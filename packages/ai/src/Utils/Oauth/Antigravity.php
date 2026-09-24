<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Signing in to Antigravity, which is Google's sandbox deployment of Code Assist.
 *
 * Upstream's `utils/oauth/google-antigravity.ts`. Same protocol as `GeminiCli` and a different
 * everything else: its own OAuth client, five scopes instead of three, port 51121 instead of
 * 8085, and a sandbox endpoint whose `User-Agent` check is load-bearing. What it buys is the
 * seven models in `Models::ANTIGRAVITY_MODELS` — two of Anthropic's, three Geminis and an
 * open-weights one, none of which Gemini CLI offers.
 *
 * **A separate class rather than a base one shared with `GeminiCli`.** Upstream has two files
 * and so does this. The overlap is real — both are Google's PKCE loopback flow — but the two
 * differ in the part that matters most, which is what happens *after* the tokens arrive:
 * `GeminiCli` provisions a Cloud project and waits for it; this one asks two endpoints and
 * gives up into a constant. A shared parent would have to hold both shapes, and the seam would
 * fall exactly where the two are least alike.
 *
 * **The client id and secret are not in this repository**, encoded or not. Upstream keeps them
 * base64'd, which is not protection and is why pushing them here was blocked twice: the
 * scanners decode. `Auth::antigravityClient()` reads them from the environment or the settings
 * and refuses clearly when there are none. See the trap entry on credentials.
 */
final class Antigravity
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json';

    /**
     * Where the browser is sent back to.
     *
     * 51121 and this path are **registered with Google against that client id**, so neither is
     * a preference: a redirect Google has not been told about is refused before the person ever
     * sees a consent screen.
     */
    public const int PORT = 51121;

    public const string CALLBACK_PATH = '/oauth-callback';

    /**
     * Five, where Gemini CLI asks for three.
     *
     * `cclog` and `experimentsandconfigs` are the two extra, and they are what the sandbox
     * deployment checks for — a token minted with Gemini CLI's scopes reaches the same endpoint
     * and is refused there.
     */
    public const array SCOPES = [
        'https://www.googleapis.com/auth/cloud-platform',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/cclog',
        'https://www.googleapis.com/auth/experimentsandconfigs',
    ];

    /**
     * Where a project is looked for, in this order: production first, then the sandbox.
     *
     * Upstream's order, which looks backwards for a sandbox-only provider and is not: a person
     * who already has a Code Assist project should spend against that one.
     */
    public const array DISCOVERY_ENDPOINTS = [
        'https://cloudcode-pa.googleapis.com',
        'https://daily-cloudcode-pa.sandbox.googleapis.com',
    ];

    /**
     * The project used when neither endpoint names one.
     *
     * Upstream's constant, kept because without it a sign-in that otherwise worked ends with
     * nothing to spend the token against. It is **somebody else's Google Cloud project** — not a
     * secret, and not pig's either; it is what Antigravity itself falls back to. Worth knowing
     * rather than discovering: requests that land on it are attributed to it.
     */
    public const string FALLBACK_PROJECT = 'rising-fact-p41fc';

    /** Renew this long before the token actually expires. Upstream's five minutes. */
    private const int MARGIN_MS = 5 * 60 * 1000;

    /** Who the discovery call says it is. Not the same set the streaming endpoint wants. */
    private const array DISCOVERY_HEADERS = [
        'user-agent' => 'google-api-nodejs-client/9.15.1',
        'x-goog-api-client' => 'google-cloud-sdk vscode_cloudshelleditor/0.1',
        'client-metadata' => '{"ideType":"IDE_UNSPECIFIED","platform":"PLATFORM_UNSPECIFIED","pluginType":"GEMINI"}',
    ];

    /** @var array<string, mixed> */
    private const array METADATA = [
        'ideType' => 'IDE_UNSPECIFIED',
        'platform' => 'PLATFORM_UNSPECIFIED',
        'pluginType' => 'GEMINI',
    ];

    /**
     * @param CallbackServer|null $server injectable because 51121 is a real port and a test
     *        must not need it free
     * @param string|null $origin every Google endpoint under this instead, so a test can point
     *        the whole flow at a loopback server — the same seam the other three flows have
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?CallbackServer $server = null,
        private readonly ?string $origin = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new OauthError('Signing in to Antigravity needs its own client id and secret.');
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
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * Listen, send the browser, take the code, find a project. Null means nobody finished.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string): void|null $onProgress
     */
    public function login(Closure $onAuth, ?Closure $onProgress = null, ?AbortSignal $signal = null): ?Credentials
    {
        $pkce = Pkce::create();
        $server = $this->server ?? new CallbackServer(self::PORT, self::CALLBACK_PATH);

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
                throw new OauthError('The sign-in came back with the wrong state, so it was not the one this pig started.');
            }
        } finally {
            $server->close();
        }

        self::say($onProgress, 'Exchanging the code for tokens…');
        $tokens = $this->exchange($callback['code'], $pkce->verifier, $server->redirectUri());

        self::say($onProgress, 'Looking for a Cloud project…');

        return new Credentials(
            $tokens['refresh'],
            $tokens['access'],
            $tokens['expires'],
            projectId: $this->project($tokens['access'], $onProgress),
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
            throw new OauthError('Google answered the exchange without an access token in it.');
        }

        // Upstream refuses here too, and the reason is worth keeping: without a refresh token
        // this sign-in lasts an hour and then silently is not one. It happens when Google skips
        // the consent screen, which `prompt=consent` is there to stop.
        if (!is_string($refresh) || $refresh === '') {
            throw new OauthError('Google sent no refresh token, so this sign-in would last an hour. Try again.');
        }

        return [
            'access' => $access,
            'refresh' => $refresh,
            'expires' => Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
        ];
    }

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
            // A missing field means "keep the one you have", not "lost it" — Google usually does
            // not rotate this one and sends nothing rather than the same value again.
            is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            $access,
            Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
            projectId: $projectId,
        );
    }

    /**
     * The Cloud project to spend the token against.
     *
     * **Nothing is provisioned and nothing is waited for**, which is the one real difference
     * from `GeminiCli::project()`: two endpoints are asked, and if neither names a project the
     * constant is used. Upstream's shape, and the reason it can be this simple is that
     * Antigravity's sandbox does not require the caller's own project the way Code Assist
     * proper does.
     *
     * Every failure here is swallowed on purpose — a 404, a 403, a connection that never
     * opened. The fallback is the answer to all of them, and an error would be an error about
     * the *first* endpoint on an account that was always going to use the second.
     *
     * @param Closure(string): void|null $onProgress
     */
    public function project(string $accessToken, ?Closure $onProgress = null): string
    {
        foreach (self::DISCOVERY_ENDPOINTS as $endpoint) {
            try {
                $loaded = $this->json(
                    'POST',
                    $this->endpoint($endpoint) . '/v1internal:loadCodeAssist',
                    ['metadata' => self::METADATA],
                    $accessToken,
                );
            } catch (Throwable) {
                continue;
            }

            $project = $loaded['cloudaicompanionProject'] ?? null;

            // A string on one endpoint and an object with an `id` on the other, which is
            // Google's inconsistency rather than a guess about the shape.
            if (is_string($project) && $project !== '') {
                return $project;
            }

            if (is_array($project) && is_string($project['id'] ?? null) && $project['id'] !== '') {
                return $project['id'];
            }
        }

        self::say($onProgress, 'No project of your own — using Antigravity\'s default.');

        return self::FALLBACK_PROJECT;
    }

    /**
     * Which address the token was issued to, if Google will say.
     *
     * A label on the credential. Nothing downstream reads it, which is why every failure is
     * swallowed — the same call and the same reasoning as `GeminiCli::email()`.
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
            ...self::DISCOVERY_HEADERS,
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

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new OauthError('Could not encode the request: ' . $error->getMessage());
        }
    }
}
