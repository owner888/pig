<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Throwable;

/**
 * Signing in with a Claude Pro or Max subscription.
 *
 * Upstream's `auth/oauth/anthropic.ts` as of pi 1.0 — the file moved and grew between the anchor
 * and there, and this follows it. Two ways in, which is what `/login` asks first:
 *
 * - **Browser** (the default): a loopback server listens on `localhost:53692/callback`, the
 *   browser is sent to claude.ai, and the code arrives on the socket. The paste box is open at
 *   the same time, for a browser on a machine that cannot reach this one — whichever answers
 *   first wins and the other is closed. Upstream's `waitForCallbackOrManualInput`.
 * - **Copy code** (headless): the redirect goes to Anthropic's own page, which shows a
 *   `code#state` to bring back. The flow pig had from the start, and the one that needs nothing
 *   but a URL and a paste.
 *
 * `Providers\Anthropic` was already written for the far end of this — an `sk-ant-oat` key goes
 * out as a bearer token under Claude Code's identity; see `Providers\ClaudeCode`.
 *
 * **The endpoints moved.** The anchor had `console.anthropic.com`; pi 1.0 has
 * `platform.claude.com`, and the old callback page is a 301 to the new one (measured). The
 * scopes grew by three at the same time — `user:sessions:claude_code`, `user:mcp_servers`,
 * `user:file_upload` — which a token minted under the old three does not carry.
 *
 * The client id is written out rather than hidden behind `atob()` as upstream has it: an id
 * that is in a published npm package and in every request this makes is not a secret, and a
 * reader who cannot see what it is has to go and decode it to find out.
 */
final class Anthropic
{
    public const string AUTHORIZE_URL = 'https://claude.ai/oauth/authorize';

    public const string TOKEN_URL = 'https://platform.claude.com/v1/oauth/token';

    /** Where the copy-code flow sends the browser: Anthropic's page that shows the code. */
    public const string COPY_CODE_REDIRECT_URI = 'https://platform.claude.com/oauth/code/callback';

    /** Registered with Anthropic for this client id, so neither is a preference. */
    public const int CALLBACK_PORT = 53692;

    public const string CALLBACK_PATH = '/callback';

    public const string SCOPES = 'org:create_api_key user:profile user:inference'
        . ' user:sessions:claude_code user:mcp_servers user:file_upload';

    public const string CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    public const string METHOD_BROWSER = 'browser';

    public const string METHOD_COPY_CODE = 'copy_code';

    /** Five minutes, which is upstream's margin: a token is treated as dead before it is. */
    private const int MARGIN_MS = 5 * 60 * 1000; // 5 minutes

    /**
     * @param string $tokenUrl the endpoint, so a test can point it at a loopback server. Not a
     *        seam that exists for the test alone — every provider in this package takes its
     *        base URL from outside for the same reason, and an auth flow with no coverage at
     *        all is the alternative.
     * @param CallbackServer|null $server the loopback the browser comes back to, injectable so a
     *        test can take a free port rather than the registered one
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $tokenUrl = self::TOKEN_URL,
        private readonly ?CallbackServer $server = null,
    ) {
    }

    /** The redirect the browser flow registers, and the one it tells Anthropic to come back to. */
    public static function callbackRedirectUri(): string
    {
        return (new CallbackServer(self::CALLBACK_PORT, self::CALLBACK_PATH))->redirectUri();
    }

    /**
     * Where to send the person.
     *
     * `state` is the verifier itself rather than a second random string. That is upstream's
     * choice and Anthropic's flow: the callback hands back `code` and `state`, so the state
     * travels home — through the socket or the clipboard — and `exchange()` can check that the
     * code it is redeeming came from the request it made.
     */
    public static function authorizeUrl(Pkce $pkce, string $redirectUri = self::COPY_CODE_REDIRECT_URI): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'code' => 'true',
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $pkce->verifier,
        ]);
    }

    /**
     * The browser flow: listen, send the browser, take whichever answers first. Null means
     * nobody finished.
     *
     * `listen()` comes before anything is shown, so a port that cannot be taken is reported to
     * somebody who has not yet been sent to a browser. The paste box is opened beside the
     * callback — not instead of it — because upstream's reason holds here: the browser may be on
     * another machine, and the person then has the redirect URL and nothing else.
     *
     * @param Closure(string, ?string): void $onAuth where to go, and what to do there
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt the paste box, which
     *        closes by itself (answering null) when the callback wins
     * @param Closure(string): void|null $onProgress
     */
    public function loginWithBrowser(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
    ): ?Credentials {
        $pkce = Pkce::create();
        $server = $this->server ?? new CallbackServer(self::CALLBACK_PORT, self::CALLBACK_PATH);
        $redirectUri = $server->redirectUri();

        $server->listen();

        try {
            $onAuth(
                self::authorizeUrl($pkce, $redirectUri),
                'Finish signing in in the browser — the answer comes back here by itself. '
                . 'If the browser is on another machine, paste the final redirect URL instead.',
            );

            $answer = $this->firstOf($server, $pkce->verifier, $onPrompt, $signal);
        } finally {
            $server->close();
        }

        if ($answer === null) {
            return null;
        }

        if ($onProgress !== null) {
            $onProgress('Exchanging the code for tokens…');
        }

        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $answer['code'],
            'state' => $answer['state'],
            'redirect_uri' => $redirectUri,
            'code_verifier' => $pkce->verifier,
        ]);
    }

    /**
     * The copy-code flow: a URL, then a paste. Null means nobody finished.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @param Closure(string): void|null $onProgress
     */
    public function loginWithCopyCode(Closure $onAuth, Closure $onPrompt, ?Closure $onProgress = null): ?Credentials
    {
        $pkce = Pkce::create();
        $onAuth(
            self::authorizeUrl($pkce),
            'Finish signing in in the browser, then copy the code Anthropic shows and paste it here.',
        );

        $pasted = $onPrompt('Paste the code Anthropic shows after you sign in', 'code#state', false, null);

        if ($pasted === null || trim($pasted) === '') {
            return null;
        }

        if ($onProgress !== null) {
            $onProgress('Exchanging the code for tokens…');
        }

        return $this->exchange($pasted, $pkce->verifier);
    }

    /**
     * Turn what was pasted back into tokens, through the copy-code redirect.
     *
     * @param string $pasted the `code#state` from the callback page, whitespace and all
     * @param string $verifier the one `authorizeUrl()` was built from
     */
    public function exchange(string $pasted, string $verifier): Credentials
    {
        $parsed = self::parseAuthorizationInput($pasted);

        // One deliberate difference from upstream, which sends the state it has (the verifier)
        // and lets Anthropic answer `invalid_grant` when the paste was half of one. The copy-code
        // page always hands over both halves, so a paste with no state is a paste that lost its
        // end — and "the code you pasted is incomplete" is an answer somebody can act on, where
        // the server's is not.
        if ($parsed['code'] === null || $parsed['state'] === null) {
            throw new OauthError('That is not a whole authorization code — paste all of it, including the part after the #.');
        }

        if (!hash_equals($verifier, $parsed['state'])) {
            throw new OauthError('The code came back with the wrong state, so it was not the one this pig asked for.');
        }

        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $parsed['code'],
            'state' => $parsed['state'],
            'redirect_uri' => self::COPY_CODE_REDIRECT_URI,
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * What somebody pasted, in any of the shapes it arrives in.
     *
     * Upstream's `parseAuthorizationInput()`: the whole redirect URL out of the address bar, a
     * bare `code=…&state=…` query string, Anthropic's `code#state`, or just the code. The first
     * is what the browser flow's paste box exists for, the third is what the copy-code page shows,
     * and the last is a paste that lost its end — which `exchange()` refuses by name.
     *
     * @return array{code: ?string, state: ?string}
     */
    public static function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);

        if ($value === '') {
            return ['code' => null, 'state' => null];
        }

        $parts = parse_url($value);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            parse_str($parts['query'] ?? '', $query);

            return [
                'code' => self::nonEmpty($query['code'] ?? null),
                'state' => self::nonEmpty($query['state'] ?? null),
            ];
        }

        if (str_contains($value, '#')) {
            [$code, $state] = explode('#', $value, 2);

            return ['code' => self::nonEmpty($code), 'state' => self::nonEmpty($state)];
        }

        if (str_contains($value, 'code=')) {
            parse_str($value, $query);

            return [
                'code' => self::nonEmpty($query['code'] ?? null),
                'state' => self::nonEmpty($query['state'] ?? null),
            ];
        }

        return ['code' => $value, 'state' => null];
    }

    /** A new access token from the refresh token, which is what every expired session needs. */
    public function refresh(string $refreshToken): Credentials
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * The callback or the paste, whichever comes first; null when both were given up on.
     *
     * The same race `McpOauth::waitForAuthorizationResponse()` runs, for the same reason: the
     * loser has to be told to stop, or a paste box stays open over a sign-in that has finished.
     * A pasted answer is checked against the verifier here; the callback's state is checked the
     * same way, because a code that came back with somebody else's state is not ours.
     *
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @return array{code: string, state: string}|null
     */
    private function firstOf(CallbackServer $server, string $verifier, Closure $onPrompt, ?AbortSignal $signal): ?array
    {
        $controller = new AbortController();
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

            if (!$answer->isComplete()) {
                $answer->complete($value);
            }
        };

        $outer = $signal?->onAbort(static function () use ($controller): void {
            $controller->abort('sign-in cancelled');
        });

        Async::spawn(static fn () => $settle(static function () use ($server, $controller, $verifier): ?array {
            $callback = $server->await($controller->signal);

            if ($callback === null) {
                return null;
            }

            if (!hash_equals($verifier, $callback['state'])) {
                throw new OauthError('The sign-in came back with the wrong state, so it was not the one this pig started.');
            }

            return $callback;
        }));

        Async::spawn(static fn () => $settle(static function () use ($onPrompt, $controller, $verifier): ?array {
            $input = $onPrompt(
                'Finish signing in in the browser, or paste the redirect URL or code here',
                self::callbackRedirectUri(),
                false,
                $controller->signal,
            );

            // The box closed because the callback won, or because escape was pressed. Either way
            // this side has nothing to say; if the callback also answers null, nobody finished.
            if ($input === null || trim($input) === '') {
                return null;
            }

            $parsed = self::parseAuthorizationInput($input);

            if ($parsed['code'] === null) {
                throw new OauthError('That is not an authorization code or a redirect URL carrying one.');
            }

            if ($parsed['state'] !== null && !hash_equals($verifier, $parsed['state'])) {
                throw new OauthError('The code came back with the wrong state, so it was not the one this pig asked for.');
            }

            // A bare code has no state with it; upstream sends the verifier in its place, and
            // so does this.
            return ['code' => $parsed['code'], 'state' => $parsed['state'] ?? $verifier];
        }));

        try {
            $result = $answer->future->await();
        } finally {
            // Whichever side lost ends here: the paste box closes on its signal, and the server
            // is closed by the caller's `finally`.
            $controller->abort('sign-in settled');

            if ($signal !== null && $outer !== null) {
                $signal->removeListener($outer);
            }
        }

        return is_array($result) ? $result : null;
    }

    /**
     * The one POST both halves make, and the only place the answer is trusted.
     *
     * @param array<string, string> $payload
     */
    private function token(array $payload): Credentials
    {
        $response = $this->http->send(new Request(
            'POST',
            $this->tokenUrl,
            ['content-type' => 'application/json', 'accept' => 'application/json'],
            self::encode($payload),
        ));

        $body = $response->body->all();

        if (!$response->isSuccessful()) {
            // The body, not a tidied summary of it: an OAuth error is a short JSON object
            // naming what was wrong, and it is the only thing that says which of six
            // identical-looking mistakes was made.
            throw new OauthError("Anthropic answered {$response->status} to the token request: {$body}");
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new OauthError('Anthropic answered the token request with something that is not JSON.');
        }

        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $lifetime = $data['expires_in'] ?? null;

        // Named one by one rather than cast into shape. A credential half-built from a
        // response that changed is a session that fails later, somewhere else, once.
        if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '' || !is_int($lifetime)) {
            throw new OauthError('Anthropic answered the token request without the tokens in it.');
        }

        return new Credentials(
            $refresh,
            $access,
            Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
        );
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, string> $payload */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $problem) {
            throw new OauthError('Could not encode the token request.', 0, $problem);
        }
    }
}
