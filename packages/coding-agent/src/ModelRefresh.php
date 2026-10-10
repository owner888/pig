<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
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
 *   provider; with `--no-save` (`Auth::path()` null) nothing is read or written.
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

    /** @return array{models: list<array<string, mixed>>, checkedAt?: int}|null upstream's `modelsStore.read(providerId)` */
    public static function read(Auth $auth, string $provider): ?array
    {
        $path = self::storePath($auth);

        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $entry = is_array($decoded) ? ($decoded[$provider] ?? null) : null;

        if (!is_array($entry) || !is_array($entry['models'] ?? null)) {
            return null;
        }

        return [
            'models' => array_values(array_filter($entry['models'], is_array(...))),
            ...(is_int($entry['checkedAt'] ?? null) ? ['checkedAt' => $entry['checkedAt']] : []),
        ];
    }

    /**
     * Upstream's `modelsStore.write(providerId, entry)`: this provider's entry replaced, every other
     * provider's kept, written beside and renamed over.
     *
     * @param array{models: list<array<string, mixed>>, checkedAt: int} $entry
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

        $store[$provider] = ['models' => $entry['models'], 'checkedAt' => $entry['checkedAt']];
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory} for the {$provider} catalog");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            throw new RuntimeException("Cannot write {$path}");
        }
    }
}
