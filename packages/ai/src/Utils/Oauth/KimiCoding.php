<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\StreamOptions;
use Pig\Ai\Timestamp;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Kimi Code (subscription) sign-in — upstream's `auth/oauth/kimi-coding.ts`: "RFC 8628 device
 * authorization grant against https://auth.kimi.com with JSON responses. The access token
 * authenticates requests to https://api.kimi.com/coding as an `Authorization: Bearer` header" —
 * which is how the `kimi-coding` provider's Chat Completions requests send their key.
 *
 * `KIMI_CODE_OAUTH_HOST` (or `KIMI_OAUTH_HOST`) points the flow at another authorization host.
 */
final class KimiCoding
{
    public const string CLIENT_ID = '17e5f671-d194-4dfb-9706-5516cb48c098';

    public const string DEFAULT_OAUTH_HOST = 'https://auth.kimi.com';

    private const int DEVICE_CODE_TIMEOUT_SECONDS = 15 * 60;

    private const int DEFAULT_POLL_INTERVAL_SECONDS = 5;

    private const float REQUEST_TIMEOUT_SECONDS = 30.0;

    private const int REFRESH_MAX_RETRIES = 3;

    private readonly HttpClient $http;

    public function __construct(?HttpClient $http = null, private readonly ?string $oauthHost = null)
    {
        $this->http = $http ?? new HttpClient(timeout: self::REQUEST_TIMEOUT_SECONDS);
    }

    /** Upstream's `getOauthHost()`. */
    public function oauthHost(): string
    {
        $host = $this->oauthHost
            ?? StreamOptions::providerEnvValue('KIMI_CODE_OAUTH_HOST', null)
            ?? StreamOptions::providerEnvValue('KIMI_OAUTH_HOST', null)
            ?? self::DEFAULT_OAUTH_HOST;

        return rtrim($host, '/');
    }

    /**
     * Upstream's `loginKimiCoding(interaction)`: ask for a device code, show where to approve it
     * (`notify({type: "device_code"})` — the address already carries the code), and poll until
     * somebody does.
     *
     * @param Closure(string, ?string): void $onAuth
     */
    public function login(Closure $onAuth, ?AbortSignal $signal = null): Credentials
    {
        $signal ??= (new AbortController())->signal;
        $device = $this->startDeviceAuthorization($signal);
        $onAuth($device['verificationUriComplete'], "Enter code: {$device['userCode']}");
        $token = $this->pollForToken($device, $signal);

        return new Credentials($token['refresh'], $token['access'], $token['expires']);
    }

    /** Upstream's `refresh(credential, signal)`. */
    public function refresh(string $refreshToken, ?AbortSignal $signal = null): Credentials
    {
        $token = $this->refreshToken($refreshToken, $signal ?? (new AbortController())->signal);

        return new Credentials($token['refresh'], $token['access'], $token['expires']);
    }

    /**
     * Upstream's `startDeviceAuthorization()`.
     *
     * @return array{deviceCode: string, userCode: string, verificationUri: string, verificationUriComplete: string, intervalSeconds: int|float, expiresInSeconds: int|float}
     */
    private function startDeviceAuthorization(AbortSignal $signal): array
    {
        $response = $this->send($this->tokenRequest('/api/oauth/device_authorization', ['client_id' => self::CLIENT_ID]), $signal);
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            throw new OauthError("Kimi Code device authorization failed with status {$response->status}" . ($text !== '' ? ": {$text}" : ''));
        }

        $json = self::json($text);
        $deviceCode = $json['device_code'] ?? null;
        $userCode = $json['user_code'] ?? null;
        $verificationUri = $json['verification_uri'] ?? null;
        $verificationUriComplete = $json['verification_uri_complete'] ?? null;

        if (!is_string($deviceCode) || !is_string($userCode) || !self::trustedHttpUrl($verificationUri) || !self::trustedHttpUrl($verificationUriComplete)) {
            throw new OauthError('Invalid Kimi Code device authorization response: ' . self::stringify($json));
        }

        /** @var string $verificationUri */
        /** @var string $verificationUriComplete */
        $interval = $json['interval'] ?? null;
        $expiresIn = $json['expires_in'] ?? null;

        return [
            'deviceCode' => $deviceCode,
            'userCode' => $userCode,
            'verificationUri' => $verificationUri,
            'verificationUriComplete' => $verificationUriComplete,
            'intervalSeconds' => self::positiveNumber($interval) ?? self::DEFAULT_POLL_INTERVAL_SECONDS,
            'expiresInSeconds' => self::positiveNumber($expiresIn) ?? self::DEVICE_CODE_TIMEOUT_SECONDS,
        ];
    }

    /**
     * Upstream's `pollForToken()`.
     *
     * @param array{deviceCode: string, intervalSeconds: int|float, expiresInSeconds: int|float} $device
     * @return array{access: string, refresh: string, expires: int}
     */
    private function pollForToken(array $device, AbortSignal $signal): array
    {
        return DeviceCodeFlow::pollOAuthDeviceCodeFlow(
            function () use ($device, $signal): array {
                $response = $this->send($this->tokenRequest('/api/oauth/token', [
                    'client_id' => self::CLIENT_ID,
                    'device_code' => $device['deviceCode'],
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                ]), $signal);
                $text = $response->body->all();

                if ($response->status >= 500) {
                    return ['status' => 'failed', 'message' => "Kimi Code device token request failed with status {$response->status}" . ($text !== '' ? ": {$text}" : '')];
                }

                $json = self::json($text);

                if ($response->isSuccessful() && is_string($json['access_token'] ?? null)) {
                    try {
                        return ['status' => 'complete', 'value' => self::parseTokenResponse($json, 'poll')];
                    } catch (OauthError $error) {
                        return ['status' => 'failed', 'message' => $error->getMessage()];
                    }
                }

                $error = $json['error'] ?? null;
                $description = is_string($json['error_description'] ?? null) ? ": {$json['error_description']}" : '';

                return match ($error) {
                    'authorization_pending' => ['status' => 'pending'],
                    'slow_down' => ['status' => 'slow_down', 'intervalSeconds' => self::positiveNumber($json['interval'] ?? null)],
                    'expired_token' => ['status' => 'failed', 'message' => 'Kimi Code device authorization expired. Please restart login.'],
                    'access_denied' => ['status' => 'failed', 'message' => 'Kimi Code login was denied.'],
                    default => [
                        'status' => 'failed',
                        'message' => "Kimi Code device token request failed (status {$response->status})" . (is_string($error) ? ": {$error}{$description}" : ''),
                    ],
                };
            },
            $signal,
            $device['intervalSeconds'],
            $device['expiresInSeconds'],
            waitBeforeFirstPoll: true,
        );
    }

    /**
     * Upstream's `refreshToken()`: up to three retries, a second apart and doubling, on a failed
     * request, a 429 or a 5xx. "Unauthorized: the stored credential is dead" — a 401, a 403 or
     * `invalid_grant` — is said at once.
     *
     * @return array{access: string, refresh: string, expires: int}
     */
    private function refreshToken(string $refreshToken, AbortSignal $signal): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt <= self::REFRESH_MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                DeviceCodeFlow::abortableSleep(1000 * 2 ** ($attempt - 1), $signal, 'Kimi Code token refresh aborted');
            }

            if ($signal->aborted()) {
                throw new OauthError('Kimi Code token refresh aborted');
            }

            try {
                $response = $this->http->send($this->tokenRequest('/api/oauth/token', [
                    'client_id' => self::CLIENT_ID,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]), $signal);
                $text = $response->body->all();
            } catch (Throwable $error) {
                $lastError = new OauthError($error->getMessage(), 0, $error);

                continue;
            }

            $json = self::json($text);

            if ($response->isSuccessful()) {
                return self::parseTokenResponse($json, 'refresh');
            }

            if ($response->status === 401 || $response->status === 403 || ($json['error'] ?? null) === 'invalid_grant') {
                $description = is_string($json['error_description'] ?? null) ? ": {$json['error_description']}" : '';

                throw new OauthError("Kimi Code token refresh unauthorized (status {$response->status}){$description}");
            }

            if (($response->status === 429 || $response->status >= 500) && $attempt < self::REFRESH_MAX_RETRIES) {
                $lastError = new OauthError("Kimi Code token refresh failed with status {$response->status}");

                continue;
            }

            throw new OauthError("Kimi Code token refresh failed with status {$response->status}: " . self::stringify($json));
        }

        throw $lastError ?? new OauthError('Kimi Code token refresh failed');
    }

    /** @param array<string, string> $fields */
    private function tokenRequest(string $path, array $fields): Request
    {
        return new Request('POST', $this->oauthHost() . $path, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query($fields, '', '&', PHP_QUERY_RFC1738));
    }

    /** A request a cancelled login interrupted says the login was cancelled. */
    private function send(Request $request, AbortSignal $signal): Response
    {
        try {
            return $this->http->send($request, $signal);
        } catch (Throwable $error) {
            if ($signal->aborted()) {
                throw new OauthError('Login cancelled', 0, $error);
            }

            throw $error;
        }
    }

    /**
     * Upstream's `parseTokenResponse()`.
     *
     * @param array<string, mixed>|null $json
     * @return array{access: string, refresh: string, expires: int}
     */
    private static function parseTokenResponse(?array $json, string $operation): array
    {
        $access = $json['access_token'] ?? null;
        $refresh = $json['refresh_token'] ?? null;
        $expiresIn = self::positiveNumber($json['expires_in'] ?? null);

        if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '' || $expiresIn === null) {
            throw new OauthError("Kimi Code token {$operation} response missing fields: " . self::stringify($json));
        }

        return ['access' => $access, 'refresh' => $refresh, 'expires' => (int) (Timestamp::nowMs() + $expiresIn * 1000)];
    }

    /** Upstream's `trustedHttpUrl()`: "The verification URI is opened in the user's browser; only http(s) URLs are trusted." */
    private static function trustedHttpUrl(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return ($scheme === 'https' || $scheme === 'http') && parse_url($value, PHP_URL_HOST) !== null;
    }

    private static function positiveNumber(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0 ? $value : null;
    }

    /** @return array<string, mixed>|null upstream's `readJson()`: an object, or null */
    private static function json(string $text): ?array
    {
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function stringify(mixed $json): string
    {
        return $json === null ? 'null' : (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
