<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Logger;
use RuntimeException;
use Throwable;

/**
 * pi-antigravity's dynamic model catalog — `models/discovery.ts`, with the catalog half of
 * `client/client.ts` (`fetchAvailableModelsCatalog()`, `mergeAvailableModelsResults()`,
 * `endpointCandidates()`, `antigravityHeaders()`, `parseApiKey()`) and the catalog state of
 * `models/models.ts` (`getCurrentAntigravityCatalog()`, `applyAntigravityCatalog()`).
 *
 * The deployment answers `v1internal:fetchAvailableModels` with every runtime model it serves;
 * `Grouping` folds those into public ids with routing, the result replaces the provider's models
 * and routing, and it is filed in the store with when it was asked, so the next refresh within
 * `catalogRefreshIntervalMs()` (four hours) answers from the store instead of the network.
 *
 * Where pi's core does what pig's does not have, the extension does it:
 *
 * - **the store** is pig's own `~/.pig/agent/models-store.json`, written in pi's shape — the
 *   provider's entry holds `models` and the extension's `pi-antigravity` section — so `Catalog`
 *   reads it as it reads pi's;
 * - **when** a refresh is asked for is pi's model registry's business (opening `/model`, signing
 *   in, `pi update --models`); pig has no model registry refresh, so `index.php` asks at session
 *   start and after `/login`, both subject to the interval, and `/antigravity.refresh` forces one.
 *
 * Lengths of time are milliseconds throughout, as upstream's are.
 */
final class Discovery
{
    public const int DEFAULT_CATALOG_REFRESH_INTERVAL_MS = 4 * 60 * 60 * 1000;

    public const string PERSIST_KEY = 'pi-antigravity';

    /** pi-antigravity's `ANTIGRAVITY_API`, which its stored models carry. */
    public const string API = 'antigravity-api';

    /** pi-antigravity's `ENDPOINT_FALLBACKS`. */
    public const array ENDPOINT_FALLBACKS = [
        'https://daily-cloudcode-pa.googleapis.com',
        'https://daily-cloudcode-pa.sandbox.googleapis.com',
        'https://cloudcode-pa.googleapis.com',
    ];

    /** "Metadata lookups (project/model discovery) must be fast" — `DISCOVERY_TIMEOUT_MS`, in seconds. */
    private const float DISCOVERY_TIMEOUT = 8.0;

    /** pi-antigravity's `DEFAULT_USER_AGENT`. */
    private const string DEFAULT_USER_AGENT = 'antigravity/cli/1.2.4 (aidev_client; os_type=linux; arch=amd64; cl=982146307; auth_method=consumer)';

    private const array ALLOWED_API_HOST_SUFFIXES = ['.googleapis.com', '.sandbox.googleapis.com'];

    /**
     * The catalog in use, in the extension's shape; null until one is applied, which means the
     * fallback — `currentModels = ANTIGRAVITY_MODELS`.
     *
     * @var array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}|null
     */
    private static ?array $current = null;

    /** pi-antigravity's `antigravityEnv()`: `ANTIGRAVITY_<name>`, then `NOAGY_<name>`; empty is unset. */
    public static function env(string $name): ?string
    {
        foreach (["ANTIGRAVITY_{$name}", "NOAGY_{$name}"] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** `getCatalogRefreshIntervalMs()`: `ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS`, then `…_REFRESH_INTERVAL_MS`. */
    public static function catalogRefreshIntervalMs(): int
    {
        $value = self::env('CATALOG_REFRESH_INTERVAL_MS') ?? self::env('REFRESH_INTERVAL_MS');

        // `Number.parseInt(envVal, 10)`: leading digits after optional whitespace and sign.
        if ($value !== null && preg_match('/^\s*([+-]?\d+)/', $value, $match) === 1 && (int) $match[1] >= 0) {
            return (int) $match[1];
        }

        return self::DEFAULT_CATALOG_REFRESH_INTERVAL_MS;
    }

    /**
     * `fallbackCatalog()`: the models and routing this extension ships, in the extension's shape.
     *
     * @return array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}
     */
    public static function fallbackCatalog(): array
    {
        $models = [];

        foreach (Models::fallback() as $model) {
            $models[] = [
                'id' => $model->id,
                'name' => $model->name,
                'reasoning' => $model->reasoning,
                ...($model->thinkingLevelMap !== [] ? ['thinkingLevelMap' => $model->thinkingLevelMap] : []),
                'input' => $model->input,
                'cost' => [
                    'input' => $model->pricing->input,
                    'output' => $model->pricing->output,
                    'cacheRead' => $model->pricing->cacheRead,
                    'cacheWrite' => $model->pricing->cacheWrite,
                ],
                'contextWindow' => $model->contextWindow,
                'maxTokens' => $model->maxTokens,
            ];
        }

        return ['models' => $models, 'routing' => Routing::fallbackRouting()];
    }

    /**
     * `getCurrentAntigravityCatalog()`.
     *
     * @return array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}
     */
    public static function current(): array
    {
        return self::$current ?? self::fallbackCatalog();
    }

    /**
     * `applyAntigravityCatalog()`: the catalog's models replace the provider's, its routing and
     * enums replace the router's, and it is the current catalog from here on.
     *
     * **Refused rather than half-applied**: a catalog `Catalog` would not install — a routing
     * target with no enum, nothing that routes — leaves everything as it was and says why.
     *
     * @throws RuntimeException when there is nothing installable in it
     */
    public static function apply(Catalog $catalog): void
    {
        if ($catalog->models === [] || $catalog->routing === [] || $catalog->catalog === null) {
            throw new RuntimeException('The Antigravity catalog could not be applied: ' . ($catalog->problems !== [] ? implode('; ', $catalog->problems) : 'it has no models'));
        }

        $catalog->install();
        self::$current = $catalog->catalog;
    }

    /** Back to the fallback. For tests, which must not leak a catalog into each other. */
    public static function forgetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * `hydrateAntigravityCatalog()`: "Restore provider state supplied by Pi before checking offline
     * mode or refresh TTL." The stored catalog is applied when there is one, and when it was asked
     * for is the answer — 0 for never.
     */
    public static function hydrate(Catalog $stored): int
    {
        if ($stored->models !== []) {
            self::apply($stored);
        }

        return $stored->checkedAt;
    }

    /**
     * `endpointCandidates()`: `ANTIGRAVITY_BASE_URL` alone when it is set, the three hosts otherwise.
     *
     * @return list<string>
     */
    public static function endpointCandidates(): array
    {
        $explicit = self::env('BASE_URL');
        $explicit = $explicit === null ? null : trim($explicit);

        return $explicit !== null && $explicit !== '' ? [self::assertSafeApiBaseUrl($explicit)] : self::ENDPOINT_FALLBACKS;
    }

    /** `assertSafeApiBaseUrl()`: "Prevent token exfiltration via poisoned BASE_URL (SSRF / credential leak)." */
    public static function assertSafeApiBaseUrl(string $raw): string
    {
        $url = parse_url($raw);

        if ($url === false || !isset($url['scheme'], $url['host'])) {
            throw new RuntimeException("Invalid ANTIGRAVITY_BASE_URL: {$raw}");
        }

        if (strtolower($url['scheme']) !== 'https') {
            throw new RuntimeException('ANTIGRAVITY_BASE_URL must use https (got ' . strtolower($url['scheme']) . ':)');
        }

        if (isset($url['user']) || isset($url['pass'])) {
            throw new RuntimeException('ANTIGRAVITY_BASE_URL must not include credentials');
        }

        $host = strtolower($url['host']);
        $allowed = $host === 'googleapis.com';

        foreach (self::ALLOWED_API_HOST_SUFFIXES as $suffix) {
            $allowed = $allowed || str_ends_with($host, $suffix);
        }

        if (!$allowed) {
            throw new RuntimeException("ANTIGRAVITY_BASE_URL host \"{$host}\" is not allowed. Use a *.googleapis.com endpoint.");
        }

        $path = rtrim($url['path'] ?? '', '/');
        $origin = 'https://' . $host . (isset($url['port']) && $url['port'] !== 443 ? ':' . $url['port'] : '');

        return $origin . ($path === '/' ? '' : $path);
    }

    /**
     * `antigravityHeaders()`.
     *
     * @return array<string, string>
     */
    public static function headers(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'User-Agent' => self::env('USER_AGENT') ?? self::DEFAULT_USER_AGENT,
        ];
    }

    /**
     * `parseApiKey()`: the token and the project out of the one string the key carries.
     *
     * @return array{token: string, projectId: string}
     */
    public static function parseApiKey(?string $apiKeyRaw): array
    {
        if ($apiKeyRaw === null || $apiKeyRaw === '') {
            throw new RuntimeException('No Antigravity OAuth credentials. Run /login antigravity.');
        }

        try {
            $parsed = json_decode($apiKeyRaw, true, flags: JSON_THROW_ON_ERROR);
            $token = is_array($parsed) ? ($parsed['token'] ?? null) : null;
            $projectId = is_array($parsed) ? ($parsed['projectId'] ?? null) : null;

            if (!is_string($token) || $token === '' || !is_string($projectId) || $projectId === '') {
                throw new RuntimeException('missing token or projectId');
            }

            return ['token' => $token, 'projectId' => $projectId];
        } catch (Throwable $error) {
            throw new RuntimeException(
                'Invalid Antigravity credentials. Run /login antigravity. (' . AntigravityApi::redactSecrets($error->getMessage()) . ')',
                0,
                $error,
            );
        }
    }

    /**
     * `fetchAvailableModelsCatalog()`: "Merge fetchAvailableModels across endpoint candidates so
     * daily/sandbox-only models appear alongside production catalog entries."
     *
     * One endpoint after another where upstream asks them all at once (`Promise.all`): pig has no
     * combinator for that (see CLAUDE.md on `Future`), and the merge is in endpoint order either way.
     *
     * @param list<string>|null $endpoints the hosts to ask; `endpointCandidates()` when null
     * @return array{endpoint: string, status: int, data: array{models: array<string, mixed>, defaultAgentModelId?: string, defaultAgentModel?: string}}
     */
    public static function fetchAvailableModelsCatalog(string $token, string $projectId, ?AbortSignal $signal = null, ?HttpClient $http = null, ?array $endpoints = null): array
    {
        $http ??= new HttpClient(self::DISCOVERY_TIMEOUT);
        $results = [];
        $lastError = null;

        foreach ($endpoints ?? self::endpointCandidates() as $endpoint) {
            $results[] = self::fetchAvailableModelsFromEndpoint($http, $endpoint, $token, $projectId, $signal, $lastError);
        }

        return self::mergeAvailableModelsResults($results, $lastError);
    }

    /**
     * `mergeAvailableModelsResults()`.
     *
     * @param list<array{endpoint: string, status: int, data: mixed}|null> $results
     * @param Throwable|null $lastError what the last endpoint that failed threw, kept as the cause
     * @return array{endpoint: string, status: int, data: array{models: array<string, mixed>, defaultAgentModelId?: string, defaultAgentModel?: string}}
     */
    public static function mergeAvailableModelsResults(array $results, ?Throwable $lastError = null): array
    {
        $mergedModels = [];
        $defaultAgentModelId = null;
        $defaultAgentModel = null;
        $lastEndpoint = '';
        $lastStatus = 0;

        foreach ($results as $result) {
            if ($result === null) {
                continue;
            }

            $lastEndpoint = $result['endpoint'];
            $lastStatus = $result['status'];
            $data = $result['data'];

            if (is_array($data) && is_array($data['models'] ?? null) && !array_is_list($data['models'])) {
                $mergedModels = array_replace($mergedModels, $data['models']);
            }

            if (is_array($data) && is_string($data['defaultAgentModelId'] ?? null)) {
                $defaultAgentModelId = $data['defaultAgentModelId'];
            }

            if (is_array($data) && is_string($data['defaultAgentModel'] ?? null)) {
                $defaultAgentModel = $data['defaultAgentModel'];
            }
        }

        if ($lastEndpoint === '') {
            throw new RuntimeException('/v1internal:fetchAvailableModels failed: no endpoint available', 0, $lastError);
        }

        return [
            'endpoint' => $lastEndpoint,
            'status' => $lastStatus,
            'data' => [
                'models' => $mergedModels,
                ...($defaultAgentModelId !== null ? ['defaultAgentModelId' => $defaultAgentModelId] : []),
                ...($defaultAgentModel !== null ? ['defaultAgentModel' => $defaultAgentModel] : []),
            ],
        ];
    }

    /**
     * `discoverAntigravityModels()`: the grouped catalog, and the enums the deployment named for
     * its runtime ids (`registerDiscoveredModelEnums()`). An empty answer is an empty catalog.
     *
     * @param list<string>|null $endpoints
     * @return array{catalog: array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}, enums: array<string, string>}
     */
    public static function discover(string $apiKey, ?AbortSignal $signal = null, ?HttpClient $http = null, ?array $endpoints = null): array
    {
        $creds = self::parseApiKey($apiKey);
        $available = self::fetchAvailableModelsCatalog($creds['token'], $creds['projectId'], $signal, $http, $endpoints);
        $models = $available['data']['models'];

        if ($models === []) {
            return ['catalog' => ['models' => [], 'routing' => []], 'enums' => []];
        }

        $enums = [];

        foreach ($models as $wireId => $info) {
            if (is_array($info) && is_string($info['model'] ?? null) && $info['model'] !== '') {
                $enums[(string) $wireId] = $info['model'];
            }
        }

        return ['catalog' => Grouping::buildAntigravityCatalog($models, self::fallbackCatalog()), 'enums' => $enums];
    }

    /**
     * `refreshAntigravityModels(context)`: the stored catalog first, then the network when it is
     * allowed, there is a key, and the last answer is older than the interval (or `$force`).
     *
     * "Keep last-known-good models; a failed refresh must not wipe the catalog. Forced/manual
     * refreshes must still report the failure to their caller." A refresh nobody forced that fails
     * is written to pig's log rather than thrown.
     *
     * @param Closure(array<string, mixed>): void $publish files the provider's store entry
     * @param list<string>|null $endpoints
     * @return list<array<string, mixed>> the models now current
     */
    public static function refresh(
        Catalog $stored,
        bool $allowNetwork,
        ?string $apiKey,
        bool $force,
        ?AbortSignal $signal,
        Closure $publish,
        ?HttpClient $http = null,
        ?array $endpoints = null,
    ): array {
        $checkedAt = self::hydrate($stored);
        $current = self::current();

        if (!$allowNetwork) {
            return $current['models'];
        }

        if ($apiKey === null || $apiKey === '' || $signal?->aborted() === true) {
            return $current['models'];
        }

        $now = Timestamp::nowMs();

        if (!$force && $checkedAt > 0 && $now >= $checkedAt && $now - $checkedAt < self::catalogRefreshIntervalMs()) {
            return $current['models'];
        }

        try {
            $discovered = self::discover($apiKey, $signal, $http, $endpoints);

            if ($signal?->aborted() === true) {
                return $current['models'];
            }

            $next = Grouping::resolvedCatalog($discovered['catalog'], $current);

            if ($next['models'] !== [] && $discovered['catalog']['models'] !== []) {
                $enums = [...Routing::dynamicEnums(), ...$discovered['enums']];
                $refreshedAt = Timestamp::nowMs();
                self::apply(Catalog::fromCatalog('the Antigravity catalog', $next, $enums, $refreshedAt));
                $publish([
                    'models' => self::storedModels($next['models']),
                    self::PERSIST_KEY => [
                        'catalog' => $next,
                        'checkedAt' => $refreshedAt,
                        'modelEnums' => $enums,
                    ],
                ]);

                return $next['models'];
            }
        } catch (Throwable $error) {
            if ($force) {
                throw $error;
            }

            Logger::warning('Antigravity model catalog refresh failed; keeping the last catalog: ' . AntigravityApi::redactSecrets($error->getMessage()));
        }

        return self::current()['models'];
    }

    /**
     * `toStoredModels()`: each model with the provider, the protocol and the endpoint filled in.
     *
     * @param list<array<string, mixed>> $models
     * @return list<array<string, mixed>>
     */
    public static function storedModels(array $models): array
    {
        return array_map(static fn (array $model): array => [
            ...$model,
            'api' => self::API,
            'provider' => Models::PROVIDER,
            'baseUrl' => AntigravityApi::ENDPOINT,
        ], $models);
    }

    /**
     * File the provider's entry in the store at $path, replacing what was filed under it before and
     * keeping every other provider's — `ModelsStore.write(providerId, entry)`.
     *
     * @param array<string, mixed> $entry
     */
    public static function persist(string $path, array $entry): void
    {
        $store = [];

        if (is_file($path)) {
            $raw = is_readable($path) ? file_get_contents($path) : false;

            if ($raw === false) {
                throw new RuntimeException("{$path} could not be read, so the Antigravity catalog was not saved");
            }

            try {
                $decoded = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new RuntimeException("{$path} is not valid JSON, so the Antigravity catalog was not saved: {$error->getMessage()}", 0, $error);
            }

            if (!is_array($decoded)) {
                throw new RuntimeException("{$path} is not an object, so the Antigravity catalog was not saved");
            }

            $store = $decoded;
        }

        $store[Models::PROVIDER] = $entry;
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory} for the Antigravity catalog");
        }

        // Written beside and renamed over, so a reader never sees half a file.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            throw new RuntimeException("Cannot write {$path}");
        }
    }

    /**
     * `fetchAvailableModelsFromEndpoint()`: the endpoint's answer, or null when it failed — the
     * failure kept in $lastError, which is what upstream's `setLastError()` does with it.
     *
     * @return array{endpoint: string, status: int, data: mixed}|null
     */
    private static function fetchAvailableModelsFromEndpoint(HttpClient $http, string $endpoint, string $token, string $projectId, ?AbortSignal $signal, ?Throwable &$lastError): ?array
    {
        try {
            $response = $http->send(new Request(
                'POST',
                "{$endpoint}/v1internal:fetchAvailableModels",
                self::headers($token),
                (string) json_encode(['project' => $projectId], JSON_UNESCAPED_SLASHES),
            ), $signal);
            $text = $response->body->all();
            $data = json_decode($text, true);

            if ($data === null && trim($text) !== 'null') {
                $data = ['raw' => $text];
            }

            if (!$response->isSuccessful()) {
                $message = is_array($data) && is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null)
                    ? $data['error']['message']
                    : $text;
                $lastError = new RuntimeException(AntigravityApi::redactSecrets($message));

                return null;
            }

            return ['endpoint' => $endpoint, 'status' => $response->status, 'data' => $data];
        } catch (Throwable $error) {
            $lastError = $error;

            return null;
        }
    }
}
