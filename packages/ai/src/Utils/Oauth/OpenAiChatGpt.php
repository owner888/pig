<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\StreamOptions;
use Pig\Ai\Timestamp;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Sign in with ChatGPT, for the `openai` provider — upstream's `auth/oauth/openai-chatgpt.ts`
 * ("OpenAI Responses API token sharing through Sign in with ChatGPT").
 *
 * OpenAI's open-source flow: a public client with no secret. The first authorization goes out with
 * `client_id=dynamic_agent_client`, which registers a client of this installation's own — named
 * by `agent_name_hint`, the installation by `ext_agent_host_id` (`urn:uuid:<device id>`) — and the
 * callback brings back the client id OpenAI issued, which the code exchange and every renewal
 * send. The access token goes straight to `api.openai.com/v1` as the key, and requests draw on the
 * ChatGPT plan's usage; ChatGPT's settings list the app by its name and can cap or disconnect it.
 *
 * Unlike the Codex flow (`OpenAiCodex`), which borrows the Codex CLI's client: this one is pig's.
 * **The name hint is `pig`, not upstream's `Pi`**, as the developer asked, so ChatGPT shows the app
 * that is actually signed in.
 *
 * The loopback on port 1455 is the only way back besides the paste box: a port somebody else holds
 * is an error naming who probably does, not a quiet fall back to pasting — upstream's choice, since
 * the browser's callback would otherwise reach that other listener and fail there as a state
 * mismatch. The credential's `expires` is three minutes short of the real one.
 *
 * pig's interaction, as in `OpenAiCodex`: `notify({type: "auth_url"})` is `$onAuth`,
 * `prompt({type: "manual_code"})` is `$onPrompt`, `notify({type: "progress"})` is `$onProgress`;
 * an escaped paste box with no callback is null, pig's "nobody finished".
 */
final class OpenAiChatGpt
{
    /** "every login registers a new client with this ID; OpenAI returns the issued client ID in the callback" */
    public const string DYNAMIC_CLIENT_ID = 'dynamic_agent_client';

    public const string AGENT_NAME_HINT = 'pig';

    public const string AUTH_BASE_URL = 'https://auth.openai.com';

    public const string RESOURCE = 'https://api.openai.com/v1';

    public const int CALLBACK_PORT = 1455;

    public const string CALLBACK_PATH = '/auth/callback';

    /** `127.0.0.1`, not the Codex flow's `localhost`: what the registration was made for. */
    public const string REDIRECT_URI = 'http://127.0.0.1:1455/auth/callback';

    public const string DIRECT_TOKEN_SCOPE = 'chatgpt.tokens.use.direct';

    public const string SCOPE = 'openid profile email offline_access resource.invoke ' . self::DIRECT_TOKEN_SCOPE;

    /** Upstream's wording for a callback without the client it registered — the server's page says it too. */
    public const string NO_CLIENT_ID = 'OpenAI OAuth registration callback did not contain an issued client ID';

    /** "Refresh this long before the real expiry so a request never starts with a token about to expire." */
    private const int EXPIRY_MARGIN_MS = 3 * 60 * 1000;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param string $authBaseUrl the host of the authorize and token endpoints, taken from outside so
     *        a test can point them at a loopback server, as `OpenAiCodex` takes it
     * @param CallbackServer|null $server the loopback the browser comes back to, injectable so a test
     *        can take a free port rather than 1455
     */
    public function __construct(
        private readonly HttpTransport $http = new HttpClient(),
        private readonly string $authBaseUrl = self::AUTH_BASE_URL,
        private readonly ?CallbackServer $server = null,
    ) {
    }

    private function authorizeUrl(): string
    {
        return "{$this->authBaseUrl}/api/accounts/authorize";
    }

    private function tokenUrl(): string
    {
        return "{$this->authBaseUrl}/api/accounts/oauth/token";
    }

    /** `PIG_OAUTH_CALLBACK_HOST` (upstream's `PI_OAUTH_CALLBACK_HOST`), else `127.0.0.1`. */
    private static function callbackHost(): string
    {
        return StreamOptions::providerEnvValue('PIG_OAUTH_CALLBACK_HOST', null) ?? '127.0.0.1';
    }

    /** Upstream's `randomValue()`: 32 random bytes, base64url. */
    private static function randomValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Upstream's `agentHostId()`: "OpenAI identifies each installation ("agent host") by a stable URI such as `urn:uuid:<uuid>`." */
    public static function agentHostId(?string $deviceId): string
    {
        if ($deviceId === null || preg_match(self::UUID_PATTERN, $deviceId) !== 1) {
            throw new OauthError('Sign in with ChatGPT requires a device ID (UUID) for this installation');
        }

        return 'urn:uuid:' . strtolower($deviceId);
    }

    /**
     * Upstream's `authorizationResultFromCallback()`, for a pasted callback URL's query.
     *
     * @param array<string, mixed> $query
     * @return array{code: string, clientId: string}
     */
    private static function authorizationResult(array $query, string $expectedState): array
    {
        $code = is_string($query['code'] ?? null) && $query['code'] !== '' ? $query['code'] : throw new OauthError('Missing authorization code');
        $state = is_string($query['state'] ?? null) && $query['state'] !== '' ? $query['state'] : throw new OauthError('Missing OAuth state');

        if ($state !== $expectedState) {
            throw new OauthError('OAuth state mismatch');
        }

        $clientId = is_string($query['client_id'] ?? null) ? trim($query['client_id']) : '';

        return ['code' => $code, 'clientId' => $clientId !== '' ? $clientId : throw new OauthError(self::NO_CLIENT_ID)];
    }

    /**
     * Upstream's `authorizationResultFromManualInput()`: the whole callback URL, nothing less — the
     * client id is in it, so a bare code would not do.
     *
     * @return array{code: string, clientId: string}
     */
    public static function authorizationResultFromManualInput(string $input, string $expectedState): array
    {
        $parts = parse_url(trim($input));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new OauthError('Paste the full callback URL from the browser');
        }

        $expected = parse_url(self::REDIRECT_URI);
        \assert(is_array($expected));
        $origin = static fn (array $url): string => strtolower($url['scheme'] . '://' . $url['host']) . (isset($url['port']) ? ':' . $url['port'] : '');

        if ($origin($parts) !== $origin($expected) || ($parts['path'] ?? '/') !== $expected['path']) {
            throw new OauthError('The pasted callback URL must start with ' . self::REDIRECT_URI);
        }

        parse_str($parts['query'] ?? '', $query);

        if (is_string($query['error'] ?? null) && $query['error'] !== '') {
            throw new OauthError("ChatGPT authorization failed: {$query['error']}");
        }

        return self::authorizationResult($query, $expectedState);
    }

    /**
     * Upstream's `requestToken()`: the form posted to the token endpoint, the JSON object it answers.
     *
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function requestToken(array $form, ?AbortSignal $signal): array
    {
        try {
            $response = $this->http->send(new Request(
                'POST',
                $this->tokenUrl(),
                ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query($form, '', '&', PHP_QUERY_RFC1738),
            ), $signal);
        } catch (Throwable $error) {
            if ($signal?->aborted() === true) {
                throw new OauthError('Login cancelled', 0, $error);
            }

            throw $error;
        }

        return self::tokenObject($response);
    }

    /** @return array<string, mixed> */
    private static function tokenObject(Response $response): array
    {
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            throw new OauthError("OpenAI OAuth token request failed ({$response->status}): " . ($text !== '' ? $text : $response->reason));
        }

        $data = json_decode($text, true);

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new OauthError('OpenAI OAuth token response must be an object');
        }

        return $data;
    }

    /** Upstream's `requireTokenString()`. */
    private static function tokenString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new OauthError("OpenAI OAuth token response has invalid {$field}");
        }

        return $value;
    }

    /**
     * Upstream's `credentialFromTokenResponse()`: the issued client and the granted scopes kept with
     * the tokens, and a grant without direct token use refused — a token without it cannot be sent.
     *
     * @param array<string, mixed> $token
     */
    private static function credentials(array $token, string $clientId): OauthCredentials
    {
        $access = self::tokenString($token['access_token'] ?? null, 'access_token');
        $refresh = self::tokenString($token['refresh_token'] ?? null, 'refresh_token');
        $scope = self::tokenString($token['scope'] ?? null, 'scope');
        $expiresIn = $token['expires_in'] ?? null;

        if (!(is_int($expiresIn) || is_float($expiresIn)) || !is_finite((float) $expiresIn) || $expiresIn <= 0) {
            throw new OauthError('OpenAI OAuth token response has invalid expires_in');
        }

        $scopes = array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $part): bool => $part !== ''));

        if (!in_array(self::DIRECT_TOKEN_SCOPE, $scopes, true)) {
            throw new OauthError('OpenAI OAuth grant did not include ' . self::DIRECT_TOKEN_SCOPE);
        }

        return new OauthCredentials(
            $refresh,
            $access,
            (int) (Timestamp::nowMs() + $expiresIn * 1000 - self::EXPIRY_MARGIN_MS),
            clientId: $clientId,
            scopes: $scopes,
        );
    }

    /** Upstream's `exchangeAuthorizationCode()`. */
    private function exchange(string $code, string $verifier, string $clientId, AbortSignal $signal): OauthCredentials
    {
        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => self::REDIRECT_URI,
            'resource' => self::RESOURCE,
        ], $signal);

        // "Pi does not use the ID token to identify the user or read profile data. Keep the presence
        // check as part of the token-response contract." Neither does pig.
        if (!is_string($token['id_token'] ?? null) || trim($token['id_token']) === '') {
            throw new OauthError('OpenAI OAuth token response did not contain an ID token');
        }

        return self::credentials($token, $clientId);
    }

    /** Upstream's `refreshAccessToken()`: with the client the sign-in was issued, and nothing else will do. */
    public function refresh(OauthCredentials $credentials, ?AbortSignal $signal = null): OauthCredentials
    {
        $clientId = $credentials->clientId;

        if ($clientId === null || trim($clientId) === '') {
            throw new OauthError('Stored OpenAI OAuth credential does not contain an issued client ID; reconnect ChatGPT');
        }

        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $credentials->refresh,
            'resource' => self::RESOURCE,
        ], $signal);

        return self::credentials($token, $clientId);
    }

    /**
     * Upstream's `loginOpenAIChatGPT()`. The device id is checked before anything else happens, so a
     * run with no way to name its installation never sends anyone to a browser.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @param Closure(string): void|null $onProgress
     * @param string|null $deviceId upstream's `LoginOptions.getDeviceId()`: this installation's UUID
     * @param string|null $agentName upstream's `LoginOptions.agentName`; pig's own name when null
     */
    public function login(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?string $deviceId = null,
        ?string $agentName = null,
    ): ?OauthCredentials {
        $hostId = self::agentHostId($deviceId);
        $signal ??= (new AbortController())->signal;
        $pkce = Pkce::create();
        $state = self::randomValue();
        $nonce = self::randomValue();
        $callback = $this->server ?? new CallbackServer(self::CALLBACK_PORT, self::CALLBACK_PATH, 'ChatGPT', $state, self::callbackHost(), issuesClientId: true);

        try {
            $callback->listen();
        } catch (OauthError $problem) {
            // "Without this server, the browser's callback would reach whatever else holds the port
            // (another pending login or the Codex CLI), which rejects it as a state mismatch. Fail
            // with a clear error instead."
            if (!str_contains($problem->getMessage(), 'in use')) {
                throw $problem;
            }

            throw new OauthError(
                'Port ' . self::CALLBACK_PORT . ' is in use, probably by an unfinished login in another pig session or by the Codex CLI. Cancel that login and try again.',
                0,
                $problem,
            );
        }

        $url = $this->authorizeUrl() . '?' . http_build_query([
            'client_id' => self::DYNAMIC_CLIENT_ID,
            'agent_name_hint' => $agentName ?? self::AGENT_NAME_HINT,
            'ext_agent_host_id' => $hostId,
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT_URI,
            'resource' => self::RESOURCE,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC1738);

        $onAuth($url, 'Complete sign-in in your browser. If the callback does not complete, paste the final redirect URL here.');

        try {
            $result = CallbackServer::waitForCallbackOrManualInput($callback, $onPrompt, $signal, [
                'message' => 'Complete login in your browser, or paste the final redirect URL here:',
                'placeholder' => self::REDIRECT_URI,
            ]);

            if ($result === null) {
                return null;
            }

            $authorization = $result['type'] === 'callback'
                ? ['code' => $result['value']['code'], 'clientId' => $result['value']['clientId'] ?? throw new OauthError(self::NO_CLIENT_ID)]
                : self::authorizationResultFromManualInput($result['input'], $state);

            if ($onProgress !== null) {
                $onProgress('Exchanging authorization code for tokens...');
            }

            return $this->exchange($authorization['code'], $pkce->verifier, $authorization['clientId'], $signal);
        } catch (Throwable $error) {
            if ($signal->aborted()) {
                throw new OauthError('Login cancelled', 0, $error);
            }

            throw $error;
        } finally {
            $callback->close();
        }
    }
}
