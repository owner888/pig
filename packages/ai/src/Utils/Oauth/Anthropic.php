<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Throwable;

/**
 * Signing in with a Claude Pro or Max subscription.
 *
 * Upstream's `utils/oauth/anthropic.ts`. It is the one OAuth flow here that needs nothing but
 * a URL and a paste — no browser to drive, no loopback server to listen on, no port to hope is
 * free. `authorizeUrl()` is shown to the person, they open it, approve, and are handed a
 * `code#state` string to bring back; `exchange()` turns that into tokens. That is the whole
 * reason it is the first one ported: everything it needs, pig already had.
 *
 * `Providers\Anthropic` was already written for the far end of this — an `sk-ant-oat` key goes
 * out as a bearer token under the `oauth-2025-04-20` beta, with Claude Code's identity as the
 * first system block, because that is the identity the token is issued to. So what was missing
 * was only the part that *obtains* one.
 *
 * The URLs and the client id are Anthropic's, as upstream has them. Upstream hides the id
 * behind `atob()` of a base64 string; it is written out here, because an id that is in a
 * published npm package and in every request this makes is not a secret, and a reader who
 * cannot see what it is has to go and decode it to find out.
 */
final class Anthropic
{
    public const string AUTHORIZE_URL = 'https://claude.ai/oauth/authorize';

    public const string REDIRECT_URI = 'https://console.anthropic.com/oauth/code/callback';

    public const string TOKEN_URL = 'https://console.anthropic.com/v1/oauth/token';

    public const string SCOPES = 'org:create_api_key user:profile user:inference';

    public const string CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    /** Five minutes, which is upstream's margin: a token is treated as dead before it is. */
    private const int MARGIN_MS = 5 * 60 * 1000; // 5 minutes

    /**
     * @param string $tokenUrl the endpoint, so a test can point it at a loopback server. Not a
     *        seam that exists for the test alone — every provider in this package takes its
     *        base URL from outside for the same reason, and an auth flow with no coverage at
     *        all is the alternative.
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $tokenUrl = self::TOKEN_URL,
    ) {
    }

    /**
     * Where to send the person.
     *
     * `state` is the verifier itself rather than a second random string. That is upstream's
     * choice and Anthropic's flow: the callback hands back `code#state`, so the state travels
     * home through the person's clipboard and `exchange()` can check that the code it is
     * redeeming came from the request it made.
     */
    public static function authorizeUrl(Pkce $pkce): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'code' => 'true',
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPES,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $pkce->verifier,
        ]);
    }

    /**
     * Turn what was pasted back into tokens.
     *
     * @param string $pasted the `code#state` from the callback page, whitespace and all
     * @param string $verifier the one `authorizeUrl()` was built from
     */
    public function exchange(string $pasted, string $verifier): Credentials
    {
        $parts = explode('#', trim($pasted));

        // One deliberate difference from upstream, which sends `state: undefined` and lets
        // Anthropic answer `invalid_grant`. The callback always hands over both halves, so a
        // string with no `#` in it is a paste that lost its end — and "the code you pasted is
        // incomplete" is an answer somebody can act on, where the server's is not.
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            throw new OauthError('That is not a whole authorization code — paste all of it, including the part after the #.');
        }

        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $parts[0],
            'state' => $parts[1],
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ]);
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
