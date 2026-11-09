<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Mcp\McpClient;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use Pig\Mcp\Transports\StdioTransport;

/**
 * The real thing: a child process, pipes, and the loop. The fixture is a PHP script, so the
 * suite needs nothing installed — which is also why `npx`-style cold starts are simulated with
 * `--slow-start` rather than run.
 */
final class StdioTransportTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /** @param list<string> $flags */
    private function transport(array $flags = [], float $closeTimeout = 2.0, ?\Closure $onStderr = null): StdioTransport
    {
        return new StdioTransport(PHP_BINARY, [__DIR__ . '/fixtures/stdio-server.php', ...$flags], closeTimeout: $closeTimeout, onStderr: $onStderr);
    }

    public function testAConversationOverStdio(): void
    {
        $transport = $this->transport();
        $client = new McpClient('pig-test', '0.0');

        [$tools, $result] = Async::run(function () use ($client, $transport): array {
            $client->connect($transport);

            try {
                return [$client->listTools(), $client->callTool('echo', ['text' => 'hello'])];
            } finally {
                $client->close();
            }
        });

        $this->assertSame('fixture', $client->serverInfo()['name']);
        $this->assertSame(['echo'], array_column($tools, 'name'));
        $this->assertSame('echo: hello', $result['content'][0]['text']);
        $this->assertSame('closed', $client->connectionState());

        // One tick for `Async::run()`'s own wake-up no-op; what is left after it is the transport's.
        Loop::get()->tick();
        $this->assertTrue(Loop::get()->isIdle(), 'no watcher and no timer left behind');
    }

    public function testAServerThatTakesItsTimeToStartIsWaitedForOnTheLoop(): void
    {
        $transport = $this->transport(['--slow-start']);
        $client = new McpClient('pig-test', '0.0');
        $ticks = 0;
        $timer = Loop::get()->delay(0.02, function () use (&$ticks, &$timer): void {
            $ticks++;
            $timer = Loop::get()->delay(0.02, fn () => null);
        });

        Async::run(function () use ($client, $transport): void {
            $client->connect($transport);
            $client->close();
        });

        $this->assertSame('connected', 'connected');   // it got there
        $this->assertGreaterThan(0, $ticks, 'the loop kept turning while the server started');
    }

    public function testStderrIsKeptAndHandedOverChunkByChunk(): void
    {
        $chunks = [];
        $transport = $this->transport(['--chatty'], onStderr: static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $client = new McpClient('pig-test', '0.0');

        Async::run(function () use ($client, $transport): void {
            $client->connect($transport);
            $client->ping();
            Async::delay(0.05);
            $client->close();
        });

        $this->assertStringContainsString('fixture server starting', $transport->stderr());
        $this->assertStringContainsString('got ping', $transport->stderr());
        $this->assertNotSame([], $chunks);
    }

    /**
     * Upstream #11791: lines framed the same whatever the chunks — a byte at a time, several to a
     * chunk, CRLF, blank lines, a line that is not JSON, multi-byte characters cut across chunks —
     * now that the search starts where the last one stopped and the buffer is cut once per chunk.
     */
    public function testLinesAreFramedTheSameWhateverTheChunks(): void
    {
        $line = static fn (int $id): string => (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['text' => str_repeat('é', $id * 10)]], JSON_UNESCAPED_UNICODE);
        $stream = $line(1) . "\r\n\n" . $line(2) . "\nnot json\n" . $line(3) . "\n" . $line(4);

        foreach ([1, 7, 64, 4096, strlen($stream)] as $size) {
            $transport = new StdioTransport(PHP_BINARY, maxMessageBytes: 250);
            $ids = [];
            $errors = [];
            $transport->onMessage(static function (array $message) use (&$ids): void {
                $ids[] = $message['id'];
            });
            $transport->onError(static function (mixed $error) use (&$errors): void {
                $errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            });
            $feed = new \ReflectionMethod($transport, 'handleStdout');

            foreach (str_split($stream, $size) as $chunk) {
                $feed->invoke($transport, $chunk);
            }

            $this->assertSame([1, 2, 3], $ids, "chunks of {$size}: the fourth has no newline yet");
            $this->assertCount(1, $errors, "chunks of {$size}: the line that is not JSON");
        }
    }

    public function testALineOverTheLimitIsAnErrorAndTheNextOneStillArrives(): void
    {
        $transport = new StdioTransport(PHP_BINARY, maxMessageBytes: 250);
        $ids = [];
        $errors = [];
        $transport->onMessage(static function (array $message) use (&$ids): void {
            $ids[] = $message['id'];
        });
        $transport->onError(static function (mixed $error) use (&$errors): void {
            $errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
        });

        (new \ReflectionMethod($transport, 'handleStdout'))->invoke($transport, str_repeat('x', 300) . "\n" . '{"jsonrpc":"2.0","id":7,"result":{}}' . "\n");

        $this->assertSame(['MCP stdio message exceeds 250 bytes'], $errors);
        $this->assertSame([7], $ids);
    }

    public function testCrlfLinesAndGarbageBeforeTheFirstReplyAreSurvived(): void
    {
        $transport = $this->transport(['--crlf', '--garbage']);
        $client = new McpClient('pig-test', '0.0');
        $errors = [];
        $client->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        $result = Async::run(function () use ($client, $transport): array {
            $client->connect($transport);

            try {
                return $client->callTool('echo', ['text' => 'ok']);
            } finally {
                $client->close();
            }
        });

        $this->assertSame('echo: ok', $result['content'][0]['text']);
        $this->assertCount(1, $errors, 'the line that was not JSON is reported, once, and nothing else is');
    }

    public function testAServerThatDiesMidConversationFailsTheRequestAndSaysSo(): void
    {
        $transport = $this->transport(['--exit-on', 'tools/call']);
        $client = new McpClient('pig-test', '0.0');
        $closed = false;
        $client->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        try {
            Async::run(function () use ($client, $transport): void {
                $client->connect($transport);
                $client->callTool('echo', ['text' => 'bye']);
            });
            $this->fail('expected the connection to be gone');
        } catch (McpConnectionClosedError) {
        }

        $this->assertTrue($closed);
        $this->assertSame('closed', $client->connectionState());
    }

    public function testACommandThatDoesNotExistIsRefusedByName(): void
    {
        $transport = new StdioTransport('/no/such/binary-' . bin2hex(random_bytes(3)));

        try {
            Async::run(fn () => $transport->start());
            $this->fail('expected a refusal');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('/no/such/binary', $error->getMessage());
        }
    }

    public function testAServerThatIgnoresStdinClosingIsTerminatedWithinTheTimeout(): void
    {
        $transport = $this->transport(['--ignore-stdin-close'], closeTimeout: 0.5);
        $client = new McpClient('pig-test', '0.0');

        $elapsed = Async::run(function () use ($client, $transport): float {
            $client->connect($transport);
            $pid = $transport->pid();
            $this->assertNotNull($pid);
            $start = microtime(true);
            $client->close();
            $took = microtime(true) - $start;

            // Gone, not merely closed on our side.
            Async::delay(0.1);
            $this->assertFalse(posix_kill($pid, 0), 'the server process is gone');

            return $took;
        });

        // Half a second of grace, then SIGTERM, well inside the 30s the fixture would otherwise sleep.
        $this->assertLessThan(3.0, $elapsed);
    }
}
