<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\StreamOptions;
use Pig\Ai\Utils\Headers;
use Pig\Async\Async;
use RuntimeException;
use Throwable;

/**
 * Anthropic workload identity federation: upstream's `getAnthropicFederation()` and the
 * `@anthropic-ai/sdk` 0.129.0 machinery it hands the config to.
 *
 * Upstream builds `{organization_id, workspace_id, authentication: {type: "oidc_federation",
 * federation_rule_id, service_account_id, identity_token: {source: "file", path}}}` from the
 * `ANTHROPIC_*` variables "the Anthropic SDK documents" — only for the `anthropic` provider and only
 * when no key or auth header was resolved — and "the SDK performs the token exchange and refresh".
 * pig has no SDK, so this class is the parts of it that config reaches:
 *
 * - `oidcFederationProvider()`: the identity token read from its file on every exchange (trimmed;
 *   empty or unreadable is an error), the 16 KiB assertion limit, and the RFC 7523 jwt-bearer grant
 *   POSTed to `<baseURL>/v1/oauth/token` with the `oauth-2025-04-20,oidc-federation-2026-04-01`
 *   betas, its errors worded as the SDK's `WorkloadIdentityError`s (401 with the SDK's hint).
 * - `requireSecureTokenEndpoint()`: no assertion over plain HTTP except to a loopback host.
 * - `parseTokenResponse()` / `redactSensitive()`.
 * - `TokenCache`: the token reused while more than 120 s remain, refreshed in the background in the
 *   last 30–120 s (a failure keeps the stale token and backs off 5 s), and refreshed before use
 *   below 30 s; `invalidate()` after a 401 from a request that used it.
 *
 * Upstream's "keep one client for the current federation config and fetch" is the static cache
 * here, keyed by `JSON.stringify([model.baseUrl, federation])` (see `client()` for the `fetch`).
 *
 * Not ported, because env-only federation never reaches it: the SDK's profile files
 * (`ANTHROPIC_PROFILE`, `credentials_path` caching) — upstream's `PiAnthropic` turns the SDK's own
 * credential chain off, and its config carries no `credentials_path`.
 */
final class AnthropicFederation
{
    public const string FEDERATION_RULE_ID_ENV = 'ANTHROPIC_FEDERATION_RULE_ID';

    public const string ORGANIZATION_ID_ENV = 'ANTHROPIC_ORGANIZATION_ID';

    public const string SERVICE_ACCOUNT_ID_ENV = 'ANTHROPIC_SERVICE_ACCOUNT_ID';

    public const string IDENTITY_TOKEN_FILE_ENV = 'ANTHROPIC_IDENTITY_TOKEN_FILE';

    public const string WORKSPACE_ID_ENV = 'ANTHROPIC_WORKSPACE_ID';

    /** The SDK's `GRANT_TYPE_JWT_BEARER`. */
    private const string GRANT_TYPE_JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** The SDK's `TOKEN_ENDPOINT`. */
    private const string TOKEN_ENDPOINT = '/v1/oauth/token';

    /** The SDK's `OAUTH_API_BETA_HEADER`: required on requests authenticated by the minted token. */
    public const string OAUTH_API_BETA_HEADER = 'oauth-2025-04-20';

    /** The SDK's `FEDERATION_BETA_HEADER`. */
    private const string FEDERATION_BETA_HEADER = 'oidc-federation-2026-04-01';

    private const int ADVISORY_REFRESH_THRESHOLD_IN_SECONDS = 120;

    private const int MANDATORY_REFRESH_THRESHOLD_IN_SECONDS = 30;

    private const int ADVISORY_REFRESH_BACKOFF_IN_SECONDS = 5;

    private const int MAX_TOKEN_RESPONSE_BYTES = 1 << 20;

    private const int MAX_ERROR_BODY_CHARS = 2000;

    /** RFC 6749 §5.2's error fields, the only ones `redactSensitive()` keeps. */
    private const array SAFE_ERROR_KEYS = ['error', 'error_description', 'error_uri'];

    /** @var array{key: string, client: self}|null upstream's `federationClient` */
    private static ?array $federationClient = null;

    /** @var array{token: string, expiresAt: int|null}|null */
    private ?array $cached = null;

    private bool $nextForce = false;

    private bool $refreshing = false;

    private int $lastAdvisoryError = 0;

    /**
     * @param array{organization_id: string, workspace_id: string|null, authentication: array{type: string, federation_rule_id: string, service_account_id: string|null, identity_token: array{source: string, path: string}}} $config
     */
    private function __construct(
        private readonly array $config,
        private readonly string $baseUrl,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * Upstream's `getAnthropicFederation()`: the config, or null when this request does not federate.
     *
     * @param array<string, string|null>|null $headers
     * @param array<string, string>|null $env
     * @return array{organization_id: string, workspace_id: string|null, authentication: array{type: string, federation_rule_id: string, service_account_id: string|null, identity_token: array{source: string, path: string}}}|null
     */
    public static function config(Model $model, ?string $apiKey, ?array $headers, ?array $env): ?array
    {
        if ($model->provider !== 'anthropic' || self::hasRequestAuth($apiKey, $headers)) {
            return null;
        }

        $federationRuleId = StreamOptions::providerEnvValue(self::FEDERATION_RULE_ID_ENV, $env);
        $organizationId = StreamOptions::providerEnvValue(self::ORGANIZATION_ID_ENV, $env);
        $identityTokenFile = StreamOptions::providerEnvValue(self::IDENTITY_TOKEN_FILE_ENV, $env);

        if ($federationRuleId === null || $organizationId === null || $identityTokenFile === null) {
            return null;
        }

        return [
            'organization_id' => $organizationId,
            'workspace_id' => StreamOptions::providerEnvValue(self::WORKSPACE_ID_ENV, $env),
            'authentication' => [
                'type' => 'oidc_federation',
                'federation_rule_id' => $federationRuleId,
                'service_account_id' => StreamOptions::providerEnvValue(self::SERVICE_ACCOUNT_ID_ENV, $env),
                'identity_token' => ['source' => 'file', 'path' => $identityTokenFile],
            ],
        ];
    }

    /**
     * Upstream's `hasRequestAuth()`: a key, or an `authorization`, `x-api-key` or
     * `cf-aig-authorization` header.
     *
     * @param array<string, string|null>|null $headers
     */
    public static function hasRequestAuth(?string $apiKey, ?array $headers): bool
    {
        return ($apiKey !== null && $apiKey !== '')
            || Headers::has($headers, 'authorization')
            || Headers::has($headers, 'x-api-key')
            || Headers::has($headers, 'cf-aig-authorization');
    }

    /**
     * The federation client for this config, kept while the config stays the same — so the token it
     * minted is reused across requests, as upstream's `withOptions()` clone shares the token cache.
     *
     * Upstream also keys on the `fetch` the client was built with. pig's counterpart is the
     * provider's `HttpClient`, which `Stream` builds afresh for every request, so keying on it
     * would mint a token per request; the client keeps the one it was built with instead.
     *
     * @param array{organization_id: string, workspace_id: string|null, authentication: array<string, mixed>} $config
     */
    public static function client(string $baseUrl, array $config, HttpClient $http): self
    {
        $key = (string) json_encode([$baseUrl, $config], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (self::$federationClient === null || self::$federationClient['key'] !== $key) {
            // `resolveCredentialsFromConfig()`: `(config.base_url || options.baseURL).replace(/\/+$/, '')`;
            // upstream's config has no `base_url`, so it is the client's.
            self::$federationClient = [
                'key' => $key,
                'client' => new self($config, (string) preg_replace('#/+$#', '', $baseUrl), $http),
            ];
        }

        return self::$federationClient['client'];
    }

    /** For tests: forget the cached client, as a fresh process would. */
    public static function reset(): void
    {
        self::$federationClient = null;
    }

    /** `TokenCache.getToken()`. */
    public function getToken(): string
    {
        $force = $this->nextForce;
        $this->nextForce = false;
        $cached = $this->cached;

        if ($force || $cached === null) {
            return $this->refresh()['token'];
        }

        if ($cached['expiresAt'] === null) {
            return $cached['token'];
        }

        $remaining = $cached['expiresAt'] - time();

        if ($remaining > self::ADVISORY_REFRESH_THRESHOLD_IN_SECONDS) {
            return $cached['token'];
        }

        if ($remaining > self::MANDATORY_REFRESH_THRESHOLD_IN_SECONDS) {
            $this->backgroundRefresh();

            return $cached['token'];
        }

        return $this->refresh()['token'];
    }

    /** `TokenCache.invalidate()`: "Called after a 401". */
    public function invalidate(): void
    {
        $this->cached = null;
        $this->nextForce = true;
    }

    /** @return array{token: string, expiresAt: int|null} */
    private function refresh(): array
    {
        $this->cached = $this->exchange();

        return $this->cached;
    }

    /** `TokenCache.backgroundRefresh()`: the stale token keeps being served while this runs. */
    private function backgroundRefresh(): void
    {
        if ($this->refreshing || time() - $this->lastAdvisoryError < self::ADVISORY_REFRESH_BACKOFF_IN_SECONDS) {
            return;
        }

        $this->refreshing = true;

        Async::spawn(function (): void {
            try {
                $this->cached = $this->exchange();
            } catch (Throwable) {
                // "Advisory failure: keep serving the stale cached token".
                $this->lastAdvisoryError = time();
            } finally {
                $this->refreshing = false;
            }
        });
    }

    /**
     * `oidcFederationProvider()`'s exchange.
     *
     * @return array{token: string, expiresAt: int}
     */
    private function exchange(): array
    {
        self::requireSecureTokenEndpoint($this->baseUrl);
        $auth = $this->config['authentication'];
        $jwt = self::identityTokenFromFile($auth['identity_token']['path']);

        // "The token endpoint enforces a 16 KiB assertion limit".
        if (strlen($jwt) > 16 * 1024) {
            throw new RuntimeException(sprintf('Identity token is %d KiB, exceeds the 16 KiB assertion limit', (int) ceil(strlen($jwt) / 1024)));
        }

        $body = [
            'grant_type' => self::GRANT_TYPE_JWT_BEARER,
            'assertion' => $jwt,
            'federation_rule_id' => $auth['federation_rule_id'],
            'organization_id' => $this->config['organization_id'],
        ];

        if (($auth['service_account_id'] ?? null) !== null && $auth['service_account_id'] !== '') {
            $body['service_account_id'] = $auth['service_account_id'];
        }

        if (($this->config['workspace_id'] ?? null) !== null && $this->config['workspace_id'] !== '') {
            $body['workspace_id'] = $this->config['workspace_id'];
        }

        $url = $this->baseUrl . self::TOKEN_ENDPOINT;

        try {
            $response = $this->http->send(new Request('POST', $url, [
                'Content-Type' => 'application/json',
                'anthropic-beta' => self::OAUTH_API_BETA_HEADER . ',' . self::FEDERATION_BETA_HEADER,
                // `config.userAgent`, which the client passes as its `getUserAgent()`.
                'User-Agent' => 'Anthropic/JS ' . \Pig\Ai\Utils\SdkHeaders::ANTHROPIC_SDK_VERSION,
            ], (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        } catch (Throwable $error) {
            // `${err}` of a fetch rejection; pig's transport errors are not `TypeError`s, so the
            // message is the error's own.
            throw new RuntimeException("Failed to reach token endpoint {$url}: Error: {$error->getMessage()}", previous: $error);
        }

        $requestId = $response->header('request-id');
        $text = substr($response->body->all(), 0, self::MAX_TOKEN_RESPONSE_BYTES);

        if (!$response->isSuccessful()) {
            $redacted = self::redactSensitive($text);
            $hint = '';

            if ($response->status === 401) {
                $hintMiddle = ($this->config['workspace_id'] ?? null) !== null && $this->config['workspace_id'] !== ''
                    ? ''
                    : "If your federation rule is scoped to multiple workspaces, set the ANTHROPIC_WORKSPACE_ID environment variable, the 'workspace_id' config key, or the `workspaceId` option. ";
                $hint = " Ensure your federation rule matches your identity token. {$hintMiddle}View your authentication events in the Workload identity page of Claude Console for more details.";
            }

            throw new RuntimeException(
                "Token exchange failed with status {$response->status}"
                . ($requestId !== null && $requestId !== '' ? " (request-id {$requestId})" : '')
                . ": {$redacted}{$hint}",
            );
        }

        $data = self::parseTokenResponse($text, $response->status);
        $expiresIn = $data['expires_in'] ?? null;
        $expiresIn = is_int($expiresIn) || is_float($expiresIn) ? $expiresIn : (is_string($expiresIn) && is_numeric(trim($expiresIn)) ? (float) $expiresIn : NAN);

        if (!is_finite((float) $expiresIn)) {
            throw new RuntimeException('Token endpoint response missing required fields: ' . self::jsonStringify(self::redactObject($data)));
        }

        return ['token' => (string) $data['access_token'], 'expiresAt' => time() + (int) $expiresIn];
    }

    /** `identityTokenFromFile()`. */
    private static function identityTokenFromFile(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Identity token file path is empty');
        }

        // `fs.promises.readFile(path, "utf-8")`'s rejections, in Node's words.
        $why = match (true) {
            !file_exists($path) => "ENOENT: no such file or directory, open '{$path}'",
            is_dir($path) => 'EISDIR: illegal operation on a directory, read',
            !is_readable($path) => "EACCES: permission denied, open '{$path}'",
            default => null,
        };
        $content = $why === null ? file_get_contents($path) : false;

        if ($content === false) {
            throw new RuntimeException("Failed to read identity token file at {$path}: Error: " . ($why ?? "EIO: i/o error, read"));
        }

        $token = \Pig\Ai\Utils\JsJson::trim($content);

        if ($token === '') {
            throw new RuntimeException("Identity token file at {$path} is empty");
        }

        return $token;
    }

    /** `requireSecureTokenEndpoint()`. */
    private static function requireSecureTokenEndpoint(string $baseUrl): void
    {
        if ($baseUrl === '') {
            return;
        }

        $parts = parse_url($baseUrl);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException("Invalid token endpoint base URL \"{$baseUrl}\": TypeError: Invalid URL");
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'https') {
            return;
        }

        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1')) {
            return;
        }

        throw new RuntimeException("Refusing to send credential over non-https token endpoint \"{$baseUrl}\"");
    }

    /**
     * `parseTokenResponse()`.
     *
     * @return array<string, mixed>
     */
    private static function parseTokenResponse(string $text, int $status): array
    {
        $data = json_decode($text, true);

        if (!is_array($data) && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Token endpoint returned non-JSON response (status {$status})");
        }

        $data = is_array($data) ? $data : [];
        $token = $data['access_token'] ?? null;

        if ($token === null || $token === '' || $token === false || $token === 0) {
            throw new RuntimeException('Token endpoint response missing access_token: ' . self::jsonStringify(self::redactObject($data)));
        }

        $tokenType = $data['token_type'] ?? null;

        if (is_string($tokenType) && $tokenType !== '' && strtolower($tokenType) !== 'bearer') {
            throw new RuntimeException("Token endpoint response: unsupported token_type \"{$tokenType}\" (want Bearer)");
        }

        return $data;
    }

    /** `redactSensitive()` of a string body. */
    private static function redactSensitive(string $body): string
    {
        $parsed = json_decode($body, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $length = mb_strlen($body, 'UTF-8');

            if ($length <= self::MAX_ERROR_BODY_CHARS) {
                return $body;
            }

            return mb_substr($body, 0, self::MAX_ERROR_BODY_CHARS, 'UTF-8') . '... <' . ($length - self::MAX_ERROR_BODY_CHARS) . ' more chars>';
        }

        // `JSON.stringify(redactSensitive(parsed))`: an object keeps its RFC 6749 fields, a string
        // goes round the string arm again, anything else (an array, a number, null) is null.
        return match (true) {
            $parsed instanceof \stdClass => self::jsonStringify(self::redactObject(get_object_vars($parsed))),
            is_string($parsed) => \Pig\Ai\Utils\JsJson::stringify(self::redactSensitive($parsed)),
            default => 'null',
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function redactObject(array $body): array
    {
        return array_intersect_key($body, array_flip(self::SAFE_ERROR_KEYS));
    }

    /** @param array<string, mixed> $value */
    private static function jsonStringify(array $value): string
    {
        return $value === [] ? '{}' : \Pig\Ai\Utils\JsJson::stringify($value);
    }
}
