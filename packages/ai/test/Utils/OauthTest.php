<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;

/**
 * Signing in with a subscription rather than an API key.
 *
 * The token endpoint is a loopback server here, so what goes out is asserted rather than
 * guessed — which is the only part of an auth flow that can be checked without an account.
 * Nothing in this file reaches Anthropic.
 */
final class OauthTest extends TestCase
{
    use AssertsThrows;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    /** @param array<string, mixed>|string $body */
    private function serve(array|string $body, int $status = 200, string $reason = 'OK'): string
    {
        $payload = is_string($body) ? $body : (string) json_encode($body);

        return $this->server->start([
            "HTTP/1.1 {$status} {$reason}\r\n"
            . "content-type: application/json\r\n"
            . 'content-length: ' . strlen($payload) . "\r\n"
            . "connection: close\r\n\r\n"
            . $payload,
        ]);
    }

    private function run(callable $work): mixed
    {
        return Async::run($work);
    }

    // ---- PKCE ---------------------------------------------------------------------------

    public function testTheVerifierAndChallengeAreBase64urlWithNoPadding(): void
    {
        $pkce = Pkce::create();

        // A `+`, a `/` or an `=` in either would be a different string by the time it has
        // been through a query parameter, which is the whole point of the alphabet.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $pkce->verifier);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $pkce->challenge);
    }

    public function testTheChallengeIsTheVerifiersSha256(): void
    {
        $pkce = Pkce::create();

        // Computed here rather than asked for, so this is two implementations agreeing.
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce->verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $pkce->challenge);
    }

    public function testTwoPairsAreNotTheSamePair(): void
    {
        $this->assertNotSame(Pkce::create()->verifier, Pkce::create()->verifier);
    }

    // ---- the URL somebody opens ---------------------------------------------------------

    public function testTheAuthorizeUrlCarriesTheChallengeAndTheVerifierAsState(): void
    {
        $pkce = Pkce::create();
        $url = Anthropic::authorizeUrl($pkce);

        $this->assertStringStartsWith(Anthropic::AUTHORIZE_URL . '?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($pkce->challenge, $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
        // The state is the verifier itself: it travels home through the clipboard, which is
        // how `exchange()` can tell the code came from the request it made.
        $this->assertSame($pkce->verifier, $query['state']);
        $this->assertSame(Anthropic::CLIENT_ID, $query['client_id']);
        $this->assertSame(Anthropic::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame(Anthropic::SCOPES, $query['scope']);
        $this->assertSame('code', $query['response_type']);
    }

    // ---- redeeming what was pasted ------------------------------------------------------

    public function testTheCodeAndStateGoOutWithTheVerifier(): void
    {
        $url = $this->serve(['access_token' => 'sk-ant-oat-x', 'refresh_token' => 'r1', 'expires_in' => 3600]);

        $credentials = $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))
            ->exchange('the-code#the-state', 'the-verifier'));

        $sent = $this->server->receivedJson();

        $this->assertSame('authorization_code', $sent['grant_type']);
        $this->assertSame('the-code', $sent['code']);
        $this->assertSame('the-state', $sent['state']);
        $this->assertSame('the-verifier', $sent['code_verifier']);
        $this->assertSame(Anthropic::REDIRECT_URI, $sent['redirect_uri']);

        $this->assertSame('sk-ant-oat-x', $credentials->access);
        $this->assertSame('r1', $credentials->refresh);
    }

    public function testWhitespaceAroundThePasteIsNotPartOfTheCode(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->exchange("  c#s\n", 'v'));

        // A code pasted out of a terminal arrives with a newline on it about half the time,
        // and a trailing newline inside the code is a 400 that says `invalid_grant`.
        $this->assertSame('c', $this->server->receivedJson()['code']);
        $this->assertSame('s', $this->server->receivedJson()['state']);
    }

    public function testHalfAPasteIsRefusedBeforeAnythingIsSent(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))
                ->exchange('just-the-code', 'v')),
        );

        $this->assertStringContainsString('paste all of it', $problem->getMessage());
        // Nothing went out: the mistake is on this side, and the server's own answer to it
        // would be `invalid_grant`, which describes six different mistakes equally badly.
        $this->assertSame('', $this->server->received());
    }

    public function testTheExpiryHasTheSafetyMarginTakenOffIt(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);

        $before = (int) (microtime(true) * 1000);
        $credentials = $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->exchange('c#s', 'v'));

        // An hour, less the five minutes upstream subtracts. A token that expires in flight
        // fails the request it was attached to rather than the next one.
        $this->assertGreaterThan($before + 3600_000 - 5 * 60_000 - 5_000, $credentials->expires);
        $this->assertLessThan($before + 3600_000 - 5 * 60_000 + 5_000, $credentials->expires);
    }

    // ---- renewing -----------------------------------------------------------------------

    public function testRefreshingSendsTheRefreshTokenAndNothingElseAboutTheSession(): void
    {
        $url = $this->serve(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 60]);

        $credentials = $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1'));

        $sent = $this->server->receivedJson();

        $this->assertSame('refresh_token', $sent['grant_type']);
        $this->assertSame('r1', $sent['refresh_token']);
        $this->assertArrayNotHasKey('code', $sent);
        $this->assertArrayNotHasKey('code_verifier', $sent);

        // The new refresh token replaces the old one: Anthropic rotates them, so keeping the
        // one that was sent would work exactly once more.
        $this->assertSame('r2', $credentials->refresh);
        $this->assertSame('a2', $credentials->access);
    }

    public function testARefusalCarriesWhatTheServerSaid(): void
    {
        $url = $this->serve(['error' => 'invalid_grant'], 400, 'Bad Request');

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
        );

        $this->assertStringContainsString('400', $problem->getMessage());
        // The body verbatim. It is the only part that says which of several identical-looking
        // failures this was.
        $this->assertStringContainsString('invalid_grant', $problem->getMessage());
    }

    public function testAnAnswerWithoutTokensInItIsRefusedRatherThanHalfBuilt(): void
    {
        $url = $this->serve(['access_token' => 'a', 'expires_in' => 60]);

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
        );

        // A credential built from half an answer is a session that fails later, elsewhere,
        // once — which is the hardest kind of thing to find.
        $this->assertStringContainsString('without the tokens', $problem->getMessage());
    }

    public function testSomethingThatIsNotJsonIsRefused(): void
    {
        $url = $this->serve('<html>gateway timeout</html>');

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
        );

        $this->assertStringContainsString('not JSON', $problem->getMessage());
    }

    // ---- the credential and the provider ------------------------------------------------

    public function testACredentialKnowsWhenItIsDone(): void
    {
        $credentials = new Credentials('r', 'a', 1_000);

        $this->assertFalse($credentials->hasExpired(999));
        // `>=`, as upstream has it: the moment it is due is already too late.
        $this->assertTrue($credentials->hasExpired(1_000));
        $this->assertTrue($credentials->hasExpired(1_001));
    }

    public function testOnlyAnthropicIsOfferedAsSomethingYouCanSignInWith(): void
    {
        $this->assertTrue(Provider::Anthropic->available());

        foreach ([Provider::GithubCopilot, Provider::GoogleGeminiCli, Provider::GoogleAntigravity] as $provider) {
            // Named so a credentials file written by pi can be read, and not offered, because
            // offering a sign-in that cannot finish is worse than not having it.
            $this->assertFalse($provider->available(), "{$provider->value} is not ported");
        }
    }

    public function testEveryProviderHasAName(): void
    {
        foreach (Provider::cases() as $provider) {
            $this->assertNotSame('', $provider->label());
        }
    }

    public function testAnthropicsKeyIsTheAccessTokenTheProviderRecognises(): void
    {
        $key = Provider::Anthropic->apiKey(new Credentials('r', 'sk-ant-oat01-xyz', 0));

        $this->assertSame('sk-ant-oat01-xyz', $key);
        // The prefix is what `Providers\Anthropic` reads to decide between a bearer token and
        // `x-api-key`, so this is the join between signing in and sending a request.
        $this->assertStringContainsString('sk-ant-oat', $key);
    }

    public function testAProviderThatIsNotPortedSaysSoRatherThanFailingLater(): void
    {
        $credentials = new Credentials('r', 'a', 0);

        $this->assertThrows(
            OauthError::class,
            static fn (): Credentials => Provider::GoogleGeminiCli->refresh($credentials),
        );

        $this->assertThrows(
            OauthError::class,
            static fn (): string => Provider::GithubCopilot->apiKey($credentials),
        );
    }

    public function testCredentialsWithNoRefreshTokenAreRefusedBeforeARequest(): void
    {
        $problem = $this->assertThrows(
            OauthError::class,
            static fn (): Credentials => Provider::Anthropic->refresh(new Credentials('', 'a', 0)),
        );

        $this->assertStringContainsString('sign in again', $problem->getMessage());
    }
}
