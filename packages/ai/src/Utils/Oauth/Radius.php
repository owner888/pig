<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Providers\RadiusConfig;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\JsJson;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * The Radius gateway's OAuth sign-in — upstream's `auth/oauth/radius.ts`: a browser that comes back
 * to a loopback server on 127.0.0.1:1456 with PKCE, or a device code for signing in from another
 * device; the token endpoint and the device endpoint live on the gateway, and only the browser's
 * authorization endpoint is discovered (`GET <gateway>/v1/oauth`).
 *
 * Once signed in, the account's token also asks the gateway for its catalog for this account
 * (`ModelRefresh`, upstream's `radiusProvider().refreshModels()`), which replaces the shipped one.
 */
final readonly class Radius
{
    private const string CALLBACK_HOST = '127.0.0.1';

    public const int CALLBACK_PORT = 1456;

    private const string CALLBACK_PATH = '/oauth/callback';

    public const string REDIRECT_URI = 'http://' . self::CALLBACK_HOST . ':1456' . self::CALLBACK_PATH;

    private const int TOKEN_EXPIRY_SKEW_MS = 60_000;

    public const string METHOD_BROWSER = 'browser';

    public const string METHOD_DEVICE_CODE = 'device-code';

    private const string OAUTH_CLIENT_ID = 'pi-gateway';

    private const string OAUTH_SCOPE = 'gateway offline_access';

    private const string OAUTH_DEVICE_CODE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    private string $gateway;

    public function __construct(
        private HttpClient $http = new HttpClient(),
        string $gateway = RadiusConfig::DEFAULT_RADIUS_GATEWAY,
        private string $name = 'Radius',
    ) {
        $this->gateway = self::normalizeGatewayUrl($gateway);
    }

    /** Upstream's `normalizeRadiusGatewayUrl()`: a scheme when there is none, no trailing slash. */
    public static function normalizeGatewayUrl(string $value): string
    {
        $withScheme = preg_match('#^https?://#i', $value) === 1 ? $value : "https://{$value}";

        return rtrim($withScheme, '/');
    }

    /**
     * Upstream's `login(interaction)`: which way in, then that way. Null when the choice was escaped.
     *
     * @param Closure(string, ?string): void $onAuth the address to open and what to do there
     * @param Closure(string): void|null $onProgress
     * @param Closure(string, list<array{0: string, 1: string}>): ?string|null $onSelect
     */
    public function login(Closure $onAuth, ?Closure $onProgress = null, ?AbortSignal $signal = null, ?Closure $onSelect = null): ?Credentials
    {
        $method = self::METHOD_BROWSER;

        if ($onSelect !== null) {
            $method = $onSelect("Sign in to {$this->name}:", [
                [self::METHOD_BROWSER, 'Sign in with browser (recommended)'],
                [self::METHOD_DEVICE_CODE, 'Sign in with device code (when signing in from another device)'],
            ]);

            if ($method === null) {
                return null;
            }
        }

        $signal ??= (new AbortController())->signal;

        return match ($method) {
            self::METHOD_DEVICE_CODE => $this->loginWithDeviceCode($onAuth, $signal),
            self::METHOD_BROWSER => $this->loginWithBrowser($this->loadDiscovery($signal), $onAuth, $onProgress, $signal),
            default => throw new OauthError("Unknown {$this->name} sign-in method: {$method}"),
        };
    }

    /** Upstream's `refresh(credential)`. */
    public function refresh(string $refreshToken, ?AbortSignal $signal = null): Credentials
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => self::OAUTH_CLIENT_ID,
            'refresh_token' => $refreshToken,
        ], $signal ?? (new AbortController())->signal);
    }

    /** Upstream's `loadRadiusOAuthDiscovery()`: the browser's authorization endpoint. */
    private function loadDiscovery(AbortSignal $signal): string
    {
        $response = $this->send(new Request('GET', "{$this->gateway}/v1/oauth", ['Accept' => 'application/json']), $signal);
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            throw new OauthError("Could not load Radius OAuth config from {$this->gateway}: {$response->status} {$text}");
        }

        $json = JsJson::parse($text);
        $endpoint = is_array($json) ? ($json['authorizationEndpoint'] ?? null) : null;

        if (!is_string($endpoint)) {
            throw new OauthError("Invalid Radius OAuth config from {$this->gateway}");
        }

        return $endpoint;
    }

    /** Upstream's `loginWithBrowser()`. */
    private function loginWithBrowser(string $authorizationEndpoint, Closure $onAuth, ?Closure $onProgress, AbortSignal $signal): Credentials
    {
        $pkce = Pkce::create();
        $state = self::uuid();
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::OAUTH_CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::OAUTH_SCOPE,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'handoff' => 'url',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC1738);
        // `authorizeUrl.search = …`: whatever query the endpoint came with is replaced.
        $url = preg_replace('/[?#].*$/s', '', $authorizationEndpoint) . '?' . $query;

        $callback = new CallbackServer(self::CALLBACK_PORT, self::CALLBACK_PATH, 'Radius', $state, self::CALLBACK_HOST);
        $callback->listen();

        try {
            if ($onProgress !== null) {
                $onProgress('Listening for OAuth callback on ' . self::REDIRECT_URI);
            }

            $onAuth($url, 'Continue in your browser.');
            $result = $callback->await($signal);

            if ($result === null) {
                throw new OauthError($signal->aborted() ? 'Login cancelled' : 'OAuth callback did not complete.');
            }

            return $this->requestToken([
                'grant_type' => 'authorization_code',
                'client_id' => self::OAUTH_CLIENT_ID,
                'redirect_uri' => self::REDIRECT_URI,
                'code' => $result['code'],
                'code_verifier' => $pkce->verifier,
            ], $signal);
        } finally {
            $callback->close();
        }
    }

    /** Upstream's `loginWithDeviceCode()`. */
    private function loginWithDeviceCode(Closure $onAuth, AbortSignal $signal): Credentials
    {
        $response = $this->send(new Request(
            'POST',
            "{$this->gateway}/v1/oauth/device",
            ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            self::form(['client_id' => self::OAUTH_CLIENT_ID, 'scope' => self::OAUTH_SCOPE]),
        ), $signal);

        if (!$response->isSuccessful()) {
            throw self::responseError($response, 'Radius OAuth device authorization failed');
        }

        $json = JsJson::parse($response->body->all());
        $device = is_array($json) ? $json : [];

        foreach (['device_code', 'user_code', 'verification_uri', 'expires_in'] as $field) {
            if (!isset($device[$field]) || $device[$field] === '' || $device[$field] === 0) {
                throw new OauthError('Radius OAuth device authorization response is missing required fields');
            }
        }

        // `notify({type: "device_code", userCode, verificationUri, …})`.
        $onAuth((string) $device['verification_uri'], "Enter code: {$device['user_code']}");
        $interval = $device['interval'] ?? null;

        return DeviceCodeFlow::pollOAuthDeviceCodeFlow(
            function () use ($device, $signal): array {
                try {
                    return ['status' => 'complete', 'value' => $this->requestToken([
                        'grant_type' => self::OAUTH_DEVICE_CODE_GRANT_TYPE,
                        'client_id' => self::OAUTH_CLIENT_ID,
                        'device_code' => (string) $device['device_code'],
                    ], $signal)];
                } catch (RadiusOauthResponseError $error) {
                    return match ($error->oauthError) {
                        'authorization_pending' => ['status' => 'pending'],
                        'slow_down' => ['status' => 'slow_down'],
                        'expired_token' => ['status' => 'failed', 'message' => 'Device authorization expired.'],
                        'access_denied' => ['status' => 'failed', 'message' => 'Device authorization was denied.'],
                        default => throw $error,
                    };
                }
            },
            $signal,
            is_int($interval) || is_float($interval) ? $interval : null,
            is_int($device['expires_in']) || is_float($device['expires_in']) ? $device['expires_in'] : null,
        );
    }

    /**
     * Upstream's `requestOAuthToken()`.
     *
     * @param array<string, string> $body
     */
    private function requestToken(array $body, AbortSignal $signal): Credentials
    {
        $response = $this->send(new Request(
            'POST',
            "{$this->gateway}/v1/oauth/token",
            ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            self::form($body),
        ), $signal);

        if (!$response->isSuccessful()) {
            throw self::responseError($response, 'Radius OAuth token request failed');
        }

        $json = JsJson::parse($response->body->all());
        $access = is_array($json) ? ($json['access_token'] ?? null) : null;
        $refresh = is_array($json) ? ($json['refresh_token'] ?? null) : null;
        $expiresIn = is_array($json) ? ($json['expires_in'] ?? null) : null;

        if (!is_string($access) || !is_string($refresh) || !(is_int($expiresIn) || is_float($expiresIn))) {
            throw new OauthError('Radius OAuth token response is missing fields: ' . JsJson::stringify($json));
        }

        return new Credentials($refresh, $access, (int) (Timestamp::nowMs() + $expiresIn * 1000 - self::TOKEN_EXPIRY_SKEW_MS));
    }

    /** A request whose failure, when the login was cancelled, says so — upstream's `catch` round `fetch`. */
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

    /** Upstream's `readOAuthResponseError()`: `<message>: <error>: <description>`, from the JSON when there is one. */
    private static function responseError(Response $response, string $message): RadiusOauthResponseError
    {
        $text = $response->body->all();
        $oauthError = null;
        $description = null;

        if ($text !== '') {
            $data = json_decode($text, true);

            if (is_array($data)) {
                $oauthError = is_string($data['error'] ?? null) ? $data['error'] : null;
                $description = is_string($data['error_description'] ?? null) ? $data['error_description'] : null;
            } else {
                $description = $text;
            }
        }

        $detail = $oauthError !== null
            ? ($description !== null && $description !== '' ? "{$oauthError}: {$description}" : $oauthError)
            : ($description !== null && $description !== '' ? $description : (string) $response->status);

        return new RadiusOauthResponseError("{$message}: {$detail}", $response->status, $oauthError);
    }

    /** `new URLSearchParams(params).toString()`. */
    private static function form(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }

    /** `crypto.randomUUID()`. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
