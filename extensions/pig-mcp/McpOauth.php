<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Mcp\Oauth\Discovery;
use Pig\Mcp\Oauth\Flow;
use Pig\Mcp\Oauth\McpOauthAuthorizationRequiredError;
use Pig\Mcp\Oauth\McpOauthProvider;
use Pig\Mcp\Oauth\Metadata;
use Pig\Mcp\Oauth\OauthCallbackServer;
use Pig\Mcp\Oauth\OauthStateStore;
use Pig\Mcp\Transports\AuthProvider;
use RuntimeException;

/**
 * OAuth sign-in for remote MCP servers — upstream's `extensions/mcp/oauth.js`.
 *
 * Connections never start a browser flow on their own. They send the stored access token and,
 * after a 401, try the stored refresh token; when that is not possible they fail with
 * `McpOauthAuthorizationRequiredError`, the connection goes `needs-auth`, and the person signs
 * in through `/mcp login` or `pig mcp login`, which runs the authorization code flow (PKCE,
 * dynamic client registration) against a loopback callback.
 *
 * Credentials live in `<agent-dir>/mcp-auth.json`, keyed by server URL, `0600`.
 *
 * Not ported: upstream's cross-process refresh lock (`proper-lockfile`). Two pigs refreshing one
 * rotating token at the same instant lose the grant and one of them has to sign in again, which
 * is the same cost a lost lock has there; the lock is a dependency pig does not take.
 */
final class McpOauth
{
    public const string CALLBACK_HOST = '127.0.0.1';

    public const string CALLBACK_PATH = '/callback';

    /** Access tokens this close to expiry are refreshed before they are sent. */
    private const int REFRESH_SKEW_MS = 30_000;

    public function __construct(private readonly string $path)
    {
    }

    public static function defaultPath(string $agentDir): string
    {
        return $agentDir . '/mcp-auth.json';
    }

    // ---- the store ------------------------------------------------------------------------------

    private static function key(string $serverUrl): string
    {
        return $serverUrl;
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) && !array_is_list($data) ? $data : [];
    }

    /** @param Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>> $update */
    private function write(Closure $update): void
    {
        $states = $update($this->read());
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }

        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new RuntimeException("Could not write {$this->path}");
        }

        chmod($temporary, 0o600);
        rename($temporary, $this->path);
    }

    /** The state store for one server. */
    public function forServer(string $serverUrl): OauthStateStore
    {
        $key = self::key($serverUrl);

        return new class ($this, $key) implements OauthStateStore {
            public function __construct(private readonly McpOauth $owner, private readonly string $key)
            {
            }

            #[\Override]
            public function load(): ?array
            {
                return $this->owner->stateOf($this->key);
            }

            #[\Override]
            public function save(array $state): void
            {
                $this->owner->saveState($this->key, $state);
            }
        };
    }

    /**
     * @internal for the store `forServer()` answers
     * @return array<string, mixed>|null
     */
    public function stateOf(string $key): ?array
    {
        return $this->read()[$key] ?? null;
    }

    /**
     * @internal for the store `forServer()` answers
     * @param array<string, mixed> $state
     */
    public function saveState(string $key, array $state): void
    {
        $this->write(static function (array $states) use ($key, $state): array {
            $states[$key] = $state;

            return $states;
        });
    }

    /**
     * The stored tokens of a server, for noticing a sign-in done by another process.
     *
     * @return array<string, mixed>|null
     */
    public function tokens(string $serverUrl): ?array
    {
        return $this->read()[self::key($serverUrl)]['tokens'] ?? null;
    }

    /** Whether credentials were stored for the server. */
    public function remove(string $serverUrl): bool
    {
        $key = self::key($serverUrl);

        if (!array_key_exists($key, $this->read())) {
            return false;
        }

        $this->write(static function (array $states) use ($key): array {
            unset($states[$key]);

            return $states;
        });

        return true;
    }

    // ---- the auth provider a connection uses ----------------------------------------------------

    /**
     * Auth provider for one connection: sends the stored access token and refreshes it when it
     * is about to expire or after a 401. Throws `McpOauthAuthorizationRequiredError` when the
     * person has to sign in, including when the server asks for more scope.
     *
     * `$settings` is asked only when a refresh is needed, so an OAuth secret that fails to
     * resolve fails the refresh rather than the whole connection. `$onChallenge` is told the
     * server's `WWW-Authenticate` so sign-in can use its resource metadata URL and scope.
     *
     * @param Closure(): array<string, mixed> $settings
     * @param Closure(array{resourceMetadataUrl: ?string, scope: ?string, error: ?string, errorDescription: ?string}): void $onChallenge
     * @param Closure(Request): Response|null $fetch
     */
    public function authProvider(string $serverUrl, Closure $settings, Closure $onChallenge, ?Closure $fetch = null): AuthProvider
    {
        $store = $this->forServer($serverUrl);
        $fetch = Discovery::fetcher($fetch);

        return new class ($serverUrl, $store, $settings, $onChallenge, $fetch) implements AuthProvider {
            private ?Deferred $refreshing = null;

            public function __construct(
                private readonly string $serverUrl,
                private readonly OauthStateStore $store,
                private readonly Closure $settings,
                private readonly Closure $onChallenge,
                private readonly Closure $fetch,
            ) {
            }

            /** Replace `$staleToken`, the access token that expired or was rejected. */
            private function refresh(?string $staleToken, ?array $challenge = null): void
            {
                // Requests in this process share one refresh: many servers rotate refresh tokens,
                // and two refreshes with the same one lose the grant.
                if ($this->refreshing !== null) {
                    $this->refreshing->future->await();

                    return;
                }

                $this->refreshing = new Deferred();

                try {
                    $state = $this->store->load();

                    // Tokens that changed meanwhile (the person signed in) are used without refreshing.
                    if (($state['tokens']['access_token'] ?? null) !== $staleToken) {
                        return;
                    }

                    if (!is_string($state['tokens']['refresh_token'] ?? null)) {
                        throw new McpOauthAuthorizationRequiredError();
                    }

                    $settings = ($this->settings)();
                    $redirectUrl = McpOauth::callbackSettings($settings)['fixedRedirectUrl']
                        ?? ($state['clientInformation']['redirect_uris'][0] ?? null)
                        ?? 'http://' . McpOauth::CALLBACK_HOST . McpOauth::CALLBACK_PATH;
                    $provider = McpOauth::provider($this->serverUrl, $this->store, $settings, $redirectUrl, static fn () => null);

                    $result = Flow::authorize($provider, [
                        'serverUrl' => $this->serverUrl,
                        'resourceMetadataUrl' => $challenge['resourceMetadataUrl'] ?? null,
                        'scope' => $challenge['scope'] ?? null,
                    ], $this->fetch);

                    if ($result === Flow::REDIRECT) {
                        throw new McpOauthAuthorizationRequiredError();
                    }
                } catch (\Throwable $error) {
                    $this->refreshing->error($error);
                    $this->refreshing = null;

                    throw $error;
                }

                $this->refreshing->complete(null);
                $this->refreshing = null;
            }

            #[\Override]
            public function token(): ?string
            {
                if ($this->refreshing !== null) {
                    try {
                        $this->refreshing->future->await();
                    } catch (\Throwable) {
                        // The request goes out with whatever is stored; a 401 decides.
                    }
                }

                $state = $this->store->load();
                $token = $state['tokens']['access_token'] ?? null;
                $expiresAt = $state['tokensExpireAt'] ?? null;
                $expired = is_int($expiresAt) && $expiresAt - 30_000 <= (int) (microtime(true) * 1000);

                if (!$expired || !is_string($state['tokens']['refresh_token'] ?? null)) {
                    return is_string($token) ? $token : null;
                }

                try {
                    $this->refresh($token);
                } catch (\Throwable) {
                    // Failures fall through: the request goes out with the old token and a 401 decides.
                }

                $token = $this->store->load()['tokens']['access_token'] ?? null;

                return is_string($token) ? $token : null;
            }

            #[\Override]
            public function onUnauthorized(Response $response, string $serverUrl, ?string $token): void
            {
                $challenge = Metadata::wwwAuthenticate($response->header('www-authenticate'));
                ($this->onChallenge)($challenge);

                // A refresh keeps the granted scope, so more scope needs a new sign-in.
                if ($challenge['error'] === 'insufficient_scope') {
                    throw new McpOauthAuthorizationRequiredError();
                }

                $this->refresh($token, $challenge);
            }
        };
    }

    // ---- signing in -----------------------------------------------------------------------------

    /**
     * Where the browser is sent back to, from the server's `oauth` settings.
     *
     * @param array<string, mixed> $settings
     * @return array{host: string, redirectHost: string, port: ?int, path: string, fixedRedirectUrl: ?string}
     */
    public static function callbackSettings(array $settings): array
    {
        $callbackUrl = isset($settings['callbackUrl']) ? (string) $settings['callbackUrl'] : 'http://' . self::CALLBACK_HOST . self::CALLBACK_PATH;
        $parts = parse_url($callbackUrl) ?: [];
        $address = trim((string) ($parts['host'] ?? self::CALLBACK_HOST), '[]');
        $urlPort = isset($parts['port']) ? (int) $parts['port'] : null;
        $port = $urlPort ?? (isset($settings['callbackPort']) ? (int) $settings['callbackPort'] : null);
        $path = (string) ($parts['path'] ?? self::CALLBACK_PATH);
        $fixed = null;

        // A configured URI with a port is sent exactly as written, since servers compare it as a string.
        if ($urlPort !== null) {
            $fixed = $callbackUrl;
        } elseif ($port !== null) {
            $fixed = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? self::CALLBACK_HOST) . ":{$port}{$path}";
        }

        return [
            // `localhost` is served on 127.0.0.1; browsers fall back to it when ::1 refuses.
            'host' => $address === 'localhost' ? self::CALLBACK_HOST : $address,
            'redirectHost' => $address,
            'port' => $port,
            'path' => $path,
            'fixedRedirectUrl' => $fixed,
        ];
    }

    /**
     * @param array<string, mixed> $settings the server's `oauth` block
     * @param Closure(string): void $onRedirect
     */
    public static function provider(string $serverUrl, OauthStateStore $store, array $settings, string $redirectUrl, Closure $onRedirect): McpOauthProvider
    {
        return new McpOauthProvider(
            $serverUrl,
            $redirectUrl,
            ['client_name' => 'pig'],
            $onRedirect,
            isset($settings['clientId']) ? (string) $settings['clientId'] : null,
            isset($settings['clientSecret']) ? (string) $settings['clientSecret'] : null,
            $store,
        );
    }

    /**
     * Sign in to an MCP server. Uses the stored refresh token when possible; otherwise runs the
     * browser authorization code flow. Tokens are saved to the store.
     *
     * `$prompt['showAuthorizationUrl']` is `Closure(string): void`; `$prompt['promptForRedirectUrl']`
     * is `Closure(AbortSignal): ?string` — asked in parallel with the loopback callback, for a
     * browser on a machine that cannot reach this one, and aborted when the callback wins.
     *
     * @param array<string, mixed> $settings
     * @param array{resourceMetadataUrl: ?string, scope: ?string, error: ?string, errorDescription: ?string}|null $challenge
     * @param array{showAuthorizationUrl: Closure, promptForRedirectUrl: Closure} $prompt
     * @param Closure(Request): Response|null $fetch
     *
     * @throws McpSignInCancelledError when nobody finished the sign-in
     */
    public function signIn(string $serverUrl, array $settings, ?array $challenge, array $prompt, ?Closure $fetch = null, float $timeout = 300.0): void
    {
        $store = $this->forServer($serverUrl);
        $stored = $store->load();
        $callbackOptions = self::callbackSettings($settings);

        // Reuse the port of the registered redirect URI so the registered client stays valid.
        $registered = $stored['clientInformation']['redirect_uris'][0] ?? null;
        $preferredPort = $callbackOptions['port']
            ?? (is_string($registered) ? ((int) parse_url($registered, PHP_URL_PORT) ?: null) : null);

        $callback = self::listenForCallback($callbackOptions, $preferredPort, $callbackOptions['port'] !== null, $timeout);
        $redirectUrl = $callbackOptions['fixedRedirectUrl'] ?? $callback->redirectUrl;

        try {
            if ($stored !== null) {
                $next = $stored;
                // Every sign-in gets a fresh `state` parameter.
                unset($next['oauthState']);

                // A registered client cannot use another redirect URI, and its tokens belong to it.
                if (!isset($settings['clientId']) && !in_array($redirectUrl, $stored['clientInformation']['redirect_uris'] ?? [], true)) {
                    unset($next['clientInformation'], $next['tokens'], $next['tokensExpireAt']);
                }

                $store->save($next);
            }

            $authorizationUrl = null;
            $provider = self::provider($serverUrl, $store, $settings, $redirectUrl, static function (string $url) use (&$authorizationUrl): void {
                $authorizationUrl = $url;
            });

            $flow = [
                'serverUrl' => $serverUrl,
                'resourceMetadataUrl' => $challenge['resourceMetadataUrl'] ?? null,
                // A server asking for more scope gets it on top of the configured scope.
                'scope' => self::mergeScopes($settings['scope'] ?? null, $challenge['scope'] ?? null),
            ];

            // A refresh keeps the granted scope; a server asking for more needs the browser flow.
            if (Flow::authorize($provider, [...$flow, 'skipRefresh' => ($challenge['error'] ?? null) === 'insufficient_scope'], $fetch) === Flow::AUTHORIZED) {
                return;
            }

            if ($authorizationUrl === null) {
                throw new RuntimeException('OAuth flow did not produce an authorization URL');
            }

            $state = $provider->state();
            ($prompt['showAuthorizationUrl'])($authorizationUrl);
            $code = self::waitForAuthorizationCode($callback, $state, $prompt['promptForRedirectUrl']);
            Flow::authorize($provider, [...$flow, 'authorizationCode' => $code], $fetch);
        } finally {
            $callback->close();
        }
    }

    /** Listen on `$port`, or on a free port when it is taken and not `$required`. */
    private static function listenForCallback(array $settings, ?int $port, bool $required, float $timeout): OauthCallbackServer
    {
        try {
            return OauthCallbackServer::listen($settings['host'], $settings['redirectHost'], $port ?? 0, $settings['path'], $timeout);
        } catch (RuntimeException $error) {
            if ($required || $port === null) {
                throw $error;
            }

            return OauthCallbackServer::listen($settings['host'], $settings['redirectHost'], 0, $settings['path'], $timeout);
        }
    }

    /**
     * Wait for the browser callback or a pasted redirect URL, whichever comes first.
     *
     * @param Closure(AbortSignal): ?string $promptForRedirectUrl
     */
    private static function waitForAuthorizationCode(OauthCallbackServer $callback, string $state, Closure $promptForRedirectUrl): string
    {
        $controller = new AbortController();
        $answer = new Deferred();

        $settle = static function (Closure $produce) use ($answer): void {
            try {
                $value = $produce();
            } catch (\Throwable $error) {
                if (!$answer->isComplete()) {
                    $answer->error($error);
                }

                return;
            }

            if (!$answer->isComplete()) {
                $answer->complete($value);
            }
        };

        Async::spawn(static fn () => $settle(static fn (): string => $callback->waitForCallback($state)['code']));
        Async::spawn(static fn () => $settle(static function () use ($promptForRedirectUrl, $controller, $state): string {
            $input = $promptForRedirectUrl($controller->signal);

            if ($input === null || trim($input) === '') {
                throw new McpSignInCancelledError();
            }

            return self::codeFromRedirectUrl($input, $state);
        }));

        try {
            return $answer->future->await();
        } finally {
            // The losing side ends once the prompt is aborted or the callback server closes.
            $controller->abort('sign-in settled');
        }
    }

    private static function codeFromRedirectUrl(string $input, string $state): string
    {
        $parts = parse_url(trim($input));

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Expected the full redirect URL from the browser address bar');
        }

        parse_str($parts['query'] ?? '', $query);

        if (is_string($query['error'] ?? null)) {
            throw new RuntimeException(is_string($query['error_description'] ?? null) ? $query['error_description'] : $query['error']);
        }

        if (($query['state'] ?? null) !== $state) {
            throw new RuntimeException('The redirect URL belongs to a different sign-in');
        }

        if (!is_string($query['code'] ?? null) || $query['code'] === '') {
            throw new RuntimeException('The redirect URL does not contain an authorization code');
        }

        return $query['code'];
    }

    /** Scopes of both lists, each once. */
    private static function mergeScopes(?string ...$scopes): ?string
    {
        $merged = [];

        foreach ($scopes as $scope) {
            foreach (preg_split('/\s+/', (string) $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
                $merged[$one] = true;
            }
        }

        return $merged === [] ? null : implode(' ', array_keys($merged));
    }
}
