<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\DeviceCode;
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

    private function onTheLoop(callable $work): mixed
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
        // With no redirect named, the copy-code page: the URL a paste comes back from.
        $this->assertSame(Anthropic::COPY_CODE_REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame(Anthropic::SCOPES, $query['scope']);
        $this->assertSame('code', $query['response_type']);
    }

    public function testTheEndpointsAndScopesArePiOnePointZeros(): void
    {
        // The anchor had `console.anthropic.com` and three scopes. pi 1.0 moved both to
        // `platform.claude.com` — the old callback page is a 301 to the new one, measured — and
        // asks for six scopes, three of which a token minted under the old three does not carry.
        $this->assertSame('https://platform.claude.com/v1/oauth/token', Anthropic::TOKEN_URL);
        $this->assertSame('https://platform.claude.com/oauth/code/callback', Anthropic::COPY_CODE_REDIRECT_URI);

        foreach (['user:sessions:claude_code', 'user:mcp_servers', 'user:file_upload', 'user:inference'] as $scope) {
            $this->assertStringContainsString($scope, Anthropic::SCOPES);
        }

        // The browser flow's loopback, registered with Anthropic for this client id.
        $this->assertSame('http://localhost:53692/callback', Anthropic::callbackRedirectUri());
    }

    public function testTheBrowserFlowsUrlNamesTheLoopback(): void
    {
        $url = Anthropic::authorizeUrl(Pkce::create(), 'http://localhost:53692/callback');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('http://localhost:53692/callback', $query['redirect_uri']);
    }

    // ---- what somebody pasted, in every shape it arrives in -----------------------------

    /** @return iterable<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function pastes(): iterable
    {
        yield 'code#state' => ['abc#xyz', 'abc', 'xyz'];
        yield 'the whole redirect URL' => ['http://localhost:53692/callback?code=abc&state=xyz', 'abc', 'xyz'];
        yield 'a redirect URL with only a code' => ['http://localhost:53692/callback?code=abc', 'abc', null];
        yield 'a bare query string' => ['code=abc&state=xyz', 'abc', 'xyz'];
        yield 'just the code' => ['abc', 'abc', null];
        yield 'whitespace around it' => ["  abc#xyz\n", 'abc', 'xyz'];
        yield 'nothing' => ['   ', null, null];
        yield 'half a paste' => ['abc#', 'abc', null];
    }

    #[DataProvider('pastes')]
    public function testEveryShapeOfPasteIsReadTheSameWay(string $input, ?string $code, ?string $state): void
    {
        // Upstream's `parseAuthorizationInput()`: the browser flow's box takes the redirect URL
        // out of the address bar, the copy-code page shows `code#state`, and a paste that lost
        // its end is a bare code — which `exchange()` then refuses by name.
        $this->assertSame(['code' => $code, 'state' => $state], Anthropic::parseAuthorizationInput($input));
    }

    public function testAPastedStateThatIsNotTheVerifierIsRefused(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        // The state is the verifier, so a code with somebody else's state on it did not come
        // from the request this pig made. Upstream throws "OAuth state mismatch" here too.
        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))
                ->exchange('c#somebody-elses', 'the-verifier')),
        );

        $this->assertStringContainsString('wrong state', $problem->getMessage());
        $this->assertSame('', $this->server->received(), 'and nothing was sent');
    }

    // ---- redeeming what was pasted ------------------------------------------------------

    public function testTheCodeAndStateGoOutWithTheVerifier(): void
    {
        $url = $this->serve(['access_token' => 'sk-ant-oat-x', 'refresh_token' => 'r1', 'expires_in' => 3600]);

        // The state *is* the verifier, so a paste whose state matches is the only one accepted.
        $credentials = $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))
            ->exchange('the-code#the-verifier', 'the-verifier'));

        $sent = $this->server->receivedJson();

        $this->assertSame('authorization_code', $sent['grant_type']);
        $this->assertSame('the-code', $sent['code']);
        $this->assertSame('the-verifier', $sent['state']);
        $this->assertSame('the-verifier', $sent['code_verifier']);
        $this->assertSame(Anthropic::COPY_CODE_REDIRECT_URI, $sent['redirect_uri']);

        $this->assertSame('sk-ant-oat-x', $credentials->access);
        $this->assertSame('r1', $credentials->refresh);
    }

    public function testWhitespaceAroundThePasteIsNotPartOfTheCode(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->exchange("  c#v\n", 'v'));

        // A code pasted out of a terminal arrives with a newline on it about half the time,
        // and a trailing newline inside the code is a 400 that says `invalid_grant`.
        $this->assertSame('c', $this->server->receivedJson()['code']);
        $this->assertSame('v', $this->server->receivedJson()['state']);
    }

    public function testHalfAPasteIsRefusedBeforeAnythingIsSent(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))
                ->exchange('just-the-code', 'v')),
        );

        $this->assertStringContainsString('paste all of it', $problem->getMessage());
        // Nothing went out: the mistake is on this side, and the server's own answer to it
        // would be `invalid_grant`, which describes six different mistakes equally badly.
        $this->assertSame('', $this->server->received());
    }

    // ---- the browser flow: the callback and the paste box race -----------------------------

    public function testTheBrowserFlowTakesTheCodeOffTheSocketAndClosesThePasteBox(): void
    {
        $port = self::freePort();
        $server = new CallbackServer($port, Anthropic::CALLBACK_PATH, 'Anthropic');
        $url = $this->serve(['access_token' => 'sk-ant-oat-b', 'refresh_token' => 'r', 'expires_in' => 3600]);
        $shown = null;
        $boxClosed = false;

        $credentials = $this->onTheLoop(function () use ($url, $server, $port, &$shown, &$boxClosed): ?Credentials {
            return (new Anthropic(new HttpClient(), $url, $server))->loginWithBrowser(
                static function (string $u, ?string $i) use (&$shown, $port): void {
                    $shown = $u;
                    // The browser: the redirect lands on the loopback with the state the URL
                    // carried, which is the verifier.
                    parse_str((string) parse_url($u, PHP_URL_QUERY), $query);
                    Loop::get()->defer(static function () use ($port, $query): void {
                        self::pretendBrowser($port, "/callback?code=from-the-browser&state={$query['state']}");
                    });
                },
                // The paste box, parked until something closes it. When the callback wins it is
                // told to, and answers null — a box left open over a finished sign-in is the
                // bug this closes.
                static function (string $m, string $p, bool $e, $closing) use (&$boxClosed): ?string {
                    $parked = new \Pig\Async\Deferred();
                    $closing->onAbort(static function () use ($parked, &$boxClosed): void {
                        $boxClosed = true;
                        if (!$parked->isComplete()) {
                            $parked->complete(null);
                        }
                    });

                    return $parked->future->await();
                },
            );
        });

        $this->assertNotNull($credentials);
        $this->assertSame('sk-ant-oat-b', $credentials->access);
        $this->assertTrue($boxClosed, 'the paste box was closed when the callback won');
        $this->assertStringContainsString("localhost%3A{$port}%2Fcallback", (string) $shown, 'the URL names the loopback');

        $sent = $this->server->receivedJson();
        $this->assertSame('from-the-browser', $sent['code']);
        $this->assertSame("http://localhost:{$port}/callback", $sent['redirect_uri']);
    }

    public function testTheBrowserFlowTakesAPastedRedirectUrlWhenTheBrowserIsElsewhere(): void
    {
        $port = self::freePort();
        $server = new CallbackServer($port, Anthropic::CALLBACK_PATH, 'Anthropic');
        $url = $this->serve(['access_token' => 'sk-ant-oat-p', 'refresh_token' => 'r', 'expires_in' => 3600]);

        $credentials = $this->onTheLoop(function () use ($url, $server): ?Credentials {
            $verifier = null;

            return (new Anthropic(new HttpClient(), $url, $server))->loginWithBrowser(
                static function (string $u, ?string $i) use (&$verifier): void {
                    parse_str((string) parse_url($u, PHP_URL_QUERY), $query);
                    $verifier = $query['state'];
                },
                // Nothing reaches the socket; the person brings the redirect URL back by hand.
                static fn (string $m, string $p, bool $e, $closing): ?string
                    => "http://localhost:53692/callback?code=pasted-code&state={$verifier}",
            );
        });

        $this->assertNotNull($credentials);
        $this->assertSame('pasted-code', $this->server->receivedJson()['code']);
    }

    public function testEscapingTheBrowserFlowsPasteBoxIsACancellation(): void
    {
        $port = self::freePort();
        $server = new CallbackServer($port, Anthropic::CALLBACK_PATH, 'Anthropic');
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $credentials = $this->onTheLoop(fn (): ?Credentials => (new Anthropic(new HttpClient(), $url, $server))->loginWithBrowser(
            static function (string $u, ?string $i): void {
            },
            static fn (string $m, string $p, bool $e, $closing): ?string => null,
        ));

        $this->assertNull($credentials);
        $this->assertSame('', $this->server->received(), 'nothing was exchanged');
    }

    public function testACallbackWithTheWrongStateIsRefusedByTheBrowserFlowToo(): void
    {
        $port = self::freePort();
        $server = new CallbackServer($port, Anthropic::CALLBACK_PATH, 'Anthropic');
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $problem = $this->assertThrows(OauthError::class, function () use ($url, $server, $port): void {
            $this->onTheLoop(fn (): ?Credentials => (new Anthropic(new HttpClient(), $url, $server))->loginWithBrowser(
                static function (string $u, ?string $i) use ($port): void {
                    Loop::get()->defer(static function () use ($port): void {
                        self::pretendBrowser($port, '/callback?code=c&state=not-the-verifier');
                    });
                },
                static function (string $m, string $p, bool $e, $closing): ?string {
                    $parked = new \Pig\Async\Deferred();
                    $closing->onAbort(static function () use ($parked): void {
                        if (!$parked->isComplete()) {
                            $parked->complete(null);
                        }
                    });

                    return $parked->future->await();
                },
            ));
        });

        $this->assertStringContainsString('wrong state', $problem->getMessage());
    }

    public function testTheExpiryHasTheSafetyMarginTakenOffIt(): void
    {
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);

        $before = (int) (microtime(true) * 1000);
        $credentials = $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->exchange('c#v', 'v'));

        // An hour, less the five minutes upstream subtracts. A token that expires in flight
        // fails the request it was attached to rather than the next one.
        $this->assertGreaterThan($before + 3600_000 - 5 * 60_000 - 5_000, $credentials->expires);
        $this->assertLessThan($before + 3600_000 - 5 * 60_000 + 5_000, $credentials->expires);
    }

    // ---- renewing -----------------------------------------------------------------------

    public function testRefreshingSendsTheRefreshTokenAndNothingElseAboutTheSession(): void
    {
        $url = $this->serve(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 60]);

        $credentials = $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1'));

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
            fn (): mixed => $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
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
            fn (): mixed => $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
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
            fn (): mixed => $this->onTheLoop(fn (): Credentials => (new Anthropic(new HttpClient(), $url))->refresh('r1')),
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
        // Both, and `available()` is a table with one row each rather than a `return true` —
        // upstream's shape, where it is a literal field on each entry. A third built-in would be
        // an unhandled `match`, which is the point of there being no `default`; a provider an
        // extension brings is an `OauthFlow` and never reaches this enum.
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

    /**
     * What WHATWG forbids in a host, which `parse_url()` does not.
     *
     * Every row was run against `new URL("https://" + input).hostname` and is null there too.
     * The space is the one anybody reaches: a domain pasted with a word after it used to come
     * back as a host, so the flow went on to build `https://a b/login/device/code` and failed
     * with `Cannot parse URL` rather than with the sentence written for this.
     */
    #[DataProvider('typedThingsThatAreNotHosts')]
    public function testAHostWithSomethingForbiddenInItIsNotOne(string $typed): void
    {
        $this->assertNull(GithubCopilot::normalizeDomain($typed));
    }

    /** @return array<string, array{0: string}> */
    public static function typedThingsThatAreNotHosts(): array
    {
        return [
            'a space' => ['a b'],
            'a word after the domain' => ['not a host!!'],
            'a less-than' => ['a<b.com'],
            'a greater-than' => ['a>b.com'],
            'a caret' => ['a^b.com'],
            'a bar' => ['a|b.com'],
            'a stray percent' => ['a%b.com'],
            // Percent-decoded by WHATWG before the check, so this is the space again.
            'a percent-encoded space' => ['a%20b.com'],
            'a control character' => ["a\x01b.com"],
            'a delete' => ["a\x7fb.com"],
        ];
    }

    public function testATabInsideAHostIsRemovedRatherThanRenamingTheHost(): void
    {
        // WHATWG strips tab, LF and CR from the string before parsing anything, so this is
        // `ab.com` on both sides. It has to happen first: `parse_url()` hands an interior tab
        // back as an **underscore**, and `a_b.com` is a perfectly good host that nobody typed.
        $this->assertSame('ab.com', GithubCopilot::normalizeDomain("a\tb.com"));
        $this->assertSame('ab.com', GithubCopilot::normalizeDomain("a\nb.com"));
        $this->assertSame('ab.com', GithubCopilot::normalizeDomain("a\rb.com"));
    }

    public function testAControlAtTheEndIsStrippedAndOneAtTheFrontIsNot(): void
    {
        // Both answers are upstream's, and the asymmetry is the order rather than a rule: the
        // scheme goes on first, so a control at the end is still at the end of the string the
        // parser strips — and one at the front now has `https://` before it, which puts it
        // inside the host, where a control is refused.
        $this->assertSame('ghe.com', GithubCopilot::normalizeDomain("ghe.com\x01"));
        $this->assertNull(GithubCopilot::normalizeDomain("\x01ghe.com"));
    }

    public function testATypedDomainIsLowercasedTheWayTheUrlParserDoesIt(): void
    {
        // The typed spelling reaches `Credentials::enterpriseUrl` and `baseUrl()`, so without
        // this the same domain typed two ways is stored and sent as two different strings.
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('COMPANY.GHE.COM'));
        $this->assertSame('company.ghe.com', GithubCopilot::normalizeDomain('HTTPS://Company.GHE.com/x'));
    }

    public function testAnIpv6LiteralKeepsItsBrackets(): void
    {
        // `[` and `]` are forbidden host code points and are deliberately not refused, because
        // this is what both parsers answer for an address literal.
        $this->assertSame('[::1]', GithubCopilot::normalizeDomain('[::1]'));
        $this->assertSame('[::1]', GithubCopilot::normalizeDomain('https://[::1]/x'));
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

        $device = $this->onTheLoop(fn (): DeviceCode => $this->copilot($url)->start('github.com'));

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
            fn (): mixed => $this->onTheLoop(fn (): DeviceCode => $this->copilot($url)->start()),
            'without the codes',
        );
    }

    public function testAnApprovedCodeComesBackAsAGitHubToken(): void
    {
        $url = $this->serve(['access_token' => 'gho_abc']);

        $token = $this->onTheLoop(fn (): ?string => $this->copilot($url)
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
            fn (): mixed => $this->onTheLoop(fn (): ?string => $this->copilot($url)
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
            fn (): mixed => $this->onTheLoop(fn (): ?string => $this->copilot($url)
                ->poll('github.com', new DeviceCode('dev-1', 'ABCD', 'https://x', 1, 900))),
        );

        $this->assertStringContainsString('access_denied', $problem->getMessage());
    }

    public function testTheWaitingCanBeCalledOff(): void
    {
        $url = $this->serve(['error' => 'authorization_pending']);

        $token = $this->onTheLoop(function () use ($url): ?string {
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

    public function testSlowDownMakesTheNextAskWaitFiveSecondsLonger(): void
    {
        $url = $this->serve(['error' => 'slow_down']);

        // Counting the asks rather than timing the sleep, and the window is what makes the two
        // answers different: `interval: 0` is floored to `MIN_INTERVAL`, which is one second, so
        // without the five the loop asks again at 1.0s and this window catches the second ask.
        // With the five it is 6.0s and there is only ever one. **Measured both ways** — a
        // 150ms window sees one ask whatever the code does, which is what the first version of
        // this test asserted and why it held a mutation in place.
        $this->onTheLoop(function () use ($url): ?string {
            $controller = new AbortController();
            Loop::get()->delay(1.3, static fn () => $controller->abort('enough'));

            return $this->copilot($url)->poll(
                'github.com',
                new DeviceCode('dev-1', 'ABCD', 'https://x', 0, 900),
                $controller->signal,
            );
        });

        $asks = substr_count($this->server->received(), 'POST /');

        $this->assertSame(1, $asks, "asked {$asks} times in 1.3s, so the interval did not grow");
    }

    public function testBlankAtTheEnterprisePromptMeansGithubComRatherThanARefusal(): void
    {
        $url = $this->serve([
            'device_code' => 'dev-1',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://github.com/login/device',
            'interval' => 0,
            'expires_in' => 900,
        ]);

        // `allowEmpty` on the prompt is what makes blank an answer, and the guard in front of
        // the refusal is the other half of it: the two conditions are "something was typed" and
        // "it is not a host", and either one on its own turns pressing Enter into an error.
        $answer = $this->onTheLoop(function () use ($url): ?Credentials {
            $controller = new AbortController();
            Loop::get()->delay(0.15, static fn () => $controller->abort('enough'));

            return $this->copilot($url)->login(
                static fn (): string => '',
                static fn (): null => null,
                null,
                $controller->signal,
            );
        });

        // Null because the wait was called off, which means it got past the prompt and as far
        // as asking — an error would have named the domain that was not one.
        $this->assertNull($answer);
        $this->assertStringContainsString('POST /', $this->server->received());
    }

    public function testSomethingTypedThatIsNotAHostIsRefusedBeforeAnythingIsAsked(): void
    {
        $url = $this->serve(['error' => 'authorization_pending']);

        $problem = $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->onTheLoop(fn (): ?Credentials => $this->copilot($url)->login(
                // `???` rather than something with a space in it, which `parse_url()` hands back
                // as a host where upstream's `new URL()` throws — see the entry in CLAUDE.md.
                static fn (): string => '???',
                static fn (): null => null,
            )),
        );

        // Upstream throws here too, and it is the right way round: carrying on against
        // github.com would sign somebody in to the wrong GitHub and look like it worked.
        $this->assertStringContainsString('is not a GitHub Enterprise domain', $problem->getMessage());
        $this->assertSame('', $this->server->received(), 'nothing should have been asked');
    }

    // ---- GitHub Copilot: the token swap --------------------------------------------------

    public function testTheGitHubTokenIsTradedForACopilotOne(): void
    {
        $expires = (int) (microtime(true)) + 3600;
        $url = $this->serve(['token' => 'tid=x;proxy-ep=proxy.individual.githubcopilot.com', 'expires_at' => $expires]);

        $credentials = $this->onTheLoop(fn (): Credentials => $this->copilot($url)->refresh('gho_abc'));

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

        $credentials = $this->onTheLoop(fn (): Credentials => $this->copilot($url)->refresh('gho_abc', 'company.ghe.com'));

        // Kept, because renewing has to go back to the same GitHub and nothing else in the
        // file says which one it was.
        $this->assertSame('company.ghe.com', $credentials->enterpriseUrl);
    }

    public function testACopilotTokenAnswerWithoutATokenIsRefused(): void
    {
        $url = $this->serve(['expires_at' => 1_800_000_000]);

        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->onTheLoop(fn (): Credentials => $this->copilot($url)->refresh('gho_abc')),
            'without a token',
        );
    }

    // ---- GitHub Copilot: switching the models on -----------------------------------------

    public function testAModelTheAccountCannotHaveDoesNotStopTheRest(): void
    {
        $url = $this->serve(['message' => 'no'], 403, 'Forbidden');
        $seen = [];

        $this->onTheLoop(function () use ($url, &$seen): void {
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
}
