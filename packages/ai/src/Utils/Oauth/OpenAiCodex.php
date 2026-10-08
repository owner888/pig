<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Providers\OpenAiCodexResponses;
use Pig\Ai\StreamOptions;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\JsJson;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Throwable;

/**
 * Signing in with a ChatGPT Plus/Pro subscription, for the `openai-codex` provider — upstream's
 * `auth/oauth/openai-codex.ts` ("OpenAI Codex (ChatGPT OAuth) flow").
 *
 * Two ways in, which `/login` asks first ("Select OpenAI Codex login method:"):
 *
 * - **Browser** (the default): PKCE against `auth.openai.com/oauth/authorize`, a loopback server on
 *   port 1455 at `/auth/callback` — "Port 1455 is shared with the Codex CLI; when it is taken, fall
 *   back to the pasted redirect URL" — and the paste box open beside it, whichever answers first.
 * - **Device code** (headless): `api/accounts/deviceauth/usercode` hands out a code to type at
 *   `auth.openai.com/codex/device`, the token endpoint is polled until it answers with an
 *   authorization code and its verifier, and that is exchanged as the browser's would be.
 *
 * The credential is upstream's `{access, refresh, expires, accountId}`, the account id read off the
 * access token's `https://api.openai.com/auth.chatgpt_account_id` claim — a token without one is
 * refused. `expires` is the token's own lifetime, with no margin taken off: upstream subtracts none
 * for this flow. The key a request carries is the access token (`toAuth()`); the provider reads the
 * account id off it again for its `chatgpt-account-id` header.
 *
 * How pig's interaction maps onto upstream's: `notify({type: "auth_url"})` and
 * `notify({type: "device_code"})` are `$onAuth`, `prompt({type: "select"})` is `$onSelect` (null — no
 * screen to ask on — takes the browser), `prompt({type: "manual_code"})` is `$onPrompt`; an escaped
 * prompt is null, which is pig's "nobody finished".
 */
final class OpenAiCodex
{
    public const string CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public const string AUTH_BASE_URL = 'https://auth.openai.com';

    public const string REDIRECT_URI = 'http://localhost:1455/auth/callback';

    /** Where the person types the device code — a page, not an endpoint, so never the test's server. */
    public const string DEVICE_VERIFICATION_URI = self::AUTH_BASE_URL . '/codex/device';

    /** The redirect a device code's authorization code was issued for. */
    public const string DEVICE_REDIRECT_URI = self::AUTH_BASE_URL . '/deviceauth/callback';

    public const int CALLBACK_PORT = 1455;

    public const string CALLBACK_PATH = '/auth/callback';

    public const string SCOPE = 'openid profile email offline_access';

    public const string METHOD_BROWSER = 'browser';

    public const string METHOD_DEVICE_CODE = 'device_code';

    /** Upstream's `DEVICE_CODE_TIMEOUT_SECONDS`. */
    private const int DEVICE_CODE_TIMEOUT_SECONDS = 15 * 60;

    /**
     * @param string $authBaseUrl upstream's `AUTH_BASE_URL` for the four endpoints — taken from outside
     *        so a test can point them at a loopback server, as `Anthropic`'s token URL is. The authorize
     *        URL's host is the same; the two device URIs are what OpenAI issued, and stay constants.
     * @param CallbackServer|null $server the loopback the browser comes back to, injectable so a test
     *        can take a free port rather than 1455
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $authBaseUrl = self::AUTH_BASE_URL,
        private readonly ?CallbackServer $server = null,
    ) {
    }

    private function authorizeUrl(): string
    {
        return "{$this->authBaseUrl}/oauth/authorize";
    }

    private function tokenUrl(): string
    {
        return "{$this->authBaseUrl}/oauth/token";
    }

    private function deviceUserCodeUrl(): string
    {
        return "{$this->authBaseUrl}/api/accounts/deviceauth/usercode";
    }

    private function deviceTokenUrl(): string
    {
        return "{$this->authBaseUrl}/api/accounts/deviceauth/token";
    }


    /** Upstream's `getCallbackHost()`: `PI_OAUTH_CALLBACK_HOST`, else `127.0.0.1`. */
    private static function getCallbackHost(): string
    {
        return StreamOptions::providerEnvValue('PI_OAUTH_CALLBACK_HOST', null) ?? '127.0.0.1';
    }

    /** Upstream's `createState()`: sixteen random bytes as hex. */
    private static function createState(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Upstream's `parseAuthorizationInput()`: a URL's `code` and `state`, else `code#state`, else a
     * `code=…&state=…` query, else the whole value as the code.
     *
     * @return array{code?: string, state?: string}
     */
    public static function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);

        if ($value === '') {
            return [];
        }

        // `new URL(value)`: anything with a scheme; "not a URL" otherwise.
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $value) === 1) {
            $parts = parse_url($value);

            if (is_array($parts)) {
                parse_str($parts['query'] ?? '', $query);

                return array_filter([
                    'code' => is_string($query['code'] ?? null) ? $query['code'] : null,
                    'state' => is_string($query['state'] ?? null) ? $query['state'] : null,
                ], static fn (?string $part): bool => $part !== null);
            }
        }

        if (str_contains($value, '#')) {
            [$code, $state] = explode('#', $value, 2) + [1 => ''];
            $state = explode('#', $state, 2)[0];

            return ['code' => $code, 'state' => $state];
        }

        if (str_contains($value, 'code=')) {
            parse_str($value, $query);

            return array_filter([
                'code' => is_string($query['code'] ?? null) ? $query['code'] : null,
                'state' => is_string($query['state'] ?? null) ? $query['state'] : null,
            ], static fn (?string $part): bool => $part !== null);
        }

        return ['code' => $value];
    }

    /** Upstream's `fetchWithLoginCancellation()`: a request that failed because the login was cancelled says so. */
    private function fetchWithLoginCancellation(Request $request, AbortSignal $signal): Response
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
     * Upstream's `readTokenResponse(response, operation)`.
     *
     * @param 'exchange'|'refresh' $operation
     * @return array{access: string, refresh: string, expires: int}
     */
    private static function readTokenResponse(Response $response, string $operation): array
    {
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            $detail = $text !== '' ? $text : $response->reason;

            throw new OauthError("OpenAI Codex token {$operation} failed ({$response->status}): {$detail}");
        }

        $json = JsJson::parse($text);
        $access = is_array($json) ? ($json['access_token'] ?? null) : null;
        $refresh = is_array($json) ? ($json['refresh_token'] ?? null) : null;
        $expiresIn = is_array($json) ? ($json['expires_in'] ?? null) : null;

        if (!self::truthy($access) || !self::truthy($refresh) || !(is_int($expiresIn) || is_float($expiresIn))) {
            throw new OauthError("OpenAI Codex token {$operation} response missing fields: " . JsJson::stringify($json));
        }

        return [
            'access' => is_string($access) ? $access : JsJson::toString($access),
            'refresh' => is_string($refresh) ? $refresh : JsJson::toString($refresh),
            'expires' => (int) (Timestamp::nowMs() + $expiresIn * 1000),
        ];
    }

    /**
     * Upstream's `exchangeAuthorizationCode()`.
     *
     * @return array{access: string, refresh: string, expires: int}
     */
    private function exchangeAuthorizationCode(string $code, string $verifier, string $redirectUri, AbortSignal $signal): array
    {
        $response = $this->fetchWithLoginCancellation(new Request(
            'POST',
            $this->tokenUrl(),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            self::form([
                'grant_type' => 'authorization_code',
                'client_id' => self::CLIENT_ID,
                'code' => $code,
                'code_verifier' => $verifier,
                'redirect_uri' => $redirectUri,
            ]),
        ), $signal);

        return self::readTokenResponse($response, 'exchange');
    }

    /**
     * Upstream's `refreshAccessToken()`: a request that did not get an answer is
     * `OpenAI Codex token refresh error: <why>`.
     *
     * @return array{access: string, refresh: string, expires: int}
     */
    private function refreshAccessToken(string $refreshToken, ?AbortSignal $signal): array
    {
        try {
            $response = $this->http->send(new Request(
                'POST',
                $this->tokenUrl(),
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                self::form([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => self::CLIENT_ID,
                ]),
            ), $signal);
        } catch (Throwable $error) {
            throw new OauthError("OpenAI Codex token refresh error: {$error->getMessage()}", 0, $error);
        }

        return self::readTokenResponse($response, 'refresh');
    }

    /**
     * Upstream's `startOpenAICodexDeviceAuth()`.
     *
     * @return array{deviceAuthId: string, userCode: string, intervalSeconds: int|float}
     */
    private function startOpenAICodexDeviceAuth(AbortSignal $signal): array
    {
        $response = $this->fetchWithLoginCancellation(new Request(
            'POST',
            $this->deviceUserCodeUrl(),
            ['Content-Type' => 'application/json'],
            JsJson::stringify(['client_id' => self::CLIENT_ID]),
        ), $signal);
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            if ($response->status === 404) {
                throw new OauthError('OpenAI Codex device code login is not enabled for this server. Use browser login or verify the server URL.');
            }

            throw new OauthError("OpenAI Codex device code request failed with status {$response->status}" . ($text !== '' ? ": {$text}" : ''));
        }

        $json = JsJson::parse($text);
        $interval = is_array($json) ? ($json['interval'] ?? null) : null;
        // `typeof json?.interval === "string" ? Number(json.interval.trim()) : json?.interval`.
        $intervalSeconds = is_string($interval) ? self::jsNumber(JsJson::trim($interval)) : $interval;
        $deviceAuthId = is_array($json) ? ($json['device_auth_id'] ?? null) : null;
        $userCode = is_array($json) ? ($json['user_code'] ?? null) : null;

        if (!self::truthy($deviceAuthId)
            || !self::truthy($userCode)
            || !(is_int($intervalSeconds) || is_float($intervalSeconds))
            || !is_finite((float) $intervalSeconds)
            || $intervalSeconds < 0) {
            throw new OauthError('Invalid OpenAI Codex device code response: ' . JsJson::stringify($json));
        }

        return [
            'deviceAuthId' => is_string($deviceAuthId) ? $deviceAuthId : JsJson::toString($deviceAuthId),
            'userCode' => is_string($userCode) ? $userCode : JsJson::toString($userCode),
            'intervalSeconds' => $intervalSeconds,
        ];
    }

    /**
     * Upstream's `pollOpenAICodexDeviceAuth()`.
     *
     * @param array{deviceAuthId: string, userCode: string, intervalSeconds: int|float} $device
     * @return array{authorizationCode: string, codeVerifier: string}
     */
    private function pollOpenAICodexDeviceAuth(array $device, AbortSignal $signal): array
    {
        return DeviceCodeFlow::pollOAuthDeviceCodeFlow(
            function () use ($device, $signal): array {
                $response = $this->fetchWithLoginCancellation(new Request(
                    'POST',
                    $this->deviceTokenUrl(),
                    ['Content-Type' => 'application/json'],
                    JsJson::stringify([
                        'device_auth_id' => $device['deviceAuthId'],
                        'user_code' => $device['userCode'],
                    ]),
                ), $signal);
                $text = $response->body->all();

                if ($response->isSuccessful()) {
                    $json = JsJson::parse($text);
                    $code = is_array($json) ? ($json['authorization_code'] ?? null) : null;
                    $verifier = is_array($json) ? ($json['code_verifier'] ?? null) : null;

                    if (!self::truthy($code) || !self::truthy($verifier)) {
                        return [
                            'status' => 'failed',
                            'message' => 'Invalid OpenAI Codex device auth token response: ' . JsJson::stringify($json),
                        ];
                    }

                    return [
                        'status' => 'complete',
                        'value' => [
                            'authorizationCode' => is_string($code) ? $code : JsJson::toString($code),
                            'codeVerifier' => is_string($verifier) ? $verifier : JsJson::toString($verifier),
                        ],
                    ];
                }

                if ($response->status === 403 || $response->status === 404) {
                    return ['status' => 'pending'];
                }

                // `try { errorCode = … } catch {}`: a body that is not JSON has no code.
                $decoded = json_decode($text, true);
                $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
                $errorCode = is_array($error) && !array_is_list($error) ? ($error['code'] ?? null) : $error;

                if ($errorCode === 'deviceauth_authorization_pending') {
                    return ['status' => 'pending'];
                }

                if ($errorCode === 'slow_down') {
                    return ['status' => 'slow_down'];
                }

                return [
                    'status' => 'failed',
                    'message' => "OpenAI Codex device auth failed with status {$response->status}" . ($text !== '' ? ": {$text}" : ''),
                ];
            },
            $signal,
            $device['intervalSeconds'],
            self::DEVICE_CODE_TIMEOUT_SECONDS,
        );
    }

    /**
     * Upstream's `createAuthorizationFlow(originator = "pi")`.
     *
     * @return array{verifier: string, state: string, url: string}
     */
    public function createAuthorizationFlow(string $originator = 'pi'): array
    {
        $pkce = Pkce::create();
        $state = self::createState();

        $url = $this->authorizeUrl() . '?' . self::form([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPE,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'id_token_add_organizations' => 'true',
            'codex_cli_simplified_flow' => 'true',
            'originator' => $originator,
        ]);

        return ['verifier' => $pkce->verifier, 'state' => $state, 'url' => $url];
    }

    /** Upstream's `getAccountId()`: the claim as a non-empty string, or null. */
    private static function getAccountId(string $accessToken): ?string
    {
        $parts = explode('.', $accessToken);
        $payload = count($parts) === 3 ? OpenAiCodexResponses::decodeJwtPayload($parts[1]) : null;
        $auth = is_array($payload) ? ($payload[OpenAiCodexResponses::JWT_CLAIM_PATH] ?? null) : null;
        $accountId = is_array($auth) ? ($auth['chatgpt_account_id'] ?? null) : null;

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }

    /**
     * Upstream's `credentialsFromToken()`.
     *
     * @param array{access: string, refresh: string, expires: int} $token
     */
    private static function credentialsFromToken(array $token): Credentials
    {
        $accountId = self::getAccountId($token['access'])
            ?? throw new OauthError('Failed to extract accountId from token');

        return new Credentials($token['refresh'], $token['access'], $token['expires'], accountId: $accountId);
    }

    /** Upstream's `loginOpenAICodexDeviceCode()`. */
    private function loginOpenAICodexDeviceCode(Closure $onAuth, AbortSignal $signal): Credentials
    {
        $device = $this->startOpenAICodexDeviceAuth($signal);
        // `notify({type: "device_code", userCode, verificationUri, …})`, which the dialog shows as the
        // address and the code to type there.
        $onAuth(self::DEVICE_VERIFICATION_URI, "Enter code: {$device['userCode']}");
        $code = $this->pollOpenAICodexDeviceAuth($device, $signal);
        $token = $this->exchangeAuthorizationCode($code['authorizationCode'], $code['codeVerifier'], self::DEVICE_REDIRECT_URI, $signal);

        return self::credentialsFromToken($token);
    }

    /**
     * Upstream's `loginOpenAICodex()`, the browser flow. Null when the paste box was escaped and no
     * callback came.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     */
    private function loginOpenAICodex(Closure $onAuth, Closure $onPrompt, AbortSignal $signal): ?Credentials
    {
        ['verifier' => $verifier, 'state' => $state, 'url' => $url] = $this->createAuthorizationFlow();

        // "Port 1455 is shared with the Codex CLI; when it is taken, fall back to the pasted redirect
        // URL." — upstream's `.catch(() => undefined)` on starting the server, kept: a port somebody
        // else holds is not this sign-in's failure, and the paste box below still finishes it.
        $callback = $this->server ?? new CallbackServer(self::CALLBACK_PORT, self::CALLBACK_PATH, 'OpenAI', $state, self::getCallbackHost());

        try {
            $callback->listen();
        } catch (OauthError) {
            $callback = null;
        }

        $onAuth($url, 'A browser window should open. Complete login to finish.');

        try {
            $result = self::waitForCallbackOrManualInput($callback, $onPrompt, $signal, [
                'message' => 'Complete login in your browser, or paste the authorization code / redirect URL here:',
                'placeholder' => self::REDIRECT_URI,
            ]);

            if ($result === null) {
                return null;
            }

            if ($result['type'] === 'callback') {
                $code = $result['value'];
            } else {
                $parsed = self::parseAuthorizationInput($result['input']);

                if (isset($parsed['state']) && $parsed['state'] !== '' && $parsed['state'] !== $state) {
                    throw new OauthError('State mismatch');
                }

                $code = $parsed['code'] ?? null;
            }

            if ($code === null || $code === '') {
                throw new OauthError('Missing authorization code');
            }

            return self::credentialsFromToken($this->exchangeAuthorizationCode($code, $verifier, self::REDIRECT_URI, $signal));
        } finally {
            $callback?->close();
        }
    }

    /**
     * Upstream's `waitForCallbackOrManualInput()` (`callback-server.ts`): the browser's callback and
     * the paste box at once, the first to answer winning and the other told to stop. Without a
     * callback server only the paste box is asked. Null is the box escaped with no callback.
     *
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @param array{message: string, placeholder: string} $prompt
     * @return array{type: 'callback', value: string}|array{type: 'manual', input: string}|null
     */
    private static function waitForCallbackOrManualInput(?CallbackServer $callback, Closure $onPrompt, AbortSignal $signal, array $prompt): ?array
    {
        $manualAbort = new AbortController();
        $callbackAbort = new AbortController();
        $answer = new Deferred();

        $settle = static function (Closure $produce) use ($answer): void {
            try {
                $value = $produce();
            } catch (Throwable $problem) {
                if (!$answer->isComplete()) {
                    $answer->error($problem);
                }

                return;
            }

            if ($value !== null && !$answer->isComplete()) {
                $answer->complete($value);
            }
        };

        $pending = $callback === null ? 1 : 2;
        $finishedOne = static function () use (&$pending, $answer): void {
            if (--$pending === 0 && !$answer->isComplete()) {
                $answer->complete(null);
            }
        };

        $outer = $signal->onAbort(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->error(new OauthError('Login cancelled'));
            }
        });

        Async::spawn(static function () use ($settle, $finishedOne, $onPrompt, $prompt, $manualAbort, $callbackAbort): void {
            $settle(static function () use ($onPrompt, $prompt, $manualAbort, $callbackAbort): ?array {
                $input = $onPrompt($prompt['message'], $prompt['placeholder'], false, $manualAbort->signal);

                if ($manualAbort->signal->aborted()) {
                    return null;
                }

                // `.then((input) => { callback?.cancel(); return input; })`, and an escaped box
                // (upstream's rejected prompt) stops the callback too — nobody finished.
                $callbackAbort->abort('manual input');

                return $input === null ? null : ['type' => 'manual', 'input' => $input];
            });
            $finishedOne();
        });

        if ($callback !== null) {
            Async::spawn(static function () use ($settle, $finishedOne, $callback, $callbackAbort): void {
                $settle(static function () use ($callback, $callbackAbort): ?array {
                    $result = $callback->await($callbackAbort->signal);

                    return $result === null ? null : ['type' => 'callback', 'value' => $result['code']];
                });
                $finishedOne();
            });
        }

        try {
            $result = $answer->future->await();
        } finally {
            $manualAbort->abort('sign-in settled');
            $callbackAbort->abort('sign-in settled');
            $signal->removeListener($outer);
        }

        return is_array($result) ? $result : null;
    }

    /** Upstream's `refreshOpenAICodexToken()`. */
    public function refresh(string $refreshToken, ?AbortSignal $signal = null): Credentials
    {
        return self::credentialsFromToken($this->refreshAccessToken($refreshToken, $signal));
    }

    /**
     * Upstream's `openaiCodexOAuth.login(interaction)`: which way in, then that way. Null means nobody
     * finished — the choice or the paste box escaped.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @param Closure(string, list<array{0: string, 1: string}>): ?string|null $onSelect
     */
    public function login(Closure $onAuth, Closure $onPrompt, ?AbortSignal $signal = null, ?Closure $onSelect = null): ?Credentials
    {
        $method = self::METHOD_BROWSER;

        if ($onSelect !== null) {
            $method = $onSelect('Select OpenAI Codex login method:', [
                [self::METHOD_BROWSER, 'Browser login (default)'],
                [self::METHOD_DEVICE_CODE, 'Device code login (headless)'],
            ]);

            if ($method === null) {
                return null;
            }
        }

        $signal ??= (new AbortController())->signal;

        if ($method === self::METHOD_DEVICE_CODE) {
            return $this->loginOpenAICodexDeviceCode($onAuth, $signal);
        }

        if ($method !== self::METHOD_BROWSER) {
            throw new OauthError("Unknown OpenAI Codex login method: {$method}");
        }

        return $this->loginOpenAICodex($onAuth, $onPrompt, $signal);
    }

    /** `new URLSearchParams(params).toString()`: form encoding, spaces as `+`. */
    private static function form(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }

    /** JavaScript's `Number(string)` for an already trimmed string: `''` is 0, anything not a number NaN. */
    private static function jsNumber(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        return is_numeric($value) ? (float) $value : NAN;
    }

    /** JavaScript truthiness of a decoded JSON value. */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }
}
