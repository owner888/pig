<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;
use PigAntigravity\AntigravityOauth;

/**
 * The Antigravity sign-in, against a loopback server.
 *
 * These cases were in `Pig\Ai\Test\Utils\OauthTest` while the flow was a core provider; they
 * moved with it. Nothing in this file reaches Google.
 */
final class AntigravityOauthTest extends TestCase
{
    use AssertsThrows;
    use LoadsAntigravity;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        self::loadAntigravity();
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

    // ---- Antigravity ----------------------------------------------------------------------

    /** Made-up credentials: this repository does not hold Antigravity's, and does not need to. */
    private function antigravity(string $url, ?CallbackServer $server = null): AntigravityOauth
    {
        return new AntigravityOauth('ag-client-id', 'ag-client-secret', new HttpClient(), $server, $url);
    }

    public function testAntigravityWithNoClientCredentialsIsRefusedAtOnce(): void
    {
        $this->assertThrows(
            OauthError::class,
            static fn (): AntigravityOauth => new AntigravityOauth('', ''),
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
        // Both were only ever asserted through the provider that has since gone: the challenge
        // is what PKCE protects the exchange with, and the state being the verifier is what the
        // callback is checked against.
        $this->assertSame($pkce->challenge, $query['code_challenge']);
        $this->assertSame($pkce->verifier, $query['state']);
    }

    public function testAntigravityExchangesTheCodeAsAForm(): void
    {
        $url = $this->serve(['access_token' => 'ya29.a', 'refresh_token' => '1//r', 'expires_in' => 3599]);

        $tokens = $this->onTheLoop(fn (): array => $this->antigravity($url)
            ->exchange('the-code', 'the-verifier', 'http://localhost:51121/oauth-callback'));

        $sent = $this->server->received();

        // Form-encoded, not JSON: Google's token endpoint takes the other one.
        $this->assertStringContainsString('content-type: application/x-www-form-urlencoded', $sent);
        $this->assertStringContainsString('grant_type=authorization_code', $sent);
        $this->assertStringContainsString('code=the-code', $sent);
        $this->assertStringContainsString('code_verifier=the-verifier', $sent);
        // Handed in rather than held here: this repository does not ship them.
        $this->assertStringContainsString('client_id=ag-client-id', $sent);
        $this->assertStringContainsString('client_secret=ag-client-secret', $sent);

        $this->assertSame('ya29.a', $tokens['access']);
        $this->assertSame('1//r', $tokens['refresh']);
    }

    public function testAntigravityReadsTheEmailWhenGoogleWillSayIt(): void
    {
        $url = $this->serve(['email' => 'me@example.com']);

        $this->assertSame('me@example.com', $this->onTheLoop(fn (): ?string => $this->antigravity($url)->email('ya29.a')));
    }

    public function testAnEmailGoogleWillNotSayIsNotAFailure(): void
    {
        $url = $this->serve(['error' => 'nope'], 403, 'Forbidden');

        // It is a label on the credential, and an account that will not answer this is not one
        // that cannot use Antigravity.
        $this->assertNull($this->onTheLoop(fn (): ?string => $this->antigravity($url)->email('ya29.a')));
    }

    public function testAntigravityRefusesACodeThatCameBackWithTheWrongState(): void
    {
        // **The reason this was ported rather than deleted with the other provider.** It is the
        // CSRF check, `hash_equals` and all, and it was only ever tested through the flow that
        // has gone — so removing that test would have left the safeguard live and uncovered.
        $port = self::freePort();
        // The path as well as the port: a server left on the default path never sees a callback
        // sent to this provider's, and the flow then waits for one forever.
        $server = new CallbackServer($port, AntigravityOauth::CALLBACK_PATH);
        $url = $this->serve(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60]);

        $problem = $this->assertThrows(OauthError::class, function () use ($url, $server, $port): void {
            $this->onTheLoop(function () use ($url, $server, $port): void {
                Loop::get()->defer(function () use ($port): void {
                    self::pretendBrowser($port, '/oauth-callback?code=c&state=not-the-verifier');
                });

                $this->antigravity($url, $server)->signIn(static function (string $u, ?string $i): void {
                });
            });
        });

        $this->assertStringContainsString('wrong state', $problem->getMessage());
        $server->close();
    }

    public function testAntigravityComesBackToItsOwnRegisteredPort(): void
    {
        // Registered with Google against that client id, so neither the port nor the path is a
        // preference — a redirect Google has not been told about is refused before anybody sees
        // a consent screen.
        $this->assertSame(51121, AntigravityOauth::PORT);
        $this->assertSame('/oauth-callback', AntigravityOauth::CALLBACK_PATH);
        $this->assertSame(
            'http://localhost:51121/oauth-callback',
            (new CallbackServer(AntigravityOauth::PORT, AntigravityOauth::CALLBACK_PATH))->redirectUri(),
        );
    }

    public function testAntigravityRefusesAnExchangeWithNoRefreshTokenInIt(): void
    {
        $url = $this->serve(['access_token' => 'ya29.a', 'expires_in' => 3599]);

        // Without one the sign-in lasts an hour and then silently is not one, which is what
        // `prompt=consent` is there to stop happening in the first place.
        $this->assertThrows(
            OauthError::class,
            fn (): mixed => $this->onTheLoop(fn (): array => $this->antigravity($url)->exchange('c', 'v', 'http://x/cb')),
            'no refresh token',
        );
    }

    public function testAntigravityCarriesItsOwnClientThroughARenewal(): void
    {
        $url = $this->serve(['access_token' => 'ya29.new', 'expires_in' => 3599]);

        $renewed = $this->onTheLoop(fn (): Credentials => $this->antigravity($url)->renew('1//r', 'proj-1'));

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
            $this->onTheLoop(fn (): string => $this->antigravity($url)->project('ya29.a')),
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
            $this->onTheLoop(fn (): string => $this->antigravity($url)->project('ya29.a')),
        );
    }

    public function testAntigravityFallsBackToItsConstantRatherThanFailing(): void
    {
        $url = $this->serve('nope', status: 403, reason: 'Forbidden');

        // Every discovery failure is swallowed on purpose — a 403 on the production endpoint is
        // the normal case for an account that was always going to use the sandbox. Unlike
        // Gemini CLI's, nothing is provisioned and nothing is waited for.
        $this->assertSame(
            AntigravityOauth::FALLBACK_PROJECT,
            $this->onTheLoop(fn (): string => $this->antigravity($url)->project('ya29.a')),
        );
    }

    public function testAntigravitysKeyCarriesTheProjectAlongsideTheToken(): void
    {
        $key = AntigravityOauth::keyFor(new Credentials('r', 'ya29.a', 0, projectId: 'proj-1'));

        // The same shape Gemini CLI's uses, because it is the same protocol — two deployments,
        // one provider class parsing the key back.
        $this->assertSame(['token' => 'ya29.a', 'projectId' => 'proj-1'], json_decode($key, true));
    }
}
