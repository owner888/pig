<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Response;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Mcp\McpClient;
use Pig\Mcp\Protocol\McpError;
use Pig\Mcp\Transports\AuthProvider;
use Pig\Mcp\Transports\McpAuthRequiredError;
use Pig\Mcp\Transports\StreamableHttpTransport;

final class StreamableHttpTransportTest extends TestCase
{
    private FakeHttpMcpServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new FakeHttpMcpServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private function transport(?AuthProvider $auth = null, bool $getStream = true): StreamableHttpTransport
    {
        return new StreamableHttpTransport(
            $this->server->url(),
            ['X-Test' => 'yes'],
            $auth,
            new HttpClient(5.0),
            openGetStream: $getStream,
            reconnect: ['initialDelay' => 0.02, 'maxDelay' => 0.1, 'maxRetries' => 3],
        );
    }

    public function testAConversationWithJsonRepliesAndASession(): void
    {
        $this->server->sessionId = 'sess-42';
        $this->server->getStream = null;   // no GET stream: 405
        $client = new McpClient('pig-test', '0.0');
        $transport = $this->transport();

        $result = Async::run(function () use ($client, $transport): array {
            $client->connect($transport);

            try {
                return $client->callTool('echo', ['text' => 'hi']);
            } finally {
                $client->close();
            }
        });

        $this->assertSame('echo: hi', $result['content'][0]['text']);
        $this->assertSame('sess-42', $transport->sessionId());

        $methods = $this->server->methods();
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/call'], $methods);

        // The session id and the protocol version ride on every request after they are known,
        // the custom header on all of them, and the GET for the standing stream was tried once.
        $calls = array_values(array_filter($this->server->requests, static fn (array $r): bool => ($r['body']['method'] ?? null) === 'tools/call'));
        $this->assertSame('sess-42', $calls[0]['headers']['mcp-session-id']);
        $this->assertSame('2025-11-25', $calls[0]['headers']['mcp-protocol-version']);
        $this->assertSame('yes', $calls[0]['headers']['x-test']);
        // The standing stream is opened after `initialized`, in a fiber of its own, so where its
        // GET lands relative to the next POST is the loop's business; that it was tried is not.
        $this->assertContains('GET', array_column($this->server->requests, 'method'));

        // And close() said DELETE, so the server can forget the session.
        $this->assertSame('DELETE', end($this->server->requests)['method']);
    }

    public function testAnSseReplyIsReadOffTheStream(): void
    {
        $this->server->replyAs = 'sse';
        $this->server->getStream = null;
        $client = new McpClient('pig-test', '0.0');

        $result = Async::run(function () use ($client): array {
            $client->connect($this->transport());

            try {
                return $client->callTool('echo', ['text' => 'streamed']);
            } finally {
                $client->close();
            }
        });

        $this->assertSame('echo: streamed', $result['content'][0]['text']);
    }

    public function testTheStandingStreamCarriesWhatTheServerSaysOnItsOwnAndIsReopenedWhenItDrops(): void
    {
        $this->server->getStream = true;
        $client = new McpClient('pig-test', '0.0');
        $changed = 0;
        $client->onNotification('notifications/tools/list_changed', static function () use (&$changed): void {
            $changed++;
        });

        Async::run(function () use ($client, &$changed): void {
            $client->connect($this->transport());
            Async::delay(0.05);
            $this->assertSame(1, $this->server->openStreams(), 'one GET stream open');

            $this->server->pushNotification('notifications/tools/list_changed');
            Async::delay(0.05);
            $this->assertSame(1, $changed);

            // The server restarts: the stream drops, and is reopened with backoff.
            $this->server->dropStreams();
            Async::delay(0.2);
            $this->assertSame(1, $this->server->openStreams(), 'reopened');

            $this->server->pushNotification('notifications/tools/list_changed');
            Async::delay(0.05);
            $this->assertSame(2, $changed, 'and it still carries notifications');

            $client->close();
        });
    }

    public function testAResponseStreamCutBeforeTheReplyIsResumedWithLastEventId(): void
    {
        $this->server->replyAs = 'sse';
        $this->server->getStream = true;
        $this->server->cutStreamAfter = 2;
        $client = new McpClient('pig-test', '0.0');

        // When the transport comes back for the rest with `Last-Event-ID`, the server hands over
        // the answer it owes on that stream.
        Loop::get()->delay(0.1, fn () => $this->server->deliverPendingOnStreams());
        Loop::get()->delay(0.2, fn () => $this->server->deliverPendingOnStreams());

        $result = Async::run(function () use ($client): array {
            $client->connect($this->transport(getStream: false));

            try {
                return $client->callTool('echo', ['text' => 'resumed']);
            } finally {
                $client->close();
            }
        });

        $this->assertSame('echo: resumed', $result['content'][0]['text']);

        $gets = array_values(array_filter($this->server->requests, static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertNotSame([], $gets, 'it came back for the rest');
        $this->assertArrayHasKey('last-event-id', $gets[0]['headers']);
    }

    public function testA401IsHandedToTheAuthProviderOnceAndTheRequestRetried(): void
    {
        $this->server->requireToken = 'secret';
        $this->server->getStream = null;
        $provider = new class () implements AuthProvider {
            public ?string $current = null;

            public int $asked = 0;

            public function token(): ?string
            {
                return $this->current;
            }

            public function onUnauthorized(Response $response, string $serverUrl, ?string $token): void
            {
                $this->asked++;
                $this->current = 'secret';   // a sign-in, in miniature
            }
        };
        $client = new McpClient('pig-test', '0.0');

        Async::run(function () use ($client, $provider): void {
            $client->connect($this->transport($provider));
            $client->ping();
            $client->close();
        });

        $this->assertSame(1, $provider->asked, 'asked once, on the first 401, and never again');
        $this->assertSame('Bearer secret', $this->server->requests[1]['headers']['authorization'], 'the retry carried the token');
        $this->assertSame('initialize', $this->server->requests[1]['body']['method']);
    }

    public function testA401WithNoProviderIsTheNamedError(): void
    {
        $this->server->requireToken = 'secret';
        $client = new McpClient('pig-test', '0.0');

        try {
            Async::run(fn () => $client->connect($this->transport()));
            $this->fail('expected a refusal');
        } catch (McpAuthRequiredError $error) {
            $this->assertSame(401, $error->status);
            $this->assertSame('Bearer realm="mcp"', $error->wwwAuthenticate);
        }
    }

    public function testAServerErrorReplyIsStillAnAnswer(): void
    {
        $this->server->getStream = null;
        $client = new McpClient('pig-test', '0.0');

        try {
            Async::run(function () use ($client): void {
                $client->connect($this->transport());
                $client->request('nope/nothing');
            });
            $this->fail('expected the server\'s error');
        } catch (McpError $error) {
            $this->assertSame(-32601, $error->rpcCode);
        }
    }
}
