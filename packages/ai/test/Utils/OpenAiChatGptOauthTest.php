<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\OauthCredentials;
use Pig\Ai\Utils\Oauth\OauthProvider;
use Pig\Ai\Utils\Oauth\OpenAiChatGpt;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Throwable;

/**
 * Sign in with ChatGPT for `openai` (`OpenAiChatGpt`), case for case upstream's
 * `test/openai-chatgpt-oauth.test.ts`: the registration's authorize URL, the issued client id
 * carried into the exchange and every renewal, the scopes kept, and each refusal in upstream's
 * words. The token endpoint is a canned server; the browser is the paste box, or a GET at the
 * loopback where a case is about the callback.
 */
final class OpenAiChatGptOauthTest extends TestCase
{
    private const string DEVICE_ID = 'e61bbe28-07ef-466d-8e5d-a344f94ab305';

    private const string REQUIRED_SCOPE = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';

    private ?CannedServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        putenv('PIG_OAUTH_CALLBACK_HOST');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /** @return array<string, mixed> */
    private static function tokenResponse(string $scope = self::REQUIRED_SCOPE): array
    {
        return ['access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600, 'id_token' => 'id-token', 'scope' => $scope];
    }

    /** @param array<string, mixed> $body */
    private function tokenEndpoint(array $body, int $status = 200): string
    {
        $json = (string) json_encode($body);
        $reason = $status === 200 ? 'OK' : 'Bad Request';
        $this->server = new CannedServer();

        return rtrim($this->server->start(["HTTP/1.1 {$status} {$reason}\r\ncontent-type: application/json\r\ncontent-length: " . strlen($json) . "\r\n\r\n{$json}"]), '/');
    }

    /** @return array<string, string> the form the token endpoint was sent */
    private function sentForm(): array
    {
        $received = (string) $this->server?->received();
        $this->assertStringStartsWith('POST /api/accounts/oauth/token ', $received);
        parse_str(explode("\r\n\r\n", $received, 2)[1] ?? '', $form);

        return $form;
    }

    private function flow(string $base): OpenAiChatGpt
    {
        return new OpenAiChatGpt(authBaseUrl: $base, server: new CallbackServer(self::freePort(), OpenAiChatGpt::CALLBACK_PATH, 'ChatGPT', null, issuesClientId: true));
    }

    /**
     * Upstream's `loginInteraction()`: the paste box answers with the redirect URI carrying a code,
     * the authorize URL's state and — when given — the issued client id.
     *
     * @param array<string, string> $authorize filled with the authorize URL's query
     */
    private static function login(OpenAiChatGpt $flow, ?string $clientId, ?string $deviceId = self::DEVICE_ID, ?string $agentName = null, array &$authorize = []): ?OauthCredentials
    {
        return Async::run(static function () use ($flow, $clientId, $deviceId, $agentName, &$authorize): ?OauthCredentials {
            return $flow->login(
                static function (string $url) use (&$authorize): void {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $authorize);
                },
                static function () use (&$authorize, $clientId): string {
                    return $authorize['redirect_uri'] . '?' . http_build_query(['code' => 'authorization-code', 'state' => $authorize['state']] + ($clientId === null ? [] : ['client_id' => $clientId]));
                },
                null,
                null,
                $deviceId,
                $agentName,
            );
        });
    }

    public function testItRegistersAUserOwnedClientAndStoresItsIssuedIdAndGrantedScopes(): void
    {
        $authorize = [];
        $credentials = self::login($this->flow($this->tokenEndpoint(self::tokenResponse())), 'oaiapp_issued', authorize: $authorize);

        $this->assertSame('dynamic_agent_client', $authorize['client_id']);
        // Upstream's hint is "Pi"; pig introduces itself.
        $this->assertSame('pig', $authorize['agent_name_hint']);
        $this->assertSame('urn:uuid:' . self::DEVICE_ID, $authorize['ext_agent_host_id']);
        $this->assertSame(self::REQUIRED_SCOPE, $authorize['scope']);
        $this->assertSame('http://127.0.0.1:1455/auth/callback', $authorize['redirect_uri']);
        $this->assertSame('https://api.openai.com/v1', $authorize['resource']);
        $this->assertSame('S256', $authorize['code_challenge_method']);

        $form = $this->sentForm();
        $this->assertSame('oaiapp_issued', $form['client_id']);
        $this->assertSame('authorization-code', $form['code']);
        $this->assertSame('https://api.openai.com/v1', $form['resource']);
        $this->assertSame($authorize['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $form['code_verifier'], true)), '+/', '-_'), '='));

        $this->assertSame(
            ['access-token', 'refresh-token', 'oaiapp_issued', explode(' ', self::REQUIRED_SCOPE)],
            [$credentials?->access, $credentials?->refresh, $credentials?->clientId, $credentials?->scopes],
        );
    }

    public function testItUsesTheAppsAgentNameAsTheNameHint(): void
    {
        $authorize = [];
        self::login($this->flow($this->tokenEndpoint(self::tokenResponse())), 'oaiapp_issued', agentName: 'my-app', authorize: $authorize);

        $this->assertSame('my-app', $authorize['agent_name_hint']);
    }

    public function testItRejectsARegistrationWithoutAnIssuedClientId(): void
    {
        $flow = $this->flow($this->tokenEndpoint(self::tokenResponse()));

        $this->assertSame('OpenAI OAuth registration callback did not contain an issued client ID', self::thrown(static fn () => self::login($flow, null)));
        $this->assertSame('', $this->server?->received(), 'nothing was exchanged');
    }

    public function testItRejectsATokenResponseThatDidNotGrantDirectTokenUse(): void
    {
        $flow = $this->flow($this->tokenEndpoint(self::tokenResponse('openid profile email offline_access resource.invoke')));

        $this->assertSame('OpenAI OAuth grant did not include chatgpt.tokens.use.direct', self::thrown(static fn () => self::login($flow, 'oaiapp_issued')));
    }

    public function testItRequiresADeviceIdBeforeStartingAuthorization(): void
    {
        $flow = $this->flow('http://127.0.0.1:9');
        $authorize = [];

        $this->assertSame('Sign in with ChatGPT requires a device ID (UUID) for this installation', self::thrown(static fn () => self::login($flow, 'x', null, authorize: $authorize)));
        $this->assertSame('Sign in with ChatGPT requires a device ID (UUID) for this installation', self::thrown(static fn () => self::login($flow, 'x', 'not-a-uuid', authorize: $authorize)));
        $this->assertSame([], $authorize, 'authorization never started');
    }

    public function testARefreshMustRotateTheRefreshToken(): void
    {
        $response = self::tokenResponse();
        unset($response['refresh_token']);
        $flow = $this->flow($this->tokenEndpoint($response));

        $this->assertSame('OpenAI OAuth token response has invalid refresh_token', self::thrown(static fn () => Async::run(static fn () => $flow->refresh(self::connected()))));
    }

    public function testItRefreshesWithTheCredentialsIssuedClientIdAndStoresReplacementScopes(): void
    {
        $flow = $this->flow($this->tokenEndpoint(['access_token' => 'new-access', 'refresh_token' => 'new-refresh'] + self::tokenResponse()));
        $before = Timestamp::nowMs();

        $credentials = Async::run(static fn (): OauthCredentials => $flow->refresh(self::connected()));

        // `expires_in` is an hour; the credential expires three minutes early so it is renewed in time.
        $this->assertGreaterThanOrEqual($before + (3600 - 180) * 1000, $credentials->expires);
        $this->assertLessThanOrEqual(Timestamp::nowMs() + (3600 - 180) * 1000, $credentials->expires);

        $form = $this->sentForm();
        $this->assertSame(['refresh_token', 'oaiapp_existing', 'old-refresh', 'https://api.openai.com/v1'], [$form['grant_type'], $form['client_id'], $form['refresh_token'], $form['resource']]);
        $this->assertArrayNotHasKey('scope', $form);
        $this->assertSame(['new-access', 'new-refresh', 'oaiapp_existing', explode(' ', self::REQUIRED_SCOPE)], [$credentials->access, $credentials->refresh, $credentials->clientId, $credentials->scopes]);
    }

    public function testARenewalWithoutAnIssuedClientAsksToReconnect(): void
    {
        $this->assertSame(
            'Stored OpenAI OAuth credential does not contain an issued client ID; reconnect ChatGPT',
            self::thrown(static fn () => (new OpenAiChatGpt(authBaseUrl: 'http://127.0.0.1:9'))->refresh(new OauthCredentials('r', 'a', 0))),
        );
    }

    public function testTheBrowsersCallbackBringsTheIssuedClientAndClosesThePasteBox(): void
    {
        $base = $this->tokenEndpoint(self::tokenResponse());
        $port = self::freePort();
        $flow = new OpenAiChatGpt(authBaseUrl: $base, server: new CallbackServer($port, OpenAiChatGpt::CALLBACK_PATH, 'ChatGPT', null, issuesClientId: true));
        $pages = [];
        $boxClosed = false;

        $credentials = Async::run(static function () use ($flow, $port, &$pages, &$boxClosed): ?OauthCredentials {
            return $flow->login(
                static function (string $url) use ($port, &$pages): void {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    Async::spawn(static function () use ($port, $query, &$pages): void {
                        // Without the client the registration made, the page says so and the wait goes on.
                        $pages[] = self::get($port, OpenAiChatGpt::CALLBACK_PATH . '?code=c&state=' . $query['state']);
                        $pages[] = self::get($port, OpenAiChatGpt::CALLBACK_PATH . '?code=c&state=' . $query['state'] . '&client_id=oaiapp_cb');
                    });
                },
                static function (string $message, string $placeholder, bool $mayBeEmpty, ?AbortSignal $signal) use (&$boxClosed): ?string {
                    $closed = new Deferred();
                    $signal?->onAbort(static fn () => $closed->complete(null));
                    $closed->future->await();
                    $boxClosed = true;

                    return null;
                },
                null,
                null,
                self::DEVICE_ID,
            );
        });

        $this->assertStringStartsWith('HTTP/1.1 400', $pages[0]);
        $this->assertStringContainsString('did not contain an issued client ID', $pages[0]);
        $this->assertStringStartsWith('HTTP/1.1 200', $pages[1]);
        $this->assertTrue($boxClosed);
        $this->assertSame('oaiapp_cb', $credentials?->clientId);
        $this->assertSame('oaiapp_cb', $this->sentForm()['client_id']);
    }

    public function testAPastedCallbackMustBeTheWholeRedirect(): void
    {
        $this->assertSame('Paste the full callback URL from the browser', self::thrown(static fn () => OpenAiChatGpt::authorizationResultFromManualInput('authorization-code', 's')));
        $this->assertSame('The pasted callback URL must start with http://127.0.0.1:1455/auth/callback', self::thrown(static fn () => OpenAiChatGpt::authorizationResultFromManualInput('http://localhost:1455/auth/callback?code=c&state=s&client_id=x', 's')));
        $this->assertSame('ChatGPT authorization failed: access_denied', self::thrown(static fn () => OpenAiChatGpt::authorizationResultFromManualInput('http://127.0.0.1:1455/auth/callback?error=access_denied', 's')));
        $this->assertSame('OAuth state mismatch', self::thrown(static fn () => OpenAiChatGpt::authorizationResultFromManualInput('http://127.0.0.1:1455/auth/callback?code=c&state=other&client_id=x', 's')));
        $this->assertSame(['code' => 'c', 'clientId' => 'x'], OpenAiChatGpt::authorizationResultFromManualInput(' http://127.0.0.1:1455/auth/callback?code=c&state=s&client_id=x ', 's'));
    }

    public function testTheProviderRenewsThroughTheFlowAndSendsTheAccessToken(): void
    {
        $this->assertSame('OpenAI (ChatGPT subscription)', OauthProvider::OpenAi->label());
        $this->assertTrue(OauthProvider::OpenAi->isSubscription());
        $this->assertSame('tok', OauthProvider::OpenAi->apiKey(new OauthCredentials('r', 'tok', 0, clientId: 'c')));
    }

    private static function connected(): OauthCredentials
    {
        return new OauthCredentials('old-refresh', 'old-access', 0, clientId: 'oaiapp_existing', scopes: explode(' ', self::REQUIRED_SCOPE));
    }

    private static function thrown(\Closure $run): ?string
    {
        try {
            $run();
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** One GET on the loop, the whole answer back. */
    private static function get(int $port, string $target): string
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        self::assertNotFalse($client);
        fwrite($client, "GET {$target} HTTP/1.1\r\nhost: localhost\r\n\r\n");
        stream_set_blocking($client, false);
        $answer = '';

        while (!feof($client)) {
            $done = new Deferred();
            $watcher = Loop::get()->onReadable($client, static function () use ($done): void {
                if (!$done->isComplete()) {
                    $done->complete(true);
                }
            });
            $done->future->await();
            Loop::get()->cancel($watcher);
            $answer .= (string) fread($client, 8192);
        }

        fclose($client);

        return $answer;
    }
}
