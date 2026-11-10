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
use Pig\CodingAgent\FileLock;
use Pig\CodingAgent\McpServers;
use Pig\CodingAgent\FileLockBusyError;
use Pig\Mcp\Oauth\McpClientMetadataDocuments;
use Pig\Mcp\Oauth\Discovery;
use Pig\Mcp\Oauth\Flow;
use Pig\Mcp\Oauth\McpOauthAuthorizationRequiredError;
use Pig\Mcp\Oauth\McpRefreshLockBusyError;
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
 * in through `/mcp login` or `pig mcp login`, which runs the authorization code flow with PKCE
 * against a loopback callback. pig identifies itself with its Client ID Metadata Document on
 * pigagent.dev where the authorization server takes one, and registers a client dynamically
 * elsewhere (`oauth.clientRegistration`, upstream's).
 *
 * Credentials live in `<agent-dir>/mcp-auth.json`, keyed by server URL, `0600`.
 *
 * A refresh is taken under a per-server lock file beside `mcp-auth.json` (`FileLock`, which is
 * `flock()` where upstream takes `proper-lockfile`), and the stored tokens are read again once it
 * is held: two pigs refreshing one rotating token at the same instant would otherwise lose the
 * grant, and one of them would have to sign in again.
 */
final class McpOauth
{
    public const string CALLBACK_HOST = '127.0.0.1';

    public const string CALLBACK_PATH = '/callback';

    /**
     * pig's Client ID Metadata Documents — upstream's on pi.dev, served by pigagent.dev:
     * `client.json`, and `<callback ID>/client.json` for an authorization server that sends no `iss`.
     * They list `/callback` (and `/callback/<callback ID>`) on 127.0.0.1 and localhost, any port.
     */
    public const string CLIENT_METADATA_DOCUMENTS_URL = 'https://pigagent.dev/oauth';

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

    /**
     * The keys of a server's state: by **name and URL**, so two servers at one URL keep separate
     * accounts (upstream's #10252), and the legacy key by URL alone that older versions wrote.
     *
     * @return array{key: string, legacy: string}
     */
    private static function keys(string $name, string $serverUrl): array
    {
        return ['key' => "mcp__{$name}|{$serverUrl}", 'legacy' => $serverUrl];
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

    /**
     * The state store for one server.
     *
     * The first server to load state stored under the legacy URL-only key takes it over: the
     * entry is moved under the server's own key, so a second server at the same URL finds nothing
     * and signs in on its own.
     */
    public function forServer(string $name, string $serverUrl): OauthStateStore
    {
        ['key' => $key, 'legacy' => $legacy] = self::keys($name, $serverUrl);

        return new class ($this, $key, $legacy) implements OauthStateStore {
            public function __construct(private readonly McpOauth $owner, private readonly string $key, private readonly string $legacy)
            {
            }

            #[\Override]
            public function load(): ?array
            {
                return $this->owner->stateOf($this->key, $this->legacy);
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
    public function stateOf(string $key, string $legacy): ?array
    {
        $states = $this->read();

        if (isset($states[$key]) || !isset($states[$legacy])) {
            return $states[$key] ?? null;
        }

        $taken = null;
        $this->write(static function (array $states) use ($key, $legacy, &$taken): array {
            if (!isset($states[$key]) && isset($states[$legacy])) {
                $states[$key] = $states[$legacy];
                unset($states[$legacy]);
            }

            $taken = $states[$key] ?? null;

            return $states;
        });

        return $taken;
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
    public function tokens(string $name, string $serverUrl): ?array
    {
        ['key' => $key, 'legacy' => $legacy] = self::keys($name, $serverUrl);
        $states = $this->read();

        // Does not take legacy state over: noticing is not owning.
        return ($states[$key] ?? $states[$legacy] ?? null)['tokens'] ?? null;
    }

    /** Whether credentials were stored for the server. Removes legacy state the server would take over. */
    public function remove(string $name, string $serverUrl): bool
    {
        ['key' => $key, 'legacy' => $legacy] = self::keys($name, $serverUrl);
        $states = $this->read();
        $stored = array_key_exists($key, $states) ? $key : (array_key_exists($legacy, $states) ? $legacy : null);

        if ($stored === null) {
            return false;
        }

        $this->write(static function (array $states) use ($stored): array {
            unset($states[$stored]);

            return $states;
        });

        return true;
    }

    // ---- the auth provider a connection uses ----------------------------------------------------

    /**
     * `withRefreshLock` for one server: a lock file per server, as upstream keeps one, so refreshing
     * one server does not wait on another's. Named by a hash of the key, which holds a URL;
     * `.flock`, since upstream's `.lock` of the same name is a directory (`FileLock::pathFor()`).
     * A lock held by another process for the whole wait is `McpRefreshLockBusyError`, upstream's.
     *
     * @return Closure(Closure(): mixed): mixed
     */
    private function refreshLock(string $name, string $serverUrl): Closure
    {
        $base = dirname($this->path) . '/mcp-auth-refresh-' . substr(hash('sha256', self::keys($name, $serverUrl)['key']), 0, 16);
        FileLock::clearLegacy($base);
        $lock = FileLock::pathFor($base);

        return static function (Closure $fn) use ($lock): mixed {
            try {
                return FileLock::hold($lock, $fn);
            } catch (FileLockBusyError) {
                throw new McpRefreshLockBusyError();
            }
        };
    }

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
    public function authProvider(string $name, string $serverUrl, Closure $settings, Closure $onChallenge, ?Closure $fetch = null): AuthProvider
    {
        $store = $this->forServer($name, $serverUrl);
        $fetch = Discovery::fetcher($fetch);
        $withRefreshLock = $this->refreshLock($name, $serverUrl);

        return new class ($serverUrl, $store, $settings, $onChallenge, $fetch, $withRefreshLock) implements AuthProvider {
            private ?Deferred $refreshing = null;

            public function __construct(
                private readonly string $serverUrl,
                private readonly OauthStateStore $store,
                private readonly Closure $settings,
                private readonly Closure $onChallenge,
                private readonly Closure $fetch,
                private readonly Closure $withRefreshLock,
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
                    // Under the server's lock across processes, and the tokens read again once it
                    // is held: ones that changed meanwhile (the person signed in, or another pig
                    // refreshed first) are used without refreshing.
                    ($this->withRefreshLock)(function () use ($staleToken, $challenge): void {
                        $state = $this->store->load();

                        if (($state['tokens']['access_token'] ?? null) !== $staleToken) {
                            return;
                        }

                        if (!is_string($state['tokens']['refresh_token'] ?? null)) {
                            throw new McpOauthAuthorizationRequiredError();
                        }

                        $result = McpOauth::refreshStoredTokens($this->serverUrl, $this->store, ($this->settings)(), $state, $challenge, null, $this->fetch);

                        if ($result === Flow::REDIRECT) {
                            throw new McpOauthAuthorizationRequiredError();
                        }
                    });
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
     * @return array{url: string, host: string, redirectHost: string, port: ?int, path: string, fixedRedirectUrl: ?string}
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
            // The redirect URI as configured, without the port unless one is configured.
            'url' => $callbackUrl,
            // `localhost` is served on 127.0.0.1; browsers fall back to it when ::1 refuses.
            'host' => $address === 'localhost' ? self::CALLBACK_HOST : $address,
            'redirectHost' => $address,
            'port' => $port,
            'path' => $path,
            'fixedRedirectUrl' => $fixed,
        ];
    }

    /** pig's documents, listing what `McpServers::isClientMetadataDocumentCallback()` says they list. */
    public static function clientMetadataDocuments(): McpClientMetadataDocuments
    {
        return new McpClientMetadataDocuments(
            self::CLIENT_METADATA_DOCUMENTS_URL,
            static fn (string $redirectUrl): bool => McpServers::isClientMetadataDocumentCallback($redirectUrl),
        );
    }

    /**
     * The OAuth client for one server — upstream's `createOAuthClientProvider()`: a pre-registered
     * `clientId`, pig's Client ID Metadata Document, or a client registered as `clientName` (`pig`).
     *
     * @param array<string, mixed> $settings the server's `oauth` block
     * @param Closure(string): void $onRedirect
     */
    public static function provider(string $serverUrl, OauthStateStore $store, array $settings, string $redirectUrl, Closure $onRedirect): McpOauthProvider
    {
        return new McpOauthProvider(
            $serverUrl,
            $redirectUrl,
            ['client_name' => isset($settings['clientName']) ? (string) $settings['clientName'] : 'pig'],
            $onRedirect,
            isset($settings['clientId']) ? (string) $settings['clientId'] : null,
            isset($settings['clientSecret']) ? (string) $settings['clientSecret'] : null,
            $store,
            McpClientMetadataDocuments::chooser($settings, self::clientMetadataDocuments(), $serverUrl, $redirectUrl),
        );
    }

    /**
     * Refresh the stored tokens of `$state` with the stored client, or the document it signed in
     * with, or answer `REDIRECT` — upstream's `refreshStoredTokens()`. Never registers a client or
     * prepares an authorization request, so a sign-in in progress stays usable. Called under the
     * server's refresh lock.
     *
     * Refreshing redirects nobody; the redirect URI only decides whether tokens without a stored
     * client can be refreshed as the document, so any one the documents list does: the sign-in's,
     * the configured one, the stored client's, else the default.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $state
     * @param array{resourceMetadataUrl: ?string, scope: ?string, error: ?string, errorDescription: ?string}|null $challenge
     * @param Closure(Request): Response|null $fetch
     */
    public static function refreshStoredTokens(string $serverUrl, OauthStateStore $store, array $settings, array $state, ?array $challenge, ?string $redirectUrl, ?Closure $fetch): string
    {
        $documents = self::clientMetadataDocuments();
        $candidates = array_filter([
            $redirectUrl,
            self::callbackSettings($settings)['fixedRedirectUrl'],
            McpClientMetadataDocuments::registeredRedirectUrls($state['clientInformation'] ?? null)[0] ?? null,
        ], static fn (?string $url): bool => $url !== null && $documents->listsRedirectUrl($url));

        $provider = self::provider($serverUrl, $store, $settings, array_values($candidates)[0] ?? 'http://' . self::CALLBACK_HOST . self::CALLBACK_PATH, static fn () => null);

        return Flow::authorize($provider, [
            'serverUrl' => $serverUrl,
            'resourceMetadataUrl' => $challenge['resourceMetadataUrl'] ?? null,
            'authorizationServerMetadataUrl' => isset($settings['authServerMetadataUrl']) ? (string) $settings['authServerMetadataUrl'] : null,
            'refreshOnly' => true,
        ], $fetch);
    }

    /**
     * The stored state for a browser step — upstream's `stateForSignIn()`: without the last
     * sign-in's `state`, and without a client that cannot use `$redirectUrl`, along with its
     * tokens. `cimd` replaces a registered client; `auto` keeps it. A document is not stored, so
     * tokens without a stored client belong to the document.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function stateForSignIn(array $stored, array $settings, string $redirectUrl): array
    {
        $next = $stored;
        unset($next['oauthState']);

        $keepClient = isset($settings['clientId']) || (isset($stored['clientInformation'])
            ? ($settings['clientRegistration'] ?? null) !== 'cimd'
                && in_array($redirectUrl, McpClientMetadataDocuments::registeredRedirectUrls($stored['clientInformation']), true)
            : McpClientMetadataDocuments::offered($settings, self::clientMetadataDocuments(), $redirectUrl));

        if (!$keepClient) {
            unset($next['clientInformation'], $next['tokens'], $next['tokensExpireAt']);
        }

        return $next;
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
     * Aborting `$signal` stops the sign-in at any step with `McpSignInCancelledError` — upstream's
     * `signInMcpServer({signal})` (#10565): every request to the authorization server is handed it,
     * and the redirect URL prompt is aborted with it.
     *
     * @throws McpSignInCancelledError when nobody finished the sign-in, or `$signal` aborted it
     */
    public function signIn(string $name, string $serverUrl, array $settings, ?array $challenge, array $prompt, ?Closure $fetch = null, float $timeout = 300.0, ?AbortSignal $signal = null): void
    {
        if ($signal?->aborted() ?? false) {
            throw new McpSignInCancelledError();
        }

        $store = $this->forServer($name, $serverUrl);
        $stored = $store->load();
        $stepUp = ($challenge['error'] ?? null) === 'insufficient_scope';
        $callbackOptions = self::callbackSettings($settings);

        // Reuse the port of the registered redirect URI so the registered client stays valid.
        $registered = McpClientMetadataDocuments::registeredRedirectUrls($stored['clientInformation'] ?? null)[0] ?? null;
        $preferredPort = $callbackOptions['port']
            ?? ($registered !== null ? ((int) parse_url($registered, PHP_URL_PORT) ?: null) : null);

        // The redirect URI of a server-specific document is served as well, upstream's `extraPaths`.
        $extraPaths = McpClientMetadataDocuments::offered($settings, self::clientMetadataDocuments(), $callbackOptions['url'])
            ? [McpClientMetadataDocuments::serverSpecificPath($callbackOptions['path'], $serverUrl)]
            : [];
        $callback = self::listenForCallback($callbackOptions, $preferredPort, $callbackOptions['port'] !== null, $timeout, $extraPaths);
        $redirectUrl = $callbackOptions['fixedRedirectUrl'] ?? $callback->redirectUrl;

        try {
            $flow = [
                'serverUrl' => $serverUrl,
                'signal' => $signal,
                'resourceMetadataUrl' => $challenge['resourceMetadataUrl'] ?? null,
                'authorizationServerMetadataUrl' => isset($settings['authServerMetadataUrl']) ? (string) $settings['authServerMetadataUrl'] : null,
                // A server asking for more scope gets it on top of the configured scope and — since
                // the challenge may list only the missing scopes — on top of the scope granted so
                // far, or the new token loses access the old one had and the server asks again.
                'scope' => self::mergeScopes(
                    $settings['scope'] ?? null,
                    $stepUp ? Flow::stepUpScope($stored['tokens']['scope'] ?? null, $challenge['scope'] ?? null) : ($challenge['scope'] ?? null),
                ),
            ];

            // A refresh keeps the granted scope; a server asking for more needs the browser flow.
            // Under the server's refresh lock, as upstream's `refreshBeforeSignIn()`: two pigs
            // refreshing one rotating token at once would lose the grant. Another process holding
            // it for the whole wait means the person signs in instead — upstream's `REDIRECT`.
            if (!$stepUp) {
                try {
                    $refreshed = ($this->refreshLock($name, $serverUrl))(function () use ($store, $settings, $serverUrl, $challenge, $redirectUrl, $fetch, $signal): string {
                        $state = $store->load();

                        // `cimd` is to replace a registered client, whose tokens are not refreshed.
                        if (($signal?->aborted() ?? false)
                            || !is_string($state['tokens']['refresh_token'] ?? null)
                            || (($settings['clientRegistration'] ?? null) === 'cimd' && isset($state['clientInformation']) && !isset($settings['clientId']))
                        ) {
                            return Flow::REDIRECT;
                        }

                        return self::refreshStoredTokens($serverUrl, $store, $settings, $state, $challenge, $redirectUrl, $fetch);
                    });
                } catch (McpRefreshLockBusyError) {
                    $refreshed = Flow::REDIRECT;
                }

                if ($refreshed === Flow::AUTHORIZED) {
                    return;
                }
            }

            // The browser step — upstream's `startBrowserStep()`.
            $stored = $store->load();

            if ($stored !== null) {
                $store->save(self::stateForSignIn($stored, $settings, $redirectUrl));
            }

            $authorizationUrl = null;
            $provider = self::provider($serverUrl, $store, $settings, $redirectUrl, static function (string $url) use (&$authorizationUrl): void {
                $authorizationUrl = $url;
            });
            Flow::authorize($provider, [...$flow, 'skipRefresh' => true], $fetch);

            if ($authorizationUrl === null) {
                throw new RuntimeException('OAuth flow did not produce an authorization URL');
            }

            // The flow picks the redirect URI, which may be specific to the MCP server.
            parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
            $authorizationRedirectUrl = is_string($query['redirect_uri'] ?? null) ? $query['redirect_uri'] : $redirectUrl;
            $state = $provider->state();
            ($prompt['showAuthorizationUrl'])($authorizationUrl);
            ['code' => $code, 'iss' => $iss] = self::waitForAuthorizationResponse($callback, $state, $authorizationRedirectUrl, $prompt['promptForRedirectUrl'], $signal);

            // The stored PKCE verifier belongs to the latest sign-in; exchanging with it would fail
            // with `invalid_grant`, which discards the stored tokens.
            if (($store->load()['oauthState'] ?? null) !== $state) {
                throw new RuntimeException('Another sign-in to this MCP server started since; sign in again');
            }

            Flow::authorize(self::provider($serverUrl, $store, $settings, $redirectUrl, static fn () => null), [
                ...$flow,
                'authorizationCode' => $code,
                'authorizationRedirectUrl' => $authorizationRedirectUrl,
                'iss' => $iss,
            ], $fetch);
        } catch (\Throwable $error) {
            // "Aborted requests fail with the signal's reason; report them as the cancellation they are."
            if ($signal?->aborted() ?? false) {
                throw new McpSignInCancelledError();
            }

            throw $error;
        } finally {
            $callback->close();
        }
    }

    /**
     * Listen on `$port`, or on a free port when it is taken and not `$required`.
     *
     * @param list<string> $extraPaths
     */
    private static function listenForCallback(array $settings, ?int $port, bool $required, float $timeout, array $extraPaths = []): OauthCallbackServer
    {
        try {
            return OauthCallbackServer::listen($settings['host'], $settings['redirectHost'], $port ?? 0, $settings['path'], $timeout, $extraPaths);
        } catch (RuntimeException $error) {
            if ($required || $port === null) {
                throw $error;
            }

            return OauthCallbackServer::listen($settings['host'], $settings['redirectHost'], 0, $settings['path'], $timeout, $extraPaths);
        }
    }

    /**
     * Wait for the browser callback or a pasted redirect URL, whichever comes first. Aborting
     * `$signal` aborts the prompt, which then cancels the sign-in.
     *
     * The response has to come back on `$redirectUrl`, the authorization request's: a server-specific
     * redirect URI is what tells two authorization servers apart.
     *
     * @param Closure(AbortSignal): ?string $promptForRedirectUrl
     * @return array{code: string, iss: ?string} the code, and the `iss` the response carried (RFC 9207)
     */
    private static function waitForAuthorizationResponse(OauthCallbackServer $callback, string $state, string $redirectUrl, Closure $promptForRedirectUrl, ?AbortSignal $signal = null): array
    {
        $controller = new AbortController();
        $answer = new Deferred();
        $outer = $signal?->onAbort(static function () use ($controller): void {
            $controller->abort('sign-in cancelled');
        });

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

        Async::spawn(static fn () => $settle(static function () use ($callback, $state, $redirectUrl): array {
            $response = $callback->waitForCallback($state, (string) parse_url($redirectUrl, PHP_URL_PATH));

            return ['code' => $response['code'], 'iss' => $response['iss'] ?? null];
        }));
        Async::spawn(static fn () => $settle(static function () use ($promptForRedirectUrl, $controller, $state, $redirectUrl): array {
            $input = $promptForRedirectUrl($controller->signal);

            if ($input === null || trim($input) === '') {
                throw new McpSignInCancelledError();
            }

            return self::responseFromRedirectUrl($input, $state, $redirectUrl);
        }));

        try {
            return $answer->future->await();
        } finally {
            if ($outer !== null) {
                $signal?->removeListener($outer);
            }

            // The losing side ends once the prompt is aborted or the callback server closes.
            $controller->abort('sign-in settled');
        }
    }

    /** @return array{code: string, iss: ?string} */
    private static function responseFromRedirectUrl(string $input, string $state, string $redirectUrl): array
    {
        $parts = parse_url(trim($input));

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Expected the full redirect URL from the browser address bar');
        }

        // A server-specific redirect URI tells authorization servers apart, so it must match exactly.
        $expected = parse_url($redirectUrl) ?: [];
        $origin = static fn (array $url): string => strtolower(($url['scheme'] ?? '') . '://' . ($url['host'] ?? '') . ':' . ($url['port'] ?? ''));

        if ($origin($parts) !== $origin($expected) || ($parts['path'] ?? '/') !== ($expected['path'] ?? '/')) {
            throw new RuntimeException("The redirect URL does not match this sign-in's redirect URI");
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

        return ['code' => $query['code'], 'iss' => is_string($query['iss'] ?? null) && $query['iss'] !== '' ? $query['iss'] : null];
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
