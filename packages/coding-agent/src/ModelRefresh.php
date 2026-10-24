<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Api;
use Pig\Ai\ClassifierModel;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
use Pig\Ai\ImageModel;
use Pig\Ai\Model;
use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Ai\Providers\OpenRouterUserModels;
use Pig\Ai\Providers\RadiusConfig;
use Pig\Ai\RemoteCatalog;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use RuntimeException;
use Throwable;

/**
 * Upstream's `Models.refresh()`: every registered provider with a `refreshModels` — or the ones
 * named — asked for its catalog, in two phases. First the stored catalog with the stored
 * credential and no network (`allowNetwork: false`), so a model a session names by id is there
 * before anything is fetched; then, unless `$allowNetwork` is false, the resolved credential —
 * the provider's api-key sign-in's `resolve()`, or the key `Auth::apiKey()` finds — and the
 * network. A provider with no credential stops after the first phase.
 *
 * - **A newer refresh of the same provider supersedes an older one**: the older one's signal is
 *   aborted and whatever it publishes afterwards is refused.
 * - **The store is pig's `~/.pig/agent/models-store.json`**, in pi's shape, one entry per
 *   provider; with `--no-session` (`Auth::path()` null) nothing is read or written.
 * - **The built-in providers refresh too**: each one's catalog from the catalog server
 *   (`Ai\RemoteCatalog`, upstream's `withRemoteCatalog()`) is laid over its shipped models when it
 *   is newer than them, and OpenRouter's is narrowed to the models the key may use
 *   (`withPersonalizedCatalog()`), and Radius's replaced by the gateway's catalog for the account.
 *   A provider an extension replaced is left out.
 * - **A failure is the provider's**, kept in the result's `errors` and nowhere else, so one
 *   provider that cannot be reached does not stop the next.
 *
 * Providers are refreshed one after another rather than together: each one's network calls
 * already yield to the loop, and upstream's `Promise.all` would only overlap their waiting.
 */
final class ModelRefresh
{
    /** Upstream's 15-second budget for a catalog refresh started by the UI. */
    public const int TIMEOUT_MS = 15_000;

    /** @var array<string, int> */
    private static array $generations = [];

    /** Upstream's `!process.env.PI_OFFLINE`, read the way `ToolInstaller::enabled()` reads `PIG_OFFLINE`. */
    public static function networkEnabled(): bool
    {
        return !in_array(strtolower((string) getenv('PIG_OFFLINE')), ['1', 'true', 'yes'], true);
    }

    /** @var array<string, AbortController> */
    private static array $controllers = [];

    /** @param list<string>|null $providers */
    public static function refresh(
        Auth $auth,
        ?array $providers = null,
        ?AbortSignal $signal = null,
        bool $allowNetwork = true,
        ?bool $force = null,
    ): ModelRefreshResult {
        $signal ??= AbortSignal::never();
        $errors = [];

        if ($signal->aborted()) {
            return new ModelRefreshResult(true, $errors);
        }

        // The built-in providers' catalogs from the catalog server (upstream's `withRemoteCatalog()`),
        // and OpenRouter's narrowed to the key's models (`withPersonalizedCatalog()`) — each unless
        // an extension provider of that name took its place.
        $errors = self::builtinCatalogs($auth, $providers, $allowNetwork, $force, $signal);

        if ($signal->aborted()) {
            return new ModelRefreshResult(true, $errors);
        }

        foreach (ProviderRegistry::all() as $provider) {
            if ($provider->refreshModels === null || ($providers !== null && !in_array($provider->id, $providers, true))) {
                continue;
            }

            $generation = (self::$generations[$provider->id] ?? 0) + 1;
            self::$generations[$provider->id] = $generation;
            (self::$controllers[$provider->id] ?? null)?->abort('Superseded by a newer model refresh');
            $controller = new AbortController();
            self::$controllers[$provider->id] = $controller;
            $listener = $signal->onAbort(static fn () => $controller->abort($signal->reason()));

            try {
                $stored = $auth->apiKeyCredential($provider->id);
                self::phase($auth, $provider, $stored, false, null, $generation, $controller->signal, $signal);

                if ($allowNetwork && !$controller->signal->aborted() && !$signal->aborted()) {
                    $credential = self::credential($auth, $provider, $stored);

                    if ($credential !== null) {
                        self::phase($auth, $provider, $credential, true, $force, $generation, $controller->signal, $signal);
                    }
                }
            } catch (Throwable $error) {
                if (!$controller->signal->aborted() && !$signal->aborted()) {
                    $errors[$provider->id] = $error;
                }
            } finally {
                $signal->removeListener($listener);

                if ((self::$controllers[$provider->id] ?? null) === $controller) {
                    unset(self::$controllers[$provider->id]);
                }
            }

            if ($signal->aborted()) {
                break;
            }
        }

        return new ModelRefreshResult($signal->aborted(), $errors);
    }

    /**
     * `refresh()` in a fiber of its own with upstream's 15-second budget, `$done` told the result —
     * upstream's `void session.modelRuntime.refresh(...)` after a sign-in or at startup.
     *
     * @param list<string>|null $providers
     * @param (\Closure(ModelRefreshResult): void)|null $done
     */
    public static function inBackground(Auth $auth, ?array $providers = null, bool $allowNetwork = true, ?\Closure $done = null): void
    {
        Async::spawn(static function () use ($auth, $providers, $allowNetwork, $done): void {
            $controller = new AbortController();
            $timer = Loop::get()->delay(self::TIMEOUT_MS / 1000, static fn () => $controller->abort('The operation was aborted due to timeout'));

            try {
                $result = self::refresh($auth, $providers, $controller->signal, $allowNetwork);
            } finally {
                Loop::get()->cancel($timer);
            }

            if ($done !== null) {
                $done($result);
            }
        });
    }

    /**
     * The built-in providers named (every one when null) that keep a catalog from elsewhere: all but
     * any an extension provider replaced — Radius's from its gateway, the rest from the catalog server. The stored catalogs first, all of them, then the network for each at once.
     *
     * @param list<string>|null $providers
     * @return array<string, Throwable>
     */
    private static function builtinCatalogs(Auth $auth, ?array $providers, bool $allowNetwork, ?bool $force, AbortSignal $signal): array
    {
        $baseUrl = RemoteCatalog::baseUrl();
        $ids = array_values(array_filter(
            Models::builtinProviders(),
            static fn (string $id): bool => ($providers === null || in_array($id, $providers, true))
                && ProviderRegistry::get($id) === null,
        ));
        $errors = [];
        $refreshes = [];

        $one = static fn (string $id): mixed => match ($id) {
            'openrouter' => self::openRouter($auth, $baseUrl, $allowNetwork, $signal),
            Models::RADIUS => self::radius($auth, $allowNetwork, $signal),
            default => self::remoteCatalog($auth, $id, $baseUrl, $allowNetwork, $force, $signal),
        };

        // With the network, every provider's request at once — from inside a coroutine, which a
        // caller outside one (a test, a script) is not; without it there is nothing to wait for.
        $together = $allowNetwork && \Fiber::getCurrent() !== null;

        foreach ($ids as $id) {
            $refreshes[$id] = $together ? Async::spawn(static fn () => $one($id)) : $id;
        }

        foreach ($refreshes as $id => $refresh) {
            try {
                is_string($refresh) ? $one($refresh) : $refresh->await();
            } catch (Throwable $error) {
                if (!$signal->aborted()) {
                    $errors[$id] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * Upstream's `withRemoteCatalog()` refresh: the stored catalog laid over the built-in models when
     * it is newer than them; then, with the network and a catalog server (`RemoteCatalog::baseUrl()`)
     * and once the four-hour interval is up (or
     * `$force`), the catalog server asked — revalidated with the stored `ETag` when a body backs it.
     * A 304 only moves the check time; a 404 or 501 (no catalog for this provider) is remembered as
     * one with nothing newer in it; any other failure keeps the stored catalog and its `ETag`, and is
     * this provider's error.
     */
    private static function remoteCatalog(Auth $auth, string $provider, ?string $baseUrl, bool $allowNetwork, ?bool $force, AbortSignal $signal): void
    {
        $stored = self::read($auth, $provider);
        Models::useProviderCatalog($provider, self::newer($provider, $stored));

        if (!$allowNetwork || $baseUrl === null || $signal->aborted()) {
            return;
        }

        // An entry without `lastModified` (saved before there was one, or after a failed first
        // request) is always fetched again: `newer()` ignores it, so waiting would leave nothing laid.
        if ($force !== true && isset($stored['checkedAt'], $stored['lastModified']) && self::now() - $stored['checkedAt'] < RemoteCatalog::REFRESH_INTERVAL_MS) {
            return;
        }

        $etag = $stored !== null && $stored['models'] !== [] ? ($stored['etag'] ?? null) : null;
        [$response, $body] = RemoteCatalog::request($provider, $baseUrl, $etag, signal: $signal);

        if ($signal->aborted()) {
            return;
        }

        $checkedAt = self::now();

        if ($response->status === 304 && $stored !== null) {
            self::write($auth, $provider, [...$stored, 'checkedAt' => $checkedAt]);

            return;
        }

        if ($response->status === 404 || $response->status === 501) {
            $entry = [...($stored ?? ['models' => []]), 'checkedAt' => $checkedAt, 'lastModified' => 0];
            unset($entry['etag']);
            self::write($auth, $provider, $entry);

            return;
        }

        if (!$response->isSuccessful()) {
            self::write($auth, $provider, [...($stored ?? ['models' => []]), 'checkedAt' => $checkedAt]);

            throw new RuntimeException("Model catalog request failed for {$provider}: {$response->status}");
        }

        $entry = [...RemoteCatalog::read($provider, $response, $body), 'checkedAt' => $checkedAt];
        self::write($auth, $provider, $entry);
        Models::useProviderCatalog($provider, self::newer($provider, $entry));
    }

    /**
     * Upstream's `withPersonalizedCatalog(openrouter, personalizeOpenRouterCatalog)`: the saved list
     * replaces the OpenRouter catalog, offline too ("The combined list is saved and replaces the
     * OpenRouter catalog, including offline"); then, with a key and the network, the built-in models
     * and a newer catalog from the catalog server, narrowed by `GET /models/user` to the chat models
     * the key may use — asked at the base URL the catalog's rows have, so a regional endpoint
     * `models.json` set applies its region's filtering — and saved. No refresh interval and no
     * `ETag`. A key the endpoint rejects, or an endpoint that is not there, keeps every model.
     */
    private static function openRouter(Auth $auth, ?string $baseUrl, bool $allowNetwork, AbortSignal $signal): void
    {
        $stored = self::read($auth, 'openrouter');
        $saved = $stored === null ? [] : RemoteCatalog::models('openrouter', $stored['models']);
        // An empty list — nothing saved yet, or one saved before the list held models — is the
        // built-in catalog.
        Models::useProviderCatalog('openrouter', $saved === [] ? null : $saved, replace: true);

        if (!$allowNetwork || $signal->aborted()) {
            return;
        }

        $key = $auth->apiKey('openrouter');

        if ($key === null || $key === '' || $key === \Pig\Ai\Stream::AMBIENT_AUTH_MARKER) {
            return;
        }

        $remote = [];

        if ($baseUrl !== null) {
            [$response, $body] = RemoteCatalog::request('openrouter', $baseUrl, signal: $signal);

            if ($signal->aborted()) {
                return;
            }

            if ($response->status !== 404 && $response->status !== 501) {
                if (!$response->isSuccessful()) {
                    throw new RuntimeException("Model catalog request failed for openrouter: {$response->status}");
                }

                $catalog = RemoteCatalog::read('openrouter', $response, $body);

                if (RemoteCatalog::isNewer($catalog['lastModified'], Models::builtinGeneratedAt())) {
                    $remote = RemoteCatalog::models('openrouter', $catalog['models']);
                }
            }
        }

        $catalog = self::merged(Models::builtinModelsOf('openrouter'), $remote);
        $chat = array_values(array_filter($catalog, static fn ($model): bool => $model instanceof Model));
        $endpoint = null;

        foreach (Models::all() as $model) {
            if ($model->provider === 'openrouter' && $model->api === Api::OpenAiCompletions) {
                $endpoint = $model->baseUrl;

                break;
            }
        }

        $allowed = OpenRouterUserModels::fetch($key, $endpoint ?? ($chat[0] ?? null)?->baseUrl, signal: $signal);

        if ($signal->aborted()) {
            return;
        }

        $personal = $allowed === null
            ? $catalog
            : [...OpenRouterUserModels::personalize($chat, $allowed), ...array_filter($catalog, static fn ($model): bool => !$model instanceof Model)];
        self::write($auth, 'openrouter', ['models' => array_map(ModelJson::encode(...), array_values($personal)), 'checkedAt' => self::now()]);
        Models::useProviderCatalog('openrouter', array_values($personal), replace: true);
    }

    /**
     * Upstream's `radiusProvider().refreshModels()` (43d376399): the gateway's catalog for this
     * account. "Radius org owners can disable models, so once known it replaces the shipped baseline
     * instead of overlaying it. The baseline only covers the time before any catalog exists." The
     * saved catalog first, offline too; then, signed in (or with `RADIUS_API_KEY`) and with the
     * network, `GET <gateway>/v1/config` with the account's token, saved.
     */
    private static function radius(Auth $auth, bool $allowNetwork, AbortSignal $signal): void
    {
        $stored = self::read($auth, Models::RADIUS);

        if ($stored !== null) {
            $saved = array_values(array_filter(
                RemoteCatalog::models(Models::RADIUS, $stored['models']),
                static fn ($model): bool => $model instanceof Model,
            ));
            Models::useProviderCatalog(Models::RADIUS, $saved, replace: true);
        }

        if (!$allowNetwork || $signal->aborted()) {
            return;
        }

        $key = $auth->apiKey(Models::RADIUS);

        if ($key === null || $key === '' || $key === \Pig\Ai\Stream::AMBIENT_AUTH_MARKER) {
            return;
        }

        $config = RadiusConfig::loadRadiusGatewayConfig(RadiusConfig::DEFAULT_RADIUS_GATEWAY, $key, $signal);

        if ($signal->aborted()) {
            return;
        }

        $refreshed = RadiusConfig::getRadiusModelsFromConfig(Models::RADIUS, $config);
        self::write($auth, Models::RADIUS, ['models' => array_map(ModelJson::encode(...), $refreshed), 'checkedAt' => self::now()]);
        Models::useProviderCatalog(Models::RADIUS, $refreshed, replace: true);
    }

    /**
     * Upstream's `remoteModels()`: a stored catalog's models when it is newer than the built-in data.
     *
     * @param array{models: list<array<string, mixed>>, lastModified?: int}|null $entry
     * @return list<Model|ClassifierModel|ImageModel>|null null for nothing to lay over the built-in models
     */
    private static function newer(string $provider, ?array $entry): ?array
    {
        if ($entry === null || !RemoteCatalog::isNewer($entry['lastModified'] ?? null, Models::builtinGeneratedAt())) {
            return null;
        }

        return RemoteCatalog::models($provider, $entry['models']);
    }

    /**
     * Upstream's `mergeModels()`: `$dynamic` over `$baseline` by type and id, a new one last.
     *
     * @param list<Model|ClassifierModel|ImageModel> $baseline
     * @param list<Model|ClassifierModel|ImageModel> $dynamic
     * @return list<Model|ClassifierModel|ImageModel>
     */
    private static function merged(array $baseline, array $dynamic): array
    {
        $merged = [];

        foreach ([...$baseline, ...$dynamic] as $model) {
            $merged[$model::class . "\0" . $model->id] = $model;
        }

        return array_values($merged);
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** Upstream's `resolveRefreshCredential()`, for pig's two kinds of sign-in. */
    private static function credential(Auth $auth, Provider $provider, ?ApiKeyCredential $stored): ?ApiKeyCredential
    {
        if ($provider->apiKeyAuth !== null && $auth->kind($provider->id) !== 'oauth') {
            $result = $provider->apiKeyAuth->resolve($stored);

            return $result === null ? null : new ApiKeyCredential($result->auth->apiKey, $result->env);
        }

        $key = $auth->apiKey($provider->id);

        return $key === null ? null : new ApiKeyCredential($key);
    }

    private static function phase(
        Auth $auth,
        Provider $provider,
        ?ApiKeyCredential $credential,
        bool $allowNetwork,
        ?bool $force,
        int $generation,
        AbortSignal $signal,
        AbortSignal $caller,
    ): void {
        $id = $provider->id;
        // The caller's abort reaches `$signal` a tick later (listeners are deferred), so both.
        $current = static fn (): bool => !$signal->aborted() && !$caller->aborted() && (self::$generations[$id] ?? 0) === $generation;
        \assert($provider->refreshModels !== null);

        ($provider->refreshModels)(new RefreshModelsContext(
            $credential,
            self::read($auth, $id),
            static function (?array $persist, ?\Closure $update) use ($auth, $id, $current): bool {
                if (!$current()) {
                    return false;
                }

                if ($persist !== null) {
                    self::write($auth, $id, $persist);
                }

                if ($update !== null) {
                    $update();
                }

                return true;
            },
            $allowNetwork,
            $signal,
            $allowNetwork ? $force : null,
        ));
    }

    public static function storePath(Auth $auth): ?string
    {
        return $auth->path() === null ? null : Config::home() . '/models-store.json';
    }

    /**
     * Upstream's `modelsStore.read(providerId)`: the entry's models, and when it was checked, when
     * its catalog was generated and its `ETag` where it has them.
     *
     * @return array{models: list<array<string, mixed>>, checkedAt?: int, lastModified?: int, etag?: string}|null
     */
    public static function read(Auth $auth, string $provider): ?array
    {
        $entry = self::store($auth)[$provider] ?? null;

        if (!is_array($entry) || !is_array($entry['models'] ?? null)) {
            return null;
        }

        return [
            'models' => array_values(array_filter($entry['models'], is_array(...))),
            ...(is_int($entry['checkedAt'] ?? null) ? ['checkedAt' => $entry['checkedAt']] : []),
            ...(is_int($entry['lastModified'] ?? null) ? ['lastModified' => $entry['lastModified']] : []),
            ...(is_string($entry['etag'] ?? null) ? ['etag' => $entry['etag']] : []),
        ];
    }

    /**
     * Upstream's `modelsStore.write(providerId, entry)`: this provider's entry replaced, every other
     * provider's kept, written beside and renamed over.
     *
     * @param array{models: list<array<string, mixed>>, checkedAt: int, lastModified?: int, etag?: string} $entry
     */
    public static function write(Auth $auth, string $provider, array $entry): void
    {
        $path = self::storePath($auth);

        if ($path === null) {
            return;
        }

        $store = [];

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (!is_array($decoded)) {
                throw new RuntimeException("{$path} is not valid JSON, so the {$provider} catalog was not saved");
            }

            $store = $decoded;
        }

        $store[$provider] = [
            'models' => $entry['models'],
            'checkedAt' => $entry['checkedAt'],
            ...(isset($entry['lastModified']) ? ['lastModified' => $entry['lastModified']] : []),
            ...(isset($entry['etag']) ? ['etag' => $entry['etag']] : []),
        ];
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory} for the {$provider} catalog");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            throw new RuntimeException("Cannot write {$path}");
        }

        self::$store = null;
    }

    /** @var array{0: string, 1: array<mixed>}|null the store as last read: which file it was, and what it held */
    private static ?array $store = null;

    /**
     * The whole store, decoded once and again only when the file changes — every built-in provider
     * reads its entry from it on each refresh, and it holds their catalogs.
     *
     * @return array<mixed>
     */
    private static function store(Auth $auth): array
    {
        $path = self::storePath($auth);

        if ($path === null) {
            return [];
        }

        clearstatcache(true, $path);

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        // Every writer renames a new file over it, so a new inode is a new store even within the
        // second `mtime` counts in.
        $stat = stat($path);
        $version = $stat === false ? '' : "{$path}\0{$stat['ino']}\0{$stat['mtime']}\0{$stat['size']}";

        if (self::$store !== null && self::$store[0] === $version) {
            return self::$store[1];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$store = [$version, is_array($decoded) ? $decoded : []];

        return self::$store[1];
    }
}
