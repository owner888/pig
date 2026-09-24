<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Throwable;

/**
 * Signing in to Google Cloud Code Assist, which is what "Gemini CLI" means here.
 *
 * Upstream's `utils/oauth/google-gemini-cli.ts`, and the third shape of sign-in in three flows.
 * Anthropic's shows a URL and takes a paste; Copilot's shows a code and polls; this one
 * **redirects to this machine** — so `CallbackServer` listens on 8085, the browser is sent to
 * Google, and the code arrives on a socket rather than through a person.
 *
 * It is also the only one that does not finish when the tokens arrive. A Code Assist token is
 * useless without a Cloud project to spend it against, and most accounts do not have one, so
 * `project()` looks for one and provisions a free-tier one when there is none — which is an API
 * call that answers "not yet" and has to be asked again. That is what `projectId` on
 * `Credentials` is for, and why `Provider::apiKey()` encodes it alongside the token rather than
 * handing the token over on its own.
 *
 * Four things worth knowing before changing any of it:
 *
 * - **The state is checked, and a mismatch is refused.** It is the verifier, so a code that came
 *   back with a different state came from a request this process did not make. Upstream calls it
 *   a possible CSRF attack and refuses; so does this. It is the one check here that is about
 *   somebody else rather than about a mistake.
 * - **`listen()` happens before the browser is sent anywhere.** A browser that arrives before
 *   the socket exists gets a connection refused, and the sign-in is over with nothing to show.
 *   The two-step `CallbackServer` is what makes the order expressible.
 * - **There has to be a refresh token.** Google only sends one when asked — `access_type=offline`
 *   with `prompt=consent` — and a sign-in that came back without one is a sign-in that expires in
 *   an hour and cannot be renewed. Upstream refuses it and says to try again.
 * - **The email is optional and its failure is ignored.** Upstream's rule, kept: it is a label
 *   on the credential, and an account that will not answer `userinfo` is not an account that
 *   cannot use Gemini.
 *
 * Provisioning **can be given up on**, which upstream cannot: ten attempts three seconds apart
 * is half a minute of a fiber nobody can reach, and escape raises the signal that ends it.
 *
 * **Google's client id and secret are not in this repository**, which is the one real difference
 * from upstream. They are handed in, and `CodingAgent\Auth` is what finds them — the environment
 * first, then the settings file. Upstream embeds them behind `atob()`, and for a Google
 * installed-application client that is defensible: the secret is not confidential by design, it
 * is in every Gemini CLI install and in a published npm package, and PKCE is what protects the
 * exchange. It is still not something this repository can hold — see the trap in CLAUDE.md, which
 * is about what scanners do rather than about what is secret.
 */
final class GeminiCli
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string CODE_ASSIST_URL = 'https://cloudcode-pa.googleapis.com';

    public const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json';

    /** @var list<string> */
    public const array SCOPES = [
        'https://www.googleapis.com/auth/cloud-platform',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];

    /** Five minutes, which is upstream's margin: a token is treated as dead before it is. */
    private const int MARGIN_MS = 5 * 60 * 1000; // 5 minutes

    /** Upstream's ten tries, three seconds apart. Provisioning is not instant and is not slow. */
    private const int ONBOARD_ATTEMPTS = 10;

    private const float ONBOARD_DELAY = 3.0;

    /** What Code Assist wants to be told about the thing asking. */
    private const array METADATA = [
        'ideType' => 'IDE_UNSPECIFIED',
        'platform' => 'PLATFORM_UNSPECIFIED',
        'pluginType' => 'GEMINI',
    ];

    /**
     * @param string $clientId Google's client id for an installed application
     * @param string $clientSecret its secret, which is not one — see the note on the class
     * @param CallbackServer|null $server the loopback the browser comes back to, injectable
     *        because 8085 is a real port and a test must not need it free
     * @param string|null $origin every Google endpoint under this instead, so a test can point
     *        the whole flow at a loopback server — the same seam the other two flows have
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?CallbackServer $server = null,
        private readonly ?string $origin = null,
    ) {
        // Refused here rather than at the first request: a flow built without them is a flow
        // that sends somebody to a browser and then fails, and the caller is what knows where
        // to look for them.
        if ($clientId === '' || $clientSecret === '') {
            throw new OauthError('Signing in to Gemini CLI needs Google\'s client id and secret.');
        }
    }

    /** Where to send the person. */
    public function authorizeUrl(Pkce $pkce, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $pkce->verifier,
            // Both are needed for a refresh token to come back at all: offline asks for one,
            // and consent stops Google skipping the screen — and skipping it skips the token.
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * The whole thing: listen, send the browser, take the code, and find a project.
     *
     * Null means nobody finished.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string): void|null $onProgress
     */
    public function login(Closure $onAuth, ?Closure $onProgress = null, ?AbortSignal $signal = null): ?Credentials
    {
        $pkce = Pkce::create();
        $server = $this->server ?? new CallbackServer();

        // Before anything is shown, so a port that cannot be taken is reported to somebody who
        // has not yet been sent to a browser.
        $server->listen();

        try {
            $onAuth(
                $this->authorizeUrl($pkce, $server->redirectUri()),
                'Finish signing in in the browser — the answer comes back here by itself.',
            );

            $callback = $server->await($signal);

            if ($callback === null) {
                return null;
            }

            if (!hash_equals($pkce->verifier, $callback['state'])) {
                // The state is the verifier, so this code came back from a request this process
                // never made. Upstream calls it a possible CSRF attack, and refuses.
                throw new OauthError('The sign-in came back with the wrong state, so it was not the one this pig started.');
            }
        } finally {
            $server->close();
        }

        self::say($onProgress, 'Exchanging the code for tokens…');
        $tokens = $this->exchange($callback['code'], $pkce->verifier, $server->redirectUri());

        self::say($onProgress, 'Looking for a Cloud project…');
        $project = $this->project($tokens['access'], $onProgress, $signal);

        if ($project === null) {
            return null;
        }

        return new Credentials(
            $tokens['refresh'],
            $tokens['access'],
            $tokens['expires'],
            projectId: $project,
            email: $this->email($tokens['access']),
        );
    }

    /**
     * Turn the code from the callback into tokens.
     *
     * @return array{access: string, refresh: string, expires: int}
     */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $data = $this->form($this->endpoint(self::TOKEN_URL), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);

        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $lifetime = $data['expires_in'] ?? null;

        if (!is_string($access) || $access === '' || !is_int($lifetime)) {
            throw new OauthError('Google answered the token request without an access token in it.');
        }

        // Named separately from the rest, because it is the one that has a cause somebody can
        // act on: Google only sends it when the consent screen was actually shown.
        if (!is_string($refresh) || $refresh === '') {
            throw new OauthError('Google sent no refresh token, so this sign-in would expire in an hour. Try again.');
        }

        return [
            'access' => $access,
            'refresh' => $refresh,
            'expires' => Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
        ];
    }

    /**
     * A new access token from the refresh token.
     *
     * The project id is carried through rather than looked up again: it belongs to the account,
     * not to the token, and asking Code Assist for it on every renewal would be a request per
     * hour for an answer that does not change.
     */
    public function refresh(string $refreshToken, string $projectId): Credentials
    {
        $data = $this->form($this->endpoint(self::TOKEN_URL), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $access = $data['access_token'] ?? null;
        $lifetime = $data['expires_in'] ?? null;

        if (!is_string($access) || $access === '' || !is_int($lifetime)) {
            throw new OauthError('Google answered the renewal without an access token in it.');
        }

        $rotated = $data['refresh_token'] ?? null;

        return new Credentials(
            // Google usually does not rotate this one, and sends nothing rather than the same
            // value again — so a missing field means "keep the one you have", not "lost it".
            is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            $access,
            Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
            projectId: $projectId,
        );
    }

    /**
     * The Cloud project this account can spend a Code Assist token against.
     *
     * Most accounts do not have one, so this is two questions: *is there one* and, if not,
     * *make me one* — and the second answers "not yet" until it is done. Null means the waiting
     * was called off.
     *
     * @param Closure(string): void|null $onProgress
     */
    public function project(string $accessToken, ?Closure $onProgress = null, ?AbortSignal $signal = null): ?string
    {
        $loaded = $this->json('POST', $this->endpoint(self::CODE_ASSIST_URL) . '/v1internal:loadCodeAssist', [
            'metadata' => self::METADATA,
        ], $accessToken);

        $existing = $loaded['cloudaicompanionProject'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $tier = self::defaultTier($loaded['allowedTiers'] ?? null);
        self::say($onProgress, 'Provisioning a Cloud project — this takes a moment…');

        for ($attempt = 0; $attempt < self::ONBOARD_ATTEMPTS; $attempt++) {
            if ($signal?->aborted() === true) {
                return null;
            }

            $onboarded = $this->json('POST', $this->endpoint(self::CODE_ASSIST_URL) . '/v1internal:onboardUser', [
                'tierId' => $tier,
                'metadata' => self::METADATA,
            ], $accessToken);

            $id = $onboarded['response']['cloudaicompanionProject']['id'] ?? null;

            // `done` as well as an id: the call answers with a half-built project while it is
            // still working, and taking that id would name something that cannot be used yet.
            if (($onboarded['done'] ?? false) === true && is_string($id) && $id !== '') {
                return $id;
            }

            if ($attempt === self::ONBOARD_ATTEMPTS - 1) {
                break;
            }

            self::say($onProgress, 'Still provisioning (' . ($attempt + 2) . ' of ' . self::ONBOARD_ATTEMPTS . ')…');

            if (!$this->sleep(self::ONBOARD_DELAY, $signal)) {
                return null;
            }
        }

        throw new OauthError(
            'Could not find or make a Google Cloud project for this account. '
            . 'Check that it has access to Google Cloud Code Assist (Gemini CLI).',
        );
    }

    /**
     * Which address the token was issued to, if Google will say.
     *
     * Upstream ignores every failure here and so does this: it is a label on the credential, and
     * an account that will not answer this is not an account that cannot use Gemini. The one
     * place in this file where a swallowed error is the right answer, and the reason is that
     * nothing downstream reads it.
     */
    public function email(string $accessToken): ?string
    {
        try {
            $data = $this->json('GET', $this->endpoint(self::USERINFO_URL), null, $accessToken);
        } catch (Throwable) {
            return null;
        }

        $email = $data['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * The tier to ask for, which is whichever Google says is the default.
     *
     * `FREE` when it says nothing — upstream's fallback, and the tier every account has.
     */
    private static function defaultTier(mixed $tiers): string
    {
        if (!is_array($tiers) || $tiers === []) {
            return 'FREE';
        }

        foreach ($tiers as $tier) {
            if (is_array($tier) && ($tier['isDefault'] ?? false) === true && is_string($tier['id'] ?? null)) {
                return $tier['id'];
            }
        }

        $first = $tiers[array_key_first($tiers)] ?? null;

        return is_array($first) && is_string($first['id'] ?? null) ? $first['id'] : 'FREE';
    }

    /**
     * A form-encoded POST, which is what Google's token endpoint takes.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function form(string $url, array $fields): array
    {
        return $this->send(new Request(
            'POST',
            $url,
            ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'application/json'],
            http_build_query($fields),
        ), $url);
    }

    /**
     * A JSON request, with a bearer token.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, ?array $body, string $accessToken): array
    {
        $encoded = $body === null ? null : self::encode($body);

        return $this->send(new Request($method, $url, [
            'accept' => 'application/json',
            ...($encoded === null ? [] : ['content-type' => 'application/json']),
            'authorization' => "Bearer {$accessToken}",
            // Upstream's, and Code Assist is picky enough about who is asking to be worth
            // copying rather than tidying.
            'user-agent' => 'google-api-nodejs-client/9.15.1',
            'x-goog-api-client' => 'gl-node/22.17.0',
        ], $encoded), $url);
    }

    /** @return array<string, mixed> */
    private function send(Request $request, string $url): array
    {
        $response = $this->http->send($request);
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            throw new OauthError("Google answered {$response->status} to {$url}: {$text}");
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            throw new OauthError("Google answered {$url} with something that is not JSON.");
        }

        return $data;
    }

    /** With `$origin` set, the path of every Google endpoint moves under it. */
    private function endpoint(string $url): string
    {
        if ($this->origin === null) {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim($this->origin, '/') . $path . (is_string($query) ? '?' . $query : '');
    }

    /** @param Closure(string): void|null $onProgress */
    private static function say(?Closure $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    /**
     * Wait, unless something says not to. False means it was cut short.
     *
     * The timer is cancelled on an abort rather than left to fire into nothing: a pending timer
     * keeps `Loop::isIdle()` false, and `bin/pig` would then not exit. The same shape
     * `GithubCopilot::sleep()` and `AgentSession::sleep()` use.
     */
    private function sleep(float $seconds, ?AbortSignal $signal): bool
    {
        if ($signal?->aborted() === true) {
            return false;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });

        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            return $done->future->await() === true;
        } finally {
            if ($signal !== null && $listener !== null) {
                $signal->removeListener($listener);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $problem) {
            throw new OauthError('Could not encode the request to Google.', 0, $problem);
        }
    }
}
