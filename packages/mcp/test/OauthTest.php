<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Mcp\Oauth\Discovery;
use Pig\Mcp\Oauth\Flow;
use Pig\Mcp\Oauth\McpOauthProvider;
use Pig\Mcp\Oauth\MemoryOauthStateStore;
use Pig\Mcp\Oauth\Metadata;
use Pig\Mcp\Oauth\OauthCallbackServer;
use Pig\Mcp\Oauth\OauthError;
use Pig\Mcp\Oauth\OauthInsecureEndpointError;
use Pig\Mcp\Oauth\OauthIssuerMismatchError;

final class OauthTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    // ---- WWW-Authenticate -----------------------------------------------------------------------

    /** @return iterable<string, array{?string, array<string, ?string>}> */
    public static function challenges(): iterable
    {
        $none = ['resourceMetadataUrl' => null, 'scope' => null, 'error' => null, 'errorDescription' => null];
        yield 'nothing' => [null, $none];
        yield 'basic is not bearer' => ['Basic realm="x"', $none];
        yield 'bearer with metadata and scope' => [
            'Bearer realm="mcp", resource_metadata="https://x/.well-known/oauth-protected-resource", scope="a b"',
            ['resourceMetadataUrl' => 'https://x/.well-known/oauth-protected-resource', 'scope' => 'a b', 'error' => null, 'errorDescription' => null],
        ];
        yield 'insufficient scope, unquoted' => [
            'Bearer error=insufficient_scope, error_description="need more", scope=repo',
            ['resourceMetadataUrl' => null, 'scope' => 'repo', 'error' => 'insufficient_scope', 'errorDescription' => 'need more'],
        ];
        yield 'metadata that is not a url is dropped' => ['Bearer resource_metadata="not a url"', $none];
        yield 'dpop counts' => ['DPoP scope="x"', ['resourceMetadataUrl' => null, 'scope' => 'x', 'error' => null, 'errorDescription' => null]];
    }

    #[DataProvider('challenges')]
    public function testTheChallengeIsReadOutOfWwwAuthenticate(?string $header, array $expected): void
    {
        $this->assertSame($expected, Metadata::wwwAuthenticate($header));
    }

    public function testMetadataParsersRefuseWhatIsNotTheirShape(): void
    {
        $this->assertSame('x', Metadata::tokens(['access_token' => 'x', 'token_type' => 'Bearer', 'expires_in' => '60'])['access_token']);
        $this->assertSame(60.0, Metadata::tokens(['access_token' => 'x', 'token_type' => 'Bearer', 'expires_in' => '60'])['expires_in']);
        $this->assertArrayNotHasKey('refresh_token', Metadata::tokens(['access_token' => 'x', 'token_type' => 'Bearer']));

        foreach ([
            fn () => Metadata::tokens(['token_type' => 'Bearer']),
            fn () => Metadata::tokens(['access_token' => 'x', 'token_type' => 'Bearer', 'expires_in' => 'soon']),
            fn () => Metadata::authorizationServer(['issuer' => 'https://x', 'authorization_endpoint' => 'https://x/a', 'token_endpoint' => 'https://x/t']),
            fn () => Metadata::authorizationServer(['issuer' => 'javascript:alert(1)', 'authorization_endpoint' => 'https://x/a', 'token_endpoint' => 'https://x/t', 'response_types_supported' => ['code']]),
            fn () => Metadata::clientInformation(['redirect_uris' => []]),
            fn () => Metadata::protectedResource('nope'),
        ] as $bad) {
            try {
                $bad();
                $this->fail('refused');
            } catch (\RuntimeException $error) {
                $this->assertStringStartsWith('Invalid ', $error->getMessage());
            }
        }
    }

    // ---- discovery ------------------------------------------------------------------------------

    public function testDiscoveryUrlsFollowTheIssuerPath(): void
    {
        $this->assertSame([
            'https://as.example/.well-known/oauth-authorization-server',
            'https://as.example/.well-known/openid-configuration',
        ], Discovery::authorizationServerDiscoveryUrls('https://as.example/'));

        $this->assertSame([
            'https://as.example/.well-known/oauth-authorization-server/tenant',
            'https://as.example/.well-known/openid-configuration/tenant',
            'https://as.example/tenant/.well-known/openid-configuration',
        ], Discovery::authorizationServerDiscoveryUrls('https://as.example/tenant/'));
    }

    public function testDiscoveryTriesTheNextCandidateOnAMissAndStopsOnAFailure(): void
    {
        Async::run(function (): void {
            $asked = [];
            $fetch = static function (Request $request) use (&$asked): \Pig\Ai\Http\Response {
                $asked[] = $request->url;

                return match ($request->url) {
                    'https://as.example/.well-known/oauth-authorization-server' => CannedResponse::make(404, '{}'),
                    'https://as.example/.well-known/openid-configuration' => CannedResponse::make(200, json_encode([
                        'issuer' => 'https://as.example',
                        'authorization_endpoint' => 'https://as.example/a',
                        'token_endpoint' => 'https://as.example/t',
                        'response_types_supported' => ['code'],
                    ])),
                    default => CannedResponse::make(500, 'boom'),
                };
            };

            $metadata = Discovery::authorizationServerMetadata('https://as.example/', $fetch);
            $this->assertSame('https://as.example/t', $metadata['token_endpoint']);
            $this->assertCount(2, $asked, 'the 404 moved on; the hit stopped');

            try {
                Discovery::authorizationServerMetadata('https://other.example/', static fn () => CannedResponse::make(500, 'boom'));
                $this->fail('a 500 is a failure, not a miss');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('HTTP 500', $error->getMessage());
            }

            $this->assertNull(Discovery::authorizationServerMetadata('https://none.example/', static fn () => CannedResponse::make(404, '')), 'nothing anywhere is null');
        });
    }

    public function testAMetadataDocumentNamingAnotherIssuerIsRefused(): void
    {
        Async::run(function (): void {
            $fetch = static fn (Request $request) => CannedResponse::make(200, json_encode([
                'issuer' => 'https://evil.example',
                'authorization_endpoint' => 'https://evil.example/a',
                'token_endpoint' => 'https://evil.example/t',
                'response_types_supported' => ['code'],
            ]));

            $this->expectException(OauthIssuerMismatchError::class);
            Discovery::authorizationServerMetadata('https://as.example/', $fetch);
        });
    }

    public function testTheResourceIsOnlyTakenWhenItCoversTheServer(): void
    {
        $this->assertNull(Discovery::selectResource('https://x/mcp', null));
        $this->assertSame('https://x/mcp', Discovery::selectResource('https://x/mcp', ['resource' => 'https://x/mcp']));
        $this->assertSame('https://x/', Discovery::selectResource('https://x/mcp/deep', ['resource' => 'https://x/']));

        $this->expectException(\RuntimeException::class);
        Discovery::selectResource('https://x/mcp', ['resource' => 'https://x/other']);
    }

    // ---- the flow -------------------------------------------------------------------------------

    public function testTheWholeFlowRegistersRedirectsAndExchangesAgainstTheFakeServer(): void
    {
        $server = new FakeHttpMcpServer();
        $server->oauth = true;
        $server->requireToken = 'nothing-yet';

        try {
            Async::run(function () use ($server): void {
                $store = new MemoryOauthStateStore();
                $redirectedTo = null;
                $provider = new McpOauthProvider($server->url(), 'http://127.0.0.1:1/callback', ['client_name' => 'pig'], static function (string $url) use (&$redirectedTo): void {
                    $redirectedTo = $url;
                }, store: $store);

                $this->assertSame(Flow::REDIRECT, Flow::authorize($provider, ['serverUrl' => $server->url()]));

                // Registered with the redirect URI, and sent to the authorization endpoint with PKCE.
                $this->assertSame(['http://127.0.0.1:1/callback'], $server->registration['redirect_uris']);
                $this->assertSame('mcp:tools', $server->registration['scope'], "the resource's scopes, when nothing else said");
                $this->assertStringStartsWith(parse_url($server->url(), PHP_URL_SCHEME) . '://', $redirectedTo);
                parse_str((string) parse_url($redirectedTo, PHP_URL_QUERY), $query);
                $this->assertSame('code', $query['response_type']);
                $this->assertSame('S256', $query['code_challenge_method']);
                $this->assertSame($provider->state(), $query['state']);
                $this->assertSame($server->url(), $query['resource']);
                $this->assertSame('mcp:tools', $query['scope']);

                // The browser comes back with a code for that challenge; the exchange yields tokens.
                $code = $server->issueCode($query['code_challenge']);
                $this->assertSame(Flow::AUTHORIZED, Flow::authorize($provider, ['serverUrl' => $server->url(), 'authorizationCode' => $code]));
                $tokens = $provider->tokens();
                $this->assertSame($server->requireToken, $tokens['access_token']);
                $this->assertStringStartsWith('refresh-', $tokens['refresh_token']);
                $this->assertSame($provider->codeVerifier(), $server->tokenRequests[0]['code_verifier']);
                $this->assertSame('http://127.0.0.1:1/callback', $server->tokenRequests[0]['redirect_uri']);
                $this->assertGreaterThan((int) (microtime(true) * 1000), $store->load()['tokensExpireAt']);

                // A second authorize with a refresh token refreshes, and the server rotated it.
                $before = $tokens['refresh_token'];
                $this->assertSame(Flow::AUTHORIZED, Flow::authorize($provider, ['serverUrl' => $server->url()]));
                $this->assertNotSame($before, $provider->tokens()['refresh_token']);
                $this->assertSame('refresh_token', end($server->tokenRequests)['grant_type']);

                // Discovery was cached, so the refresh did not re-fetch the well-knowns.
                $wellKnowns = array_filter($server->requests, static fn (array $r): bool => str_contains($r['path'], '.well-known'));
                $this->assertCount(2, $wellKnowns, 'resource metadata and server metadata, once each');
            });
        } finally {
            $server->stop();
        }
    }

    public function testARefreshTheServerRefusesFallsBackToTheBrowser(): void
    {
        $server = new FakeHttpMcpServer();
        $server->oauth = true;

        try {
            Async::run(function () use ($server): void {
                $store = new MemoryOauthStateStore();
                $redirected = 0;
                $provider = new McpOauthProvider($server->url(), 'http://127.0.0.1:1/callback', ['client_name' => 'pig'], static function () use (&$redirected): void {
                    $redirected++;
                }, store: $store);

                // Tokens the server has never heard of: `invalid_grant` → tokens dropped → redirect.
                Flow::authorize($provider, ['serverUrl' => $server->url()]);
                $provider->saveTokens(['access_token' => 'old', 'token_type' => 'Bearer', 'refresh_token' => 'stale']);

                $this->assertSame(Flow::REDIRECT, Flow::authorize($provider, ['serverUrl' => $server->url()]));
                $this->assertNull($provider->tokens(), 'the refused tokens are gone');
                $this->assertSame(2, $redirected);
            });
        } finally {
            $server->stop();
        }
    }

    public function testATokenEndpointThatIsNotHttpsGetsNoCredentials(): void
    {
        Async::run(function (): void {
            $provider = new McpOauthProvider('http://mcp.example/mcp', 'http://127.0.0.1:1/callback', ['client_name' => 'pig'], static fn () => null, store: new MemoryOauthStateStore());
            $provider->saveDiscoveryState([
                'authorizationServerUrl' => 'http://as.example/',
                'authorizationServerMetadata' => ['issuer' => 'http://as.example', 'authorization_endpoint' => 'http://as.example/a', 'token_endpoint' => 'http://as.example/t', 'response_types_supported' => ['code']],
                'resourceMetadata' => null,
            ]);
            $provider->saveClientInformation(['client_id' => 'c', 'redirect_uris' => []]);
            $provider->saveTokens(['access_token' => 'a', 'token_type' => 'Bearer', 'refresh_token' => 'r']);

            $this->expectException(OauthInsecureEndpointError::class);
            Flow::authorize($provider, ['serverUrl' => 'http://mcp.example/mcp'], static fn () => CannedResponse::make(200, '{}'));
        });
    }

    public function testAnOauthErrorInTheBodyWinsOverTheStatus(): void
    {
        Async::run(function (): void {
            $provider = new McpOauthProvider('https://mcp.example/mcp', 'http://127.0.0.1:1/callback', ['client_name' => 'pig'], static fn () => null, store: new MemoryOauthStateStore());
            $provider->saveDiscoveryState([
                'authorizationServerUrl' => 'https://as.example/',
                'authorizationServerMetadata' => ['issuer' => 'https://as.example', 'authorization_endpoint' => 'https://as.example/a', 'token_endpoint' => 'https://as.example/t', 'response_types_supported' => ['code']],
                'resourceMetadata' => null,
            ]);
            $provider->saveClientInformation(['client_id' => 'c', 'redirect_uris' => []]);
            $provider->saveCodeVerifier('v');

            try {
                Flow::authorize($provider, ['serverUrl' => 'https://mcp.example/mcp', 'authorizationCode' => 'x'], static fn () => CannedResponse::make(200, '{"error":"access_denied","error_description":"nope"}'));
                $this->fail('the body said no');
            } catch (OauthError $error) {
                $this->assertSame('access_denied', $error->oauthCode);
                $this->assertSame('nope', $error->getMessage());
            }
        });
    }

    public function testStateForAnotherServerIsNeverReadAsThisOnes(): void
    {
        $store = new MemoryOauthStateStore();
        $store->save(['serverUrl' => 'https://other/mcp', 'tokens' => ['access_token' => 'theirs', 'token_type' => 'Bearer']]);
        $provider = new McpOauthProvider('https://mine/mcp', 'http://127.0.0.1:1/callback', [], static fn () => null, store: $store);

        $this->assertNull($provider->tokens());
        $provider->saveTokens(['access_token' => 'mine', 'token_type' => 'Bearer']);
        $this->assertSame('https://mine/mcp', $store->load()['serverUrl'], 'and writing replaces the record wholesale');
    }

    // ---- the callback server --------------------------------------------------------------------

    public function testTheCallbackServerMatchesTheBrowserToTheWaitingSignInByState(): void
    {
        Async::run(function (): void {
            $server = OauthCallbackServer::listen(timeout: 5.0);
            $this->assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+/callback$#', $server->redirectUrl);

            $result = null;
            $waiter = Async::spawn(static fn () => $server->waitForCallback('s1'));

            // A browser for a *different* state is told so and resolves nothing.
            $this->assertStringContainsString('400', $this->browse($server->redirectUrl . '?code=c&state=wrong'));
            $this->assertStringContainsString('404', $this->browse(str_replace('/callback', '/favicon.ico', $server->redirectUrl)));
            $this->assertFalse($waiter->isComplete());

            $page = $this->browse($server->redirectUrl . '?code=the-code&state=s1&iss=https%3A%2F%2Fas');
            $this->assertStringContainsString('200 OK', $page);
            $this->assertStringContainsString('Signed in', $page);
            $this->assertSame(['code' => 'the-code', 'state' => 's1', 'iss' => 'https://as'], $waiter->await());

            // A refusal is a throw with the server's words.
            $waiter = Async::spawn(static fn () => $server->waitForCallback('s2'));
            $this->browse($server->redirectUrl . '?error=access_denied&error_description=User+said+no&state=s2');

            try {
                $waiter->await();
                $this->fail('refused');
            } catch (\RuntimeException $error) {
                $this->assertSame('User said no', $error->getMessage());
            }

            $server->close();
        });

        Loop::get()->tick();
        $this->assertTrue(Loop::get()->isIdle(), 'closing cancels the timers and watchers');
    }

    public function testClosingTheCallbackServerFailsWhoeverWasWaiting(): void
    {
        Async::run(function (): void {
            $server = OauthCallbackServer::listen(timeout: 5.0);
            $waiter = Async::spawn(static fn () => $server->waitForCallback('s'));
            Async::delay(0.01);
            $server->close();

            try {
                $waiter->await();
                $this->fail('closed');
            } catch (\RuntimeException $error) {
                $this->assertSame('OAuth callback server closed', $error->getMessage());
            }
        });
    }

    /** A GET from the loop, as a browser would make it; answers the raw response. */
    private function browse(string $url): string
    {
        $client = new \Pig\Ai\Http\HttpClient(timeout: 5.0);
        $response = $client->send(new Request('GET', $url));
        $body = $response->body->all();

        return "HTTP/1.1 {$response->status} " . ($response->status === 200 ? 'OK' : '') . "\n\n{$body}";
    }
}
