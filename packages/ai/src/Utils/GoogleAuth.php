<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\ProviderError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use RuntimeException;
use Throwable;

/**
 * Application Default Credentials, as `@google/genai`'s `NodeAuth` gets them from
 * `google-auth-library` 10.9.1 (`new GoogleAuth({keyFilename?, scopes: [cloud-platform]})`, then
 * `getRequestHeaders()`): what upstream's Vertex provider authenticates with when it has no API key.
 *
 * Which credentials, in the library's order:
 *
 * 1. **the key file upstream names** — `GOOGLE_APPLICATION_CREDENTIALS`, the scoped `env`'s first, which
 *    upstream passes as `keyFilename` whenever it is set;
 * 2. otherwise `google_application_credentials` (the library also reads the lower-case spelling);
 * 3. the well-known file `gcloud auth application-default login` writes:
 *    `$CLOUDSDK_CONFIG/application_default_credentials.json`, else `%APPDATA%\gcloud\…` on Windows and
 *    `$HOME/.config/gcloud/…` elsewhere;
 * 4. the GCE metadata server, when this is Google Cloud — `METADATA_SERVER_DETECTION`, the residency
 *    probe (Cloud Run / Functions variables, a Google BIOS), else a ping — cached for the process, as
 *    `gcp-metadata` caches it;
 * 5. "Could not load the default credentials…".
 *
 * And what a file holds: an `authorized_user` (what `gcloud` writes) is a refresh-token grant, a
 * `service_account` — anything else that is not one of the types below — a JWT bearer grant signed
 * RS256 with its key through OpenSSL, both against `https://oauth2.googleapis.com/token` as the library
 * sends them; `quota_project_id` becomes `x-goog-user-project`. The library's other types —
 * `external_account` (workload identity federation), `external_account_authorized_user`,
 * `impersonated_service_account`, `gdch_service_account` — and a service account outside the
 * `googleapis.com` universe (a self-signed JWT) are **refused by name**, not ported.
 *
 * Like upstream, nothing is cached across requests: the SDK builds a new `GoogleAuth` for every
 * stream, so every Vertex turn fetches its token. The library's token requests are retried as gaxios
 * retries them — up to three times for 408, 429 and 5xx, twice for a request that never got an answer,
 * 100 ms, then 500 ms, then 1.5 s apart.
 *
 * @internal
 */
final class GoogleAuth
{
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** `@google/genai`'s `REQUIRED_VERTEX_AI_SCOPE`. */
    public const string SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    /** `GoogleAuthExceptionMessages.NO_ADC_FOUND`. */
    public const string NO_ADC_FOUND = 'Could not load the default credentials. Browse to https://cloud.google.com/docs/authentication/getting-started for more information.';

    /** `google-auth-library`'s own user agent and version. */
    private const string LIBRARY_USER_AGENT = 'google-api-nodejs-client/10.9.1';

    private const string METADATA_HOST = 'http://169.254.169.254';

    private const string METADATA_SECONDARY_HOST = 'http://metadata.google.internal.';

    private const string GRANT_TYPE_JWT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** gaxios's default `statusCodesToRetry`. */
    private const array RETRY_RANGES = [[100, 199], [408, 408], [429, 429], [500, 599]];

    /** `gcp-metadata`'s `cachedIsAvailableResponse`, for the life of the process. */
    private static ?bool $isAvailable = null;

    /** `gcp-metadata`'s `gcpResidencyCache`. */
    private static ?bool $residency = null;

    /**
     * @param string|null $keyFilename upstream's `googleAuthOptions.keyFilename`
     * @param (Closure(): int)|null $now seconds, for tests
     * @param (Closure(int): void)|null $sleep milliseconds, for tests
     */
    public function __construct(
        private readonly ?string $keyFilename,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?Closure $now = null,
        private readonly string $tokenUrl = self::TOKEN_URL,
        private readonly ?Closure $sleep = null,
    ) {
    }

    /** Forget the process-wide metadata server answers. For tests. */
    public static function forgetMetadataServer(): void
    {
        self::$isAvailable = null;
        self::$residency = null;
    }

    /**
     * `googleAuth.getRequestHeaders(url)`: `authorization`, and `x-goog-user-project` when there is a
     * quota project.
     *
     * @return array<string, string>
     */
    public function requestHeaders(?AbortSignal $signal = null): array
    {
        [$credentials, $quotaProject] = $this->client($signal);
        $token = match ($credentials['type']) {
            'authorized_user' => $this->refreshUserToken($credentials, $signal),
            'compute' => $this->computeToken($signal),
            default => $this->serviceAccountToken($credentials, $signal),
        };
        $headers = ['authorization' => ($token['token_type'] ?? '') !== '' ? "{$token['token_type']} {$token['access_token']}" : "Bearer {$token['access_token']}"];

        if ($quotaProject !== null && $quotaProject !== '') {
            $headers['x-goog-user-project'] = $quotaProject;
        }

        return $headers;
    }

    /**
     * `getClient()`: the credentials and their quota project, or the reason there are none.
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function client(?AbortSignal $signal): array
    {
        if ($this->keyFilename !== null && $this->keyFilename !== '') {
            $json = self::fromJson(self::readKeyFile($this->keyFilename));

            return [$json, self::stringOf($json['quota_project_id'] ?? null)];
        }

        // `getApplicationDefaultAsync()`, whose clients get `GOOGLE_CLOUD_QUOTA_PROJECT` over theirs.
        $override = self::env('GOOGLE_CLOUD_QUOTA_PROJECT');
        $path = self::env('GOOGLE_APPLICATION_CREDENTIALS') ?? self::env('google_application_credentials');

        if ($path !== null) {
            try {
                $json = self::fromJson(self::readCredentialFile($path));
            } catch (Throwable $error) {
                throw new RuntimeException('Unable to read the credential file specified by the GOOGLE_APPLICATION_CREDENTIALS environment variable: ' . $error->getMessage(), 0, $error);
            }

            return [$json, $override ?? self::stringOf($json['quota_project_id'] ?? null)];
        }

        $wellKnown = self::wellKnownFile();

        if ($wellKnown !== null && file_exists($wellKnown)) {
            $json = self::fromJson(self::readCredentialFile($wellKnown));

            return [$json, $override ?? self::stringOf($json['quota_project_id'] ?? null)];
        }

        if ($this->isGce($signal)) {
            return [['type' => 'compute'], $override];
        }

        throw new RuntimeException(self::NO_ADC_FOUND);
    }

    /** `#determineClient()` with a `keyFilename`: `path.resolve()`, then the file read whole. */
    private static function readKeyFile(string $keyFilename): string
    {
        $path = str_starts_with($keyFilename, '/') ? $keyFilename : (getcwd() ?: '.') . '/' . $keyFilename;

        if (is_dir($path)) {
            throw new RuntimeException('EISDIR: illegal operation on a directory, read');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }

        return (string) file_get_contents($path);
    }

    /** `_getApplicationCredentialsFromFilePath()`: a file that is there, then read. */
    private static function readCredentialFile(string $path): string
    {
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            throw new RuntimeException("The file at {$path} does not exist, or it is not a file. ENOENT: no such file or directory, lstat '{$path}'");
        }

        return (string) file_get_contents($real);
    }

    /** `_tryGetApplicationCredentialsFromWellKnownFile()`'s location. */
    private static function wellKnownFile(): ?string
    {
        $configDir = self::env('CLOUDSDK_CONFIG');

        if ($configDir === null) {
            if (PHP_OS_FAMILY === 'Windows') {
                $appData = self::env('APPDATA');
                $configDir = $appData !== null ? $appData . '\\gcloud' : null;
            } else {
                $home = self::env('HOME');
                $configDir = $home !== null ? $home . '/.config/gcloud' : null;
            }
        }

        return $configDir !== null ? $configDir . DIRECTORY_SEPARATOR . 'application_default_credentials.json' : null;
    }

    /**
     * `fromJSON()`: the file's type, checked the way each client's `fromJSON()` checks it.
     *
     * @return array<string, mixed>
     */
    private static function fromJson(string $text): array
    {
        $json = JsJson::parse(JsJson::decodeUtf8($text));

        if (!is_array($json) || array_is_list($json)) {
            throw new RuntimeException('Must pass in a JSON object containing the service account auth settings.');
        }

        $type = self::stringOf($json['type'] ?? null);

        if ($type === 'authorized_user') {
            foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
                if (self::stringOf($json[$key] ?? null) === null || $json[$key] === '') {
                    throw new RuntimeException("The incoming JSON object does not contain a {$key} field");
                }
            }

            return $json;
        }

        if (in_array($type, ['external_account', 'external_account_authorized_user', 'impersonated_service_account', 'gdch_service_account'], true)) {
            throw new ProviderError("Google credentials of type \"{$type}\" are not supported by pig; use a service account key, `gcloud auth application-default login`, or a Vertex API key");
        }

        foreach (['client_email', 'private_key'] as $key) {
            if (self::stringOf($json[$key] ?? null) === null || $json[$key] === '') {
                throw new RuntimeException("The incoming JSON object does not contain a {$key} field");
            }
        }

        $universe = self::stringOf($json['universe_domain'] ?? null);

        if ($universe !== null && $universe !== '' && $universe !== 'googleapis.com') {
            throw new ProviderError("Service account credentials for the \"{$universe}\" universe (self-signed JWT) are not supported by pig");
        }

        return $json;
    }

    /**
     * The service account's token: gtoken's JWT bearer grant — `{"alg":"RS256"}` over
     * `{iss, scope, aud, exp, iat}`, an hour long — and its error, `<error>: <error_description>`.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function serviceAccountToken(array $credentials, ?AbortSignal $signal): array
    {
        $iat = $this->nowSeconds();
        $assertion = self::jwt(
            ['alg' => 'RS256'],
            ['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'exp' => $iat + 3600, 'iat' => $iat],
            (string) $credentials['private_key'],
        );
        $body = 'grant_type=' . rawurlencode(self::GRANT_TYPE_JWT) . '&assertion=' . rawurlencode($assertion);

        try {
            return $this->tokenRequest(new Request('POST', $this->tokenUrl, [
                'accept' => 'application/json',
                'x-goog-api-client' => 'gl-php/' . PHP_VERSION,
                'user-agent' => self::LIBRARY_USER_AGENT,
                'content-type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ], $body), $signal, 2, true);
        } catch (GaxiosError $error) {
            $data = $error->data;

            throw is_array($data) && is_string($data['error'] ?? null) && $data['error'] !== ''
                ? new GaxiosError("{$data['error']}: " . JsJson::toString($data['error_description'] ?? null), $error->status, $data, $error)
                : $error;
        }
    }

    /**
     * The `authorized_user` token: `refreshTokenNoCache()`, the refresh token grant. A 403 or 404 is
     * "Could not refresh access token: …" (`getRequestMetadataAsync()`), and an `invalid_grant` about
     * re-authentication is the whole answer as JSON.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function refreshUserToken(array $credentials, ?AbortSignal $signal): array
    {
        $body = http_build_query([
            'refresh_token' => $credentials['refresh_token'],
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC1738);

        try {
            return $this->tokenRequest(new Request('POST', $this->tokenUrl, [
                'x-goog-api-client' => 'gl-php/' . PHP_VERSION,
                'user-agent' => self::LIBRARY_USER_AGENT,
                'content-type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'accept' => '*/*',
            ], $body), $signal, 2, true);
        } catch (GaxiosError $error) {
            $data = $error->data;
            $message = $error->getMessage();

            if ($message === 'invalid_grant' && is_array($data) && preg_match('/ReAuth/i', JsJson::toString($data['error_description'] ?? null)) === 1) {
                $message = JsJson::stringify(json_decode(json_encode($data, JSON_THROW_ON_ERROR), false));
            }

            if ($error->status === 403 || $error->status === 404) {
                $message = "Could not refresh access token: {$message}";
            }

            throw new GaxiosError($message, $error->status, $data, $error);
        }
    }

    /**
     * The metadata server's token for the instance's default service account (`Compute`), and its
     * two worded refusals.
     *
     * @return array<string, mixed>
     */
    private function computeToken(?AbortSignal $signal): array
    {
        $url = self::metadataBase() . '/instance/service-accounts/default/token?scopes=' . rawurlencode(self::SCOPE);

        try {
            $data = $this->metadataRequest($url, $signal, 3);
        } catch (GaxiosError $error) {
            $message = "Could not refresh access token: {$error->getMessage()}";

            if ($error->status === 403) {
                $message = 'A Forbidden error was returned while attempting to retrieve an access token for the Compute Engine built-in service account. This may be because the Compute Engine instance does not have the correct permission scopes specified: ' . $message;
            } elseif ($error->status === 404) {
                $message = 'A Not Found error was returned while attempting to retrieve an accesstoken for the Compute Engine built-in service account. This may be because the Compute Engine instance does not have any permission scopes specified: ' . $message;
            }

            throw new GaxiosError($message, $error->status, $error->data, $error);
        }

        if (!is_array($data) || !is_string($data['access_token'] ?? null)) {
            throw new RuntimeException('The metadata server answered without an access token');
        }

        return $data;
    }

    /** `gcp-metadata`'s `isAvailable()`, cached for the process. */
    private function isGce(?AbortSignal $signal): bool
    {
        if (self::residency()) {
            return true;
        }

        $detection = self::env('METADATA_SERVER_DETECTION');

        if ($detection !== null) {
            $value = strtolower(trim($detection));

            return match ($value) {
                'assume-present' => true,
                'none' => false,
                'bios-only' => self::residency(),
                'ping-only' => self::$isAvailable ??= $this->ping($signal),
                default => throw new RuntimeException("Unknown `METADATA_SERVER_DETECTION` env variable. Got `{$value}`, but it should be `assume-present`, `none`, `bios-only`, `ping-only`, or unset"),
            };
        }

        return self::$isAvailable ??= $this->ping($signal);
    }

    /**
     * The ping: `instance` on the metadata server — both of its names, when no host is configured —
     * three seconds each. Any failure means "not Google Cloud".
     */
    private function ping(?AbortSignal $signal): bool
    {
        $configured = self::env('GCE_METADATA_IP') ?? self::env('GCE_METADATA_HOST');
        $bases = $configured !== null ? [self::metadataBase()] : [self::metadataBase(), self::METADATA_SECONDARY_HOST . '/computeMetadata/v1'];

        foreach ($bases as $base) {
            try {
                $this->metadataRequest($base . '/instance', $signal, 0);

                return true;
            } catch (Throwable $error) {
                // `isAvailable()`'s `catch`: a metadata server that does not answer is no metadata
                // server — a failure here is the answer, not an error. The abort is still one.
                if ($signal?->aborted() ?? false) {
                    throw $error;
                }
            }
        }

        return false;
    }

    /** `detectGCPResidency()`: a serverless runtime, or Linux with a Google BIOS. */
    private static function residency(): bool
    {
        if (self::$residency !== null) {
            return self::$residency;
        }

        if (self::env('CLOUD_RUN_JOB') !== null || self::env('FUNCTION_NAME') !== null || self::env('K_SERVICE') !== null) {
            return self::$residency = true;
        }

        if (PHP_OS_FAMILY === 'Linux' && is_file('/sys/class/dmi/id/bios_date') && is_readable('/sys/class/dmi/id/bios_vendor')) {
            return self::$residency = str_contains((string) file_get_contents('/sys/class/dmi/id/bios_vendor'), 'Google');
        }

        return self::$residency = false;
    }

    /** `getBaseUrl()`: `GCE_METADATA_IP`, `GCE_METADATA_HOST`, else `169.254.169.254`. */
    private static function metadataBase(): string
    {
        $host = self::env('GCE_METADATA_IP') ?? self::env('GCE_METADATA_HOST') ?? self::METADATA_HOST;

        if (preg_match('#^https?://#', $host) !== 1) {
            $host = "http://{$host}";
        }

        return rtrim($host, '/') . '/computeMetadata/v1';
    }

    /**
     * `metadataAccessor()`: a GET with `Metadata-Flavor: Google`, an answer that must say the same,
     * three seconds unless this is known to be Google Cloud.
     */
    private function metadataRequest(string $url, ?AbortSignal $signal, int $noResponseRetries): mixed
    {
        $http = new HttpClient(self::residency() ? null : 3.0);
        $response = $this->withRetries(
            fn (): array => self::read($http->send(new Request('GET', $url, ['metadata-flavor' => 'Google']), $signal)),
            $url,
            $signal,
            $noResponseRetries,
            false,
        );
        [, $headers, $text] = $response;
        $flavor = $headers['metadata-flavor'] ?? null;

        if ($flavor !== 'Google') {
            throw new RuntimeException("Invalid response from metadata service: incorrect Metadata-Flavor header. Expected 'Google', got " . ($flavor !== null ? "'{$flavor}'" : 'no header'));
        }

        $decoded = json_decode($text, true);

        return $decoded ?? $text;
    }

    /**
     * A token endpoint call through gaxios: the JSON answer, or a `GaxiosError` worded as
     * `extractAPIErrorFromResponse()` words it.
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(Request $request, ?AbortSignal $signal, int $noResponseRetries, bool $retryPost): array
    {
        [, , $text] = $this->withRetries(fn (): array => self::read($this->http->send($request, $signal)), $request->url, $signal, $noResponseRetries, $retryPost);
        $data = json_decode($text, true);

        if (!is_array($data) || !is_string($data['access_token'] ?? null)) {
            throw new RuntimeException('The token endpoint answered without an access token');
        }

        return $data;
    }

    /**
     * gaxios's retry: `retry` 3, `noResponseRetries` as given, `statusCodesToRetry` its default,
     * `retryDelay` 100 ms and a multiplier of 2.
     *
     * @param Closure(): array{0: int, 1: array<string, string>, 2: string} $request
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function withRetries(Closure $request, string $url, ?AbortSignal $signal, int $noResponseRetries, bool $retryPost): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $request();
            } catch (Throwable $error) {
                if ($signal?->aborted() ?? false) {
                    throw $error;
                }

                if ($attempt >= $noResponseRetries) {
                    // node-fetch's wording for a request that got no answer, which gaxios passes on.
                    throw new GaxiosError("request to {$url} failed, reason: {$error->getMessage()}", null, null, $error);
                }

                $this->sleepMs(self::retryDelay($attempt), $signal);

                continue;
            }

            [$status, , $text] = $response;

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            $error = self::gaxiosError($status, $text);

            if ($attempt >= 3 || !self::retryableStatus($status) || !$retryPost) {
                throw $error;
            }

            $this->sleepMs(self::retryDelay($attempt), $signal);
        }
    }

    /** gaxios's `getNextRetryDelay()`: 100 ms on the first retry, then `(2^n − 1) / 2` seconds. */
    private static function retryDelay(int $attempt): int
    {
        return ($attempt === 0 ? 100 : 0) + (int) ((2 ** $attempt - 1) / 2 * 1000);
    }

    private static function retryableStatus(int $status): bool
    {
        foreach (self::RETRY_RANGES as [$min, $max]) {
            if ($status >= $min && $status <= $max) {
                return true;
            }
        }

        return false;
    }

    /** `GaxiosError.extractAPIErrorFromResponse(res, "Request failed with status code <n>")`. */
    private static function gaxiosError(int $status, string $text): GaxiosError
    {
        $data = json_decode($text, true);
        $message = "Request failed with status code {$status}";

        if (!is_array($data)) {
            return new GaxiosError($text !== '' ? $text : $message, $status, $text);
        }

        $error = $data['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return new GaxiosError($error, $status, $data);
        }

        if (is_array($error)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : $message;
        }

        return new GaxiosError($message, $status, $data);
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} */
    private static function read(Response $response): array
    {
        return [$response->status, $response->headers, $response->body->all()];
    }

    /**
     * A compact JWS: `base64url(header).base64url(payload).base64url(RS256 signature)`, signed through
     * OpenSSL with the key as the file gives it (PEM).
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    public static function jwt(array $header, array $payload, string $privateKey): string
    {
        $input = self::base64url((string) json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . self::base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new RuntimeException('Cannot read the service account private key: ' . (openssl_error_string() ?: 'not a PEM private key'));
        }

        if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign with the service account private key: ' . (openssl_error_string() ?: 'openssl_sign failed'));
        }

        return $input . '.' . self::base64url($signature);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function sleepMs(int $ms, ?AbortSignal $signal): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($ms);

            return;
        }

        $signal?->throwIfAborted();
        Async::delay($ms / 1000);
        $signal?->throwIfAborted();
    }

    private function nowSeconds(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringOf(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
