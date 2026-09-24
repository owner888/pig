<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\DeviceCode;
use Pig\Ai\Utils\Oauth\Antigravity;
use Pig\Ai\Utils\Oauth\GeminiCli;
use Pig\Ai\Utils\Oauth\GithubCopilot;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\AbortController;
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

    public function testEveryFlowIsOfferedNowThatEveryFlowIsHere(): void
    {
        // All four. `available()` stays because the reason it exists has not changed: offering a
        // sign-in that cannot finish is worse than not having it, and a fifth provider ported
        // halfway needs somewhere to say so. Antigravity was the last one it answered false for.
        foreach (Provider::cases() as $provider) {
            $this->assertTrue($provider->available(), $provider->value);
        }
    }

    public function testEveryProviderHasAName(): void
    {
        foreach (Provider::cases() as $provider) {
            $this->assertNotSame('', $provider->label());
        }
    }

    public function testCopilotsKeyIsTheShortLivedHalfToo(): void
    {
        // The GitHub token is what lasts and the Copilot token is what a request carries, so
        // reading `refresh` here would send the wrong one and fail as an auth error.
        $this->assertSame(
            'tid=x;proxy-ep=proxy.individual.githubcopilot.com',
            Provider::GithubCopilot->apiKey(new Credentials('gho_abc', 'tid=x;proxy-ep=proxy.individual.githubcopilot.com', 0)),
        );
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
            'client id and secret',
        );

        $this->assertThrows(
            OauthError::class,
            static fn (): string => Provider::GoogleAntigravity->apiKey($credentials),
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

    // ---- GitHub Copilot: what somebody typed ---------------------------------------------

    public function testADomainIsTakenOutOfWhateverWasTyped(): void
    {
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('company.ghe.com'));
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('https://company.ghe.com'));
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('https://company.ghe.com/some/path'));
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('  company.ghe.com  '));
    }

    public function testSomethingThatIsNotADomainIsNull(): void
    {
        $this->assertNull(GithubCopilot::normalizeDomain(''));
        $this->assertNull(GithubCopilot::normalizeDomain('   '));
        $this->assertNull(GithubCopilot::normalizeDomain('???'));
    }

    // ---- GitHub Copilot: where it answers ------------------------------------------------

    public function testTheTokenSaysWhereCopilotAnswers(): void
    {
        $token = 'tid=abc;exp=123;proxy-ep=proxy.business.githubcopilot.com;st=dotcom';

        // `proxy.` becomes `api.` — the same host with a different prefix, which is upstream's
        // substitution and not a guess. A business or enterprise account says something other
        // than `individual`, and sending to the registry's default would be a 404.
        $this->assertSame('https://api.business.githubcopilot.com', GithubCopilot::baseUrl($token));
    }

    public function testWithNoTokenAnEnterpriseDomainDecides(): void
    {
        $this->assertSame('https://copilot-api.company.ghe.com', GithubCopilot::baseUrl(null, 'company.ghe.com'));
        $this->assertSame(GithubCopilot::DEFAULT_BASE_URL, GithubCopilot::baseUrl(null, null));
        // A token whose claims say nothing falls through to the same two answers.
        $this->assertSame(GithubCopilot::DEFAULT_BASE_URL, GithubCopilot::baseUrl('nothing-useful-here'));
    }

    // ---- GitHub Copilot: the device flow -------------------------------------------------

    private function copilot(string $url): GithubCopilot
    {
        return new GithubCopilot(new HttpClient(), $url);
    }

    public function testAskingForTheCodesSendsTheClientIdAndTheScope(): void
    {
        $url = $this->serve([
            'device_code' => 'dev-1',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://github.com/login/device',
            'interval' => 5,
            'expires_in' => 900,
        ]);

        $device = $this->run(fn (): DeviceCode => $this->copilot($url)->start('github.com'));

        $sent = $this->server->receivedJson();

        $this->assertSame(GithubCopilot::CLIENT_ID, $sent['client_id']);
        $this->assertSame('read:user', $sent['scope']);

        $this->assertSame('dev-1', $device->deviceCode);
        $this->assertSame('ABCD-1234', $device->userCode);
        $this->assertSame(5, $device->interval);
    }

    public function testAnAnswerMissingOneOfTheCodesIsRefused(): void
    {
        // A device flow without a `user_code` is a sign-in nobody can complete, and the
        // mistake belongs where it happened rather than three requests later.
        $url = $this->serve(['device_code' => 'dev-1', 'verification_uri' => 'x', 'interval' => 5, 'expires_in' => 900]);

        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): DeviceCode => $this->copilot($url)->start()),
            'without the codes',
        );
    }

    public function testAnApprovedCodeComesBackAsAGitHubToken(): void
    {
        $url = $this->serve(['access_token' => 'gho_abc']);

        $token = $this->run(fn (): ?string => $this->copilot($url)
            ->poll('github.com', new DeviceCode('dev-1', 'ABCD', 'https://x', 1, 900)));

        $this->assertSame('gho_abc', $token);

        $sent = $this->server->receivedJson();

        $this->assertSame('dev-1', $sent['device_code']);
        $this->assertSame('urn:ietf:params:oauth:grant-type:device_code', $sent['grant_type']);
    }

    public function testWaitingIsNotAnErrorAndTheCodeCanExpireWhileItGoesOn(): void
    {
        $url = $this->serve(['error' => 'authorization_pending']);

        // `authorization_pending` means ask again, so the loop sleeps and comes back; one
        // second of life on the code means the deadline is what ends it.
        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): ?string => $this->copilot($url)
                ->poll('github.com', new DeviceCode('dev-1', 'ABCD', 'https://x', 1, 1))),
        );

        $this->assertStringContainsString('expired before it was entered', $problem->getMessage());
    }

    public function testARefusalStopsTheWaitingAtOnce(): void
    {
        $url = $this->serve(['error' => 'access_denied']);

        // Unlike `authorization_pending`, asking again says the same thing — so a fifteen
        // minute wait for an answer that has already arrived is the wrong behaviour.
        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): ?string => $this->copilot($url)
                ->poll('github.com', new DeviceCode('dev-1', 'ABCD', 'https://x', 1, 900))),
        );

        $this->assertStringContainsString('access_denied', $problem->getMessage());
    }

    public function testTheWaitingCanBeCalledOff(): void
    {
        $url = $this->serve(['error' => 'authorization_pending']);

        $token = $this->run(function () use ($url): ?string {
            $controller = new AbortController();

            Loop::get()->delay(0.05, static fn () => $controller->abort('Cancelled'));

            // The addition to upstream, and the reason this flow waited for a decision: it
            // loops for fifteen minutes with nothing able to interrupt it, which in pig is a
            // fiber parked where nobody can reach it.
            return $this->copilot($url)->poll(
                'github.com',
                new DeviceCode('dev-1', 'ABCD', 'https://x', 1, 900),
                $controller->signal,
            );
        });

        // Null and not an exception: somebody changing their mind is an outcome.
        $this->assertNull($token);
    }

    // ---- GitHub Copilot: the token swap --------------------------------------------------

    public function testTheGitHubTokenIsTradedForACopilotOne(): void
    {
        $expires = (int) (microtime(true)) + 3600;
        $url = $this->serve(['token' => 'tid=x;proxy-ep=proxy.individual.githubcopilot.com', 'expires_at' => $expires]);

        $credentials = $this->run(fn (): Credentials => $this->copilot($url)->refresh('gho_abc'));

        $head = $this->server->receivedHead();

        $this->assertStringContainsString('authorization: Bearer gho_abc', $head);
        // The endpoint is VS Code's and answers a request that does not claim to be VS Code
        // with a 4xx.
        // Lowercased on the wire by `HttpClient`, which is what makes a header name
        // case-insensitive in practice as well as in the spec.
        $this->assertStringContainsString('copilot-integration-id: vscode-chat', $head);

        // The GitHub token is the lasting half and the Copilot one is what goes on a request,
        // which is exactly what `refresh` and `access` mean everywhere else here.
        $this->assertSame('gho_abc', $credentials->refresh);
        $this->assertStringContainsString('proxy-ep=', $credentials->access);
        $this->assertSame($expires * 1000 - 5 * 60_000, $credentials->expires);
    }

    public function testAnEnterpriseSignInRemembersWhichGitHubItWas(): void
    {
        $url = $this->serve(['token' => 'tid=x', 'expires_at' => 1_800_000_000]);

        $credentials = $this->run(fn (): Credentials => $this->copilot($url)->refresh('gho_abc', 'company.ghe.com'));

        // Kept, because renewing has to go back to the same GitHub and nothing else in the
        // file says which one it was.
        $this->assertSame('company.ghe.com', $credentials->enterpriseUrl);
    }

    public function testACopilotTokenAnswerWithoutATokenIsRefused(): void
    {
        $url = $this->serve(['expires_at' => 1_800_000_000]);

        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): Credentials => $this->copilot($url)->refresh('gho_abc')),
            'without a token',
        );
    }

    // ---- GitHub Copilot: switching the models on -----------------------------------------

    public function testAModelTheAccountCannotHaveDoesNotStopTheRest(): void
    {
        $url = $this->serve(['message' => 'no'], 403, 'Forbidden');
        $seen = [];

        $this->run(function () use ($url, &$seen): void {
            $this->copilot($url)->enableModels(
                'tok',
                ['claude-sonnet-4.5', 'grok-code-fast-1'],
                null,
                static function (string $id, bool $enabled) use (&$seen): void {
                    $seen[$id] = $enabled;
                },
            );
        });

        // The question asked was "may this account use that model", and "no" is an answer
        // rather than a failure of a sign-in that is already finished.
        $this->assertSame(['claude-sonnet-4.5' => false, 'grok-code-fast-1' => false], $seen);
    }

    // ---- Gemini CLI: the URL somebody opens ----------------------------------------------

    /** Made-up credentials: this repository does not hold Google's, and does not need to. */
    private function gemini(string $url, ?CallbackServer $server = null): GeminiCli
    {
        return new GeminiCli('test-client-id', 'test-client-secret', new HttpClient(), $server, $url);
    }

    public function testAFlowWithNoClientCredentialsIsRefusedAtOnce(): void
    {
        // At construction, not at the first request: a flow built without them would send
        // somebody to a browser and then fail, and the caller is what knows where to look.
        $this->assertThrows(
            OauthError::class,
            static fn (): GeminiCli => new GeminiCli('', ''),
            'client id and secret',
        );
    }

    public function testTheGoogleUrlAsksForARefreshTokenAndMeansIt(): void
    {
        $pkce = Pkce::create();
        $url = $this->gemini('http://unused')->authorizeUrl($pkce, 'http://localhost:8085/oauth2callback');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($pkce->challenge, $query['code_challenge']);
        $this->assertSame($pkce->verifier, $query['state']);
        $this->assertSame('http://localhost:8085/oauth2callback', $query['redirect_uri']);
        // Both, and both are load-bearing: offline asks for a refresh token and consent stops
        // Google skipping the screen — and skipping the screen skips the token.
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('test-client-id', $query['client_id']);
        // Space-separated, which is what OAuth says and what `http_build_query` encodes as `+`.
        $this->assertStringContainsString('auth/cloud-platform', $query['scope']);
        $this->assertStringContainsString(' ', $query['scope']);
    }

    // ---- Gemini CLI: the exchange ---------------------------------------------------------

    public function testTheCodeIsExchangedAsAForm(): void
    {
        $url = $this->serve(['access_token' => 'ya29.a', 'refresh_token' => '1//r', 'expires_in' => 3599]);

        $tokens = $this->run(fn (): array => $this->gemini($url)
            ->exchange('the-code', 'the-verifier', 'http://localhost:8085/oauth2callback'));

        $sent = $this->server->received();

        // Form-encoded, not JSON: Google's token endpoint takes the other one.
        $this->assertStringContainsString('content-type: application/x-www-form-urlencoded', $sent);
        $this->assertStringContainsString('grant_type=authorization_code', $sent);
        $this->assertStringContainsString('code=the-code', $sent);
        $this->assertStringContainsString('code_verifier=the-verifier', $sent);
        // Handed in rather than held here: Google's renewal and exchange both carry them, and
        // this repository does not ship them.
        $this->assertStringContainsString('client_id=test-client-id', $sent);
        $this->assertStringContainsString('client_secret=test-client-secret', $sent);

        $this->assertSame('ya29.a', $tokens['access']);
        $this->assertSame('1//r', $tokens['refresh']);
    }

    public function testNoRefreshTokenIsItsOwnComplaint(): void
    {
        $url = $this->serve(['access_token' => 'ya29.a', 'expires_in' => 3599]);

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): array => $this->gemini($url)->exchange('c', 'v', 'http://x/cb')),
        );

        // Named separately from a missing access token because it has a cause somebody can act
        // on: Google only sends it when the consent screen was actually shown.
        $this->assertStringContainsString('expire in an hour', $problem->getMessage());
    }

    public function testRenewingKeepsTheRefreshTokenGoogleDidNotResend(): void
    {
        $url = $this->serve(['access_token' => 'ya29.b', 'expires_in' => 3599]);

        $credentials = $this->run(fn (): Credentials => $this->gemini($url)->refresh('1//keep-me', 'proj-1'));

        $this->assertStringContainsString('grant_type=refresh_token', $this->server->received());

        // Google usually does not rotate this one and sends nothing rather than the same value
        // again, so a missing field means "keep the one you have" and not "lost it".
        $this->assertSame('1//keep-me', $credentials->refresh);
        $this->assertSame('ya29.b', $credentials->access);
        // Carried through rather than looked up again: it belongs to the account, not the token.
        $this->assertSame('proj-1', $credentials->projectId);
    }

    // ---- Gemini CLI: the Cloud project ----------------------------------------------------

    public function testAnAccountThatAlreadyHasAProjectIsNotOnboarded(): void
    {
        $url = $this->serve(['cloudaicompanionProject' => 'existing-project']);

        $project = $this->run(fn (): ?string => $this->gemini($url)->project('ya29.a'));

        $this->assertSame('existing-project', $project);
        // One request: asking to be onboarded when there is already a project is how you end up
        // with two.
        $this->assertStringContainsString('loadCodeAssist', $this->server->received());
        $this->assertStringNotContainsString('onboardUser', $this->server->received());
    }

    public function testAnAccountWithNoProjectIsOnboardedIntoOne(): void
    {
        // No `cloudaicompanionProject` at the top, so the same answer serves as the onboarding
        // reply: done, with an id.
        $url = $this->serve([
            'allowedTiers' => [['id' => 'LEGACY'], ['id' => 'FREE', 'isDefault' => true]],
            'done' => true,
            'response' => ['cloudaicompanionProject' => ['id' => 'made-one']],
        ]);

        $project = $this->run(fn (): ?string => $this->gemini($url)->project('ya29.a'));

        $this->assertSame('made-one', $project);
        // The tier Google marked default, not the first one in the list.
        $this->assertStringContainsString('"tierId":"FREE"', $this->server->received());
    }

    public function testAHalfBuiltProjectIsNotTakenAsFinished(): void
    {
        // An id but no `done`: the call answers with a project that is still being made, and
        // taking that id would name something nothing can be spent against yet. Two attempts
        // in, the wait is what this ends on.
        $url = $this->serve(['response' => ['cloudaicompanionProject' => ['id' => 'not-yet']]]);

        $project = $this->run(function () use ($url): ?string {
            $controller = new AbortController();
            Loop::get()->delay(0.05, static fn () => $controller->abort('Cancelled'));

            return $this->gemini($url)->project('ya29.a', null, $controller->signal);
        });

        // Null and not `not-yet`: giving up is an outcome, and a wrong id is not.
        $this->assertNull($project);
    }

    public function testProvisioningSaysWhatItIsDoing(): void
    {
        $url = $this->serve(['response' => ['cloudaicompanionProject' => ['id' => 'x']]]);
        $said = [];

        $this->run(function () use ($url, &$said): void {
            $controller = new AbortController();
            Loop::get()->delay(0.05, static fn () => $controller->abort('Cancelled'));

            $this->gemini($url)->project(
                'ya29.a',
                static function (string $note) use (&$said): void {
                    $said[] = $note;
                },
                $controller->signal,
            );
        });

        // Half a minute of silence is how a person concludes it has hung.
        $this->assertNotSame([], $said);
        $this->assertStringContainsString('Provisioning', $said[0]);
    }

    // ---- Gemini CLI: the email ------------------------------------------------------------

    public function testTheEmailIsReadWhenGoogleWillSayIt(): void
    {
        $url = $this->serve(['email' => 'me@example.com']);

        $this->assertSame('me@example.com', $this->run(fn (): ?string => $this->gemini($url)->email('ya29.a')));
    }

    public function testAnEmailGoogleWillNotSayIsNotAFailure(): void
    {
        $url = $this->serve(['error' => 'nope'], 403, 'Forbidden');

        // Upstream ignores every failure here and so does this: it is a label on the credential,
        // and an account that will not answer this is not one that cannot use Gemini.
        $this->assertNull($this->run(fn (): ?string => $this->gemini($url)->email('ya29.a')));
    }

    // ---- Gemini CLI: the state check ------------------------------------------------------

    public function testACodeThatCameBackWithTheWrongStateIsRefused(): void
    {
        $port = self::freePort();
        $server = new CallbackServer($port);
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $problem = $this->assertThrows(OauthError::class, function () use ($url, $server, $port): void {
            $this->run(function () use ($url, $server, $port): void {
                Loop::get()->defer(function () use ($port): void {
                    self::pretendBrowser($port, '/oauth2callback?code=c&state=not-the-verifier');
                });

                $this->gemini($url, $server)->login(static function (string $u, ?string $i): void {
                });
            });
        });

        // The state is the verifier, so a code that came back with a different one came from a
        // request this process never made. Upstream calls it a possible CSRF attack.
        $this->assertStringContainsString('wrong state', $problem->getMessage());
        $server->close();
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            self::fail('cannot open a probe socket');
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** Write the callback and walk away — the reply is not what this test is about. */
    private static function pretendBrowser(int $port, string $target): void
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);

        if ($client === false) {
            self::fail("cannot reach the callback server: {$errstr}");
        }

        stream_set_blocking($client, false);
        fwrite($client, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    }

    // ---- Gemini CLI: the provider entry ---------------------------------------------------

    public function testGeminiCliIsOfferedNowThatThereAreModelsToChoose(): void
    {
        // It was false while the protocol and the models were missing: a sign-in that unlocks
        // nothing to choose is the same mistake as a model whose protocol is not ported.
        $this->assertTrue(Provider::GoogleGeminiCli->available());
    }

    public function testGeminiClisKeyCarriesTheProjectAlongsideTheToken(): void
    {
        $key = Provider::GoogleGeminiCli->apiKey(new Credentials('r', 'ya29.a', 0, projectId: 'proj-1'));

        // Upstream's shape: Code Assist needs both, and an api key field can only carry one
        // string.
        $this->assertSame(['token' => 'ya29.a', 'projectId' => 'proj-1'], json_decode($key, true));
    }

    public function testGeminiCliCredentialsWithNoProjectAreRefused(): void
    {
        $this->assertThrows(
            OauthError::class,
            static fn (): string => Provider::GoogleGeminiCli->apiKey(new Credentials('r', 'a', 0)),
            'no Cloud project id',
        );
    }

    // ---- Antigravity ----------------------------------------------------------------------

    /** Made-up credentials: this repository does not hold Antigravity's, and does not need to. */
    private function antigravity(string $url, ?CallbackServer $server = null): Antigravity
    {
        return new Antigravity('ag-client-id', 'ag-client-secret', new HttpClient(), $server, $url);
    }

    public function testAntigravityWithNoClientCredentialsIsRefusedAtOnce(): void
    {
        $this->assertThrows(
            OauthError::class,
            static fn (): Antigravity => new Antigravity('', ''),
            'client id and secret',
        );
    }

    public function testAntigravityAsksForItsOwnFiveScopes(): void
    {
        $pkce = Pkce::create();
        $url = $this->antigravity('http://unused')
            ->authorizeUrl($pkce, 'http://localhost:51121/oauth-callback');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $scopes = explode(' ', $query['scope']);

        // Five, not Gemini CLI's three. `cclog` and `experimentsandconfigs` are the two extra
        // and they are what the sandbox deployment checks for — a token minted with Gemini
        // CLI's scopes reaches the same endpoint and is refused there.
        $this->assertCount(5, $scopes);
        $this->assertContains('https://www.googleapis.com/auth/cclog', $scopes);
        $this->assertContains('https://www.googleapis.com/auth/experimentsandconfigs', $scopes);
        $this->assertSame('ag-client-id', $query['client_id']);
        $this->assertSame('http://localhost:51121/oauth-callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
    }

    public function testAntigravityComesBackToItsOwnRegisteredPort(): void
    {
        // Registered with Google against that client id, so neither the port nor the path is a
        // preference — a redirect Google has not been told about is refused before anybody sees
        // a consent screen.
        $this->assertSame(51121, Antigravity::PORT);
        $this->assertSame('/oauth-callback', Antigravity::CALLBACK_PATH);
        $this->assertSame(
            'http://localhost:51121/oauth-callback',
            (new CallbackServer(Antigravity::PORT, Antigravity::CALLBACK_PATH))->redirectUri(),
        );
    }

    public function testAntigravityRefusesAnExchangeWithNoRefreshTokenInIt(): void
    {
        $url = $this->serve(['access_token' => 'ya29.a', 'expires_in' => 3599]);

        // Without one the sign-in lasts an hour and then silently is not one, which is what
        // `prompt=consent` is there to stop happening in the first place.
        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->run(fn (): array => $this->antigravity($url)->exchange('c', 'v', 'http://x/cb')),
            'no refresh token',
        );
    }

    public function testAntigravityCarriesItsOwnClientThroughARenewal(): void
    {
        $url = $this->serve(['access_token' => 'ya29.new', 'expires_in' => 3599]);

        $renewed = $this->run(fn (): Credentials => $this->antigravity($url)->refresh('1//r', 'proj-1'));

        $sent = $this->server->received();

        // Its own pair, not Gemini CLI's: two OAuth clients, and a renewal carries the one the
        // token was minted by.
        $this->assertStringContainsString('client_id=ag-client-id', $sent);
        $this->assertStringContainsString('grant_type=refresh_token', $sent);
        $this->assertSame('ya29.new', $renewed->access);
        $this->assertSame('1//r', $renewed->refresh, 'Google sends nothing rather than the same value again');
        $this->assertSame('proj-1', $renewed->projectId);
    }

    public function testAntigravityTakesTheProjectTheFirstEndpointNames(): void
    {
        $url = $this->serve(['cloudaicompanionProject' => 'somebody-elses-project']);

        $this->assertSame(
            'somebody-elses-project',
            $this->run(fn (): string => $this->antigravity($url)->project('ya29.a')),
        );

        // Not Gemini CLI's headers: the sandbox checks who is asking.
        $this->assertStringContainsString('v1internal:loadCodeAssist', $this->server->received());
    }

    public function testAntigravityReadsTheProjectWhenItComesBackAsAnObject(): void
    {
        $url = $this->serve(['cloudaicompanionProject' => ['id' => 'proj-from-object']]);

        // A string on one endpoint and an object with an `id` on the other, which is Google's
        // inconsistency rather than a guess about the shape.
        $this->assertSame(
            'proj-from-object',
            $this->run(fn (): string => $this->antigravity($url)->project('ya29.a')),
        );
    }

    public function testAntigravityFallsBackToItsConstantRatherThanFailing(): void
    {
        $url = $this->serve('nope', status: 403, reason: 'Forbidden');

        // Every discovery failure is swallowed on purpose — a 403 on the production endpoint is
        // the normal case for an account that was always going to use the sandbox. Unlike
        // Gemini CLI's, nothing is provisioned and nothing is waited for.
        $this->assertSame(
            Antigravity::FALLBACK_PROJECT,
            $this->run(fn (): string => $this->antigravity($url)->project('ya29.a')),
        );
    }

    public function testAntigravitysKeyCarriesTheProjectAlongsideTheToken(): void
    {
        $key = Provider::GoogleAntigravity->apiKey(new Credentials('r', 'ya29.a', 0, projectId: 'proj-1'));

        // The same shape Gemini CLI's uses, because it is the same protocol — two deployments,
        // one provider class parsing the key back.
        $this->assertSame(['token' => 'ya29.a', 'projectId' => 'proj-1'], json_decode($key, true));
    }
}
