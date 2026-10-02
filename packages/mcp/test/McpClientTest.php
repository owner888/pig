<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Mcp\McpClient;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\McpAbortError;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use Pig\Mcp\Protocol\McpError;
use Pig\Mcp\Protocol\McpTimeoutError;
use Pig\Mcp\Protocol\Protocol;
use Pig\Mcp\Transports\InMemoryTransport;

final class McpClientTest extends TestCase
{
    private FakeMcpServer $server;

    private InMemoryTransport $clientEnd;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        ['client' => $this->clientEnd, 'server' => $serverEnd] = InMemoryTransport::pair();
        $this->server = new FakeMcpServer($serverEnd);
    }

    private function client(float $timeout = 30.0, mixed $roots = null): McpClient
    {
        return new McpClient('pig-test', '0.0', requestTimeout: $timeout, roots: $roots);
    }

    /**
     * `Async::run()` returns the moment the root fiber finishes, and the in-memory transport
     * delivers on the next tick — so the last thing the client *sent* is still in the queue when a
     * test looks at what the server received. One more turn of the loop is the fix, and a real
     * transport writes to a pipe where the question does not arise.
     */
    private function settle(): void
    {
        Loop::get()->delay(0.0, static fn () => null);
        Loop::get()->tick();
        Loop::get()->tick();
    }

    /** @return list<string> the methods the server was sent, in order */
    private function methods(): array
    {
        return array_values(array_filter(array_map(static fn (array $m): ?string => $m['method'] ?? null, $this->server->received)));
    }

    public function testConnectRunsTheHandshakeAndRemembersWhatTheServerSaid(): void
    {
        $this->server->instructions = 'Be kind to the tools.';
        $client = $this->client();

        $result = Async::run(fn () => $client->connect($this->clientEnd));
        $this->settle();

        $this->assertSame(['initialize', 'notifications/initialized'], $this->methods());
        $this->assertSame('connected', $client->connectionState());
        $this->assertSame(Protocol::LATEST_VERSION, $client->protocolVersion());
        $this->assertSame(['name' => 'fake', 'version' => '1.0'], $client->serverInfo());
        $this->assertSame(['tools' => ['listChanged' => true]], $client->serverCapabilities());
        $this->assertSame('Be kind to the tools.', $client->instructions());
        $this->assertSame('fake', $result['serverInfo']['name']);

        // What went over the wire for `initialize`.
        $init = $this->server->received[0];
        $this->assertSame(Protocol::LATEST_VERSION, $init['params']['protocolVersion']);
        $this->assertSame(['name' => 'pig-test', 'version' => '0.0'], $init['params']['clientInfo']);
    }

    public function testAnOlderProtocolVersionTheServerPicksIsAccepted(): void
    {
        $this->server->protocolVersion = '2024-11-05';
        $client = $this->client();

        Async::run(fn () => $client->connect($this->clientEnd));

        $this->assertSame('2024-11-05', $client->protocolVersion());
    }

    public function testAVersionNobodySupportsIsRefusedAndTheClientIsClosed(): void
    {
        $this->server->protocolVersion = '1999-01-01';
        $client = $this->client();

        try {
            Async::run(fn () => $client->connect($this->clientEnd));
            $this->fail('expected a refusal');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unsupported protocol version 1999-01-01', $error->getMessage());
        }

        $this->assertSame('closed', $client->connectionState());
    }

    public function testListToolsFollowsEveryPageAndCallToolSendsTheArguments(): void
    {
        $this->server->tools = array_map(static fn (int $n): array => ['name' => "tool{$n}", 'inputSchema' => ['type' => 'object']], range(1, 7));
        $this->server->pageSize = 3;
        $client = $this->client();

        [$tools, $result] = Async::run(function () use ($client): array {
            $client->connect($this->clientEnd);

            return [$client->listTools(), $client->callTool('tool2', ['path' => 'x'])];
        });

        $this->assertSame(['tool1', 'tool2', 'tool3', 'tool4', 'tool5', 'tool6', 'tool7'], array_column($tools, 'name'));
        $this->assertSame(3, count(array_filter($this->methods(), static fn (string $m): bool => $m === 'tools/list')), 'three pages');
        $this->assertSame('called tool2 with {"path":"x"}', $result['content'][0]['text']);

        // No arguments goes out without an `arguments` key; empty arguments as `{}` and not `[]`.
        Async::run(fn () => $client->callTool('tool1'));
        Async::run(fn () => $client->callTool('tool1', []));
        $calls = array_values(array_filter($this->server->received, static fn (array $m): bool => ($m['method'] ?? null) === 'tools/call'));
        $this->assertArrayNotHasKey('arguments', $calls[1]['params']);
        $this->assertInstanceOf(\stdClass::class, $calls[2]['params']['arguments']);
    }

    public function testAResultWithoutContentGetsAnEmptyContentList(): void
    {
        $this->server->handlers['tools/call'] = static fn (): array => ['structuredContent' => ['answer' => 42]];
        $client = $this->client();

        $result = Async::run(function () use ($client): array {
            $client->connect($this->clientEnd);

            return $client->callTool('t');
        });

        $this->assertSame([], $result['content']);
        $this->assertSame(['answer' => 42], $result['structuredContent']);
    }

    public function testAnErrorReplyBecomesAnMcpErrorWithTheServersCode(): void
    {
        $client = $this->client();

        try {
            Async::run(function () use ($client): void {
                $client->connect($this->clientEnd);
                $client->request('no/such');
            });
            $this->fail('expected the server\'s error');
        } catch (McpError $error) {
            $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $error->rpcCode);
            $this->assertStringContainsString('no/such', $error->getMessage());
        }

        $this->assertSame('connected', $client->connectionState(), 'an error reply is an answer, not a dropped connection');
    }

    public function testARequestTimesOutAndTheServerIsToldItWasCancelled(): void
    {
        $this->server->answerAfter['tools/call'] = 5.0;
        $client = $this->client(timeout: 0.1);

        try {
            Async::run(function () use ($client): void {
                $client->connect($this->clientEnd);
                $client->callTool('slow');
            });
            $this->fail('expected a timeout');
        } catch (McpTimeoutError $error) {
            $this->assertSame(100.0, $error->timeoutMs);
        }

        $this->settle();
        $cancelled = array_values(array_filter($this->server->received, static fn (array $m): bool => ($m['method'] ?? null) === 'notifications/cancelled'));
        $this->assertCount(1, $cancelled);
        $this->assertSame('Request timed out', $cancelled[0]['params']['reason']);
        $this->assertSame('connected', $client->connectionState(), 'one slow request does not close the connection');
    }

    public function testProgressNotificationsResetTheTimeout(): void
    {
        // 0.3s to answer, four progress reports along the way, 0.15s timeout: without the reset
        // the call would time out at 0.15; with it the last gap is 0.06s and the answer arrives.
        $this->server->answerAfter['tools/call'] = 0.3;
        $this->server->progressEvery = 4;
        $client = $this->client(timeout: 0.15);
        $seen = [];

        $result = Async::run(function () use ($client, &$seen): array {
            $client->connect($this->clientEnd);

            return $client->callTool('slow', null, ['onProgress' => static function (array $p) use (&$seen): void {
                $seen[] = $p['progress'];
            }]);
        });

        $this->assertSame([1, 2, 3, 4], $seen);
        $this->assertStringContainsString('called slow', $result['content'][0]['text']);

        // The token went out under `_meta`, which is how the server knew whom to tell.
        $call = array_values(array_filter($this->server->received, static fn (array $m): bool => ($m['method'] ?? null) === 'tools/call'))[0];
        $this->assertSame($call['id'], $call['params']['_meta']['progressToken']);
    }

    public function testAbortingARequestFailsItHereAndTellsTheServer(): void
    {
        $this->server->answerAfter['tools/call'] = 5.0;
        $client = $this->client();
        $controller = new AbortController();
        Loop::get()->delay(0.05, static fn () => $controller->abort('changed my mind'));

        try {
            Async::run(function () use ($client, $controller): void {
                $client->connect($this->clientEnd);
                $client->callTool('slow', null, ['signal' => $controller->signal]);
            });
            $this->fail('expected an abort');
        } catch (McpAbortError) {
        }

        $this->settle();
        $cancelled = array_values(array_filter($this->server->received, static fn (array $m): bool => ($m['method'] ?? null) === 'notifications/cancelled'));
        $this->assertCount(1, $cancelled);
        $this->assertSame('changed my mind', $cancelled[0]['params']['reason']);
    }

    public function testInitializeIsNeverCancelledOnTheWire(): void
    {
        // The spec forbids cancelling `initialize`, so a timeout there fails locally and says nothing.
        $this->server->answerAfter['initialize'] = 5.0;
        $client = $this->client(timeout: 0.05);

        try {
            Async::run(fn () => $client->connect($this->clientEnd));
            $this->fail('expected a timeout');
        } catch (McpTimeoutError) {
        }

        $this->assertNotContains('notifications/cancelled', $this->methods());
        $this->assertSame('closed', $client->connectionState());
    }

    public function testTheTransportClosingFailsEveryRequestInFlight(): void
    {
        $this->server->answerAfter['tools/call'] = 5.0;
        $client = $this->client();
        $closed = 0;
        $client->onClose(static function () use (&$closed): void {
            $closed++;
        });
        Loop::get()->delay(0.05, fn () => $this->server->transport->close());

        try {
            Async::run(function () use ($client): void {
                $client->connect($this->clientEnd);
                $client->callTool('slow');
            });
            $this->fail('expected the connection to be gone');
        } catch (McpConnectionClosedError) {
        }

        $this->assertSame(1, $closed, 'told once');
        $this->assertSame('closed', $client->connectionState());

        // And closing again is nothing.
        $client->close();
        $this->assertSame(1, $closed);
    }

    public function testTheClientAnswersPingAndRootsAndRefusesWhatItDoesNotKnow(): void
    {
        $client = $this->client(roots: [['uri' => 'file:///work', 'name' => 'work']]);
        $replies = [];

        Async::run(function () use ($client, &$replies): void {
            $client->connect($this->clientEnd);

            foreach (['ping', 'roots/list', 'elicitation/create'] as $method) {
                $this->server->request($method, null, static function (array $reply) use (&$replies, $method): void {
                    $replies[$method] = $reply;
                });
            }

            Async::delay(0.05);
        });

        $this->assertSame([], (array) $replies['ping']['result']);
        $this->assertSame([['uri' => 'file:///work', 'name' => 'work']], $replies['roots/list']['result']['roots']);
        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $replies['elicitation/create']['error']['code']);

        // Offering roots announces the capability at `initialize`.
        $this->assertArrayHasKey('roots', (array) $this->server->received[0]['params']['capabilities']);
    }

    public function testNotificationsReachTheirListenersAndAListenerThatThrowsIsReported(): void
    {
        $client = $this->client();
        $changed = 0;
        $errors = [];
        $client->onNotification('notifications/tools/list_changed', static function () use (&$changed): void {
            $changed++;
        });
        $client->onNotification('notifications/tools/list_changed', static function (): void {
            throw new \RuntimeException('a listener with a typo');
        });
        $client->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        Async::run(function () use ($client): void {
            $client->connect($this->clientEnd);
            $this->server->notify('notifications/tools/list_changed');
            Async::delay(0.02);
        });

        $this->assertSame(1, $changed);
        $this->assertSame(['a listener with a typo'], $errors);
    }

    public function testAResponseNobodyAskedForIsReportedNotFatal(): void
    {
        $client = $this->client();
        $errors = [];
        $client->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        Async::run(function () use ($client): void {
            $client->connect($this->clientEnd);
            $this->server->sendRaw(['jsonrpc' => '2.0', 'id' => 999, 'result' => []]);
            Async::delay(0.02);
        });

        $this->assertSame(['Received response for unknown MCP request 999'], $errors);
        $this->assertSame('connected', $client->connectionState());
    }

    public function testRequestingBeforeConnectingIsRefusedByName(): void
    {
        $client = $this->client();

        $this->expectException(McpConnectionClosedError::class);
        $this->expectExceptionMessage('MCP client is idle');

        Async::run(fn () => $client->ping());
    }
}
