<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Mcp;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Mcp\McpStdio;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use RuntimeException;

/**
 * The same server on stdio: lines in, lines out, and the host closing the pipe ends it.
 *
 * `McpServerTest` covers the methods; what is under test here is the wire — one message per
 * line both ways, a line that is not JSON answered rather than fatal, and the ask's progress
 * and result as two lines in order.
 */
final class McpStdioTest extends TestCase
{
    private Agent $agent;

    private AgentSession $session;

    private McpStdio $server;

    /** @var resource the server's stdin */
    private $serverIn;

    /** @var resource the host's end of the server's stdin */
    private $hostIn;

    /** @var resource what the server wrote to stdout */
    private $out;

    private string $cwd;

    /** @var list<string> */
    private array $answers = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-mcp-stdio-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->cwd . '-home');
        $this->answers = [];

        $this->agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
        $this->agent->setModel(new Model('claude-test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, false));
        $this->agent->setTools([]);
        $this->session = new AgentSession($this->agent, $this->cwd, null);

        [$this->hostIn, $this->serverIn] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0) ?: throw new RuntimeException('no pair');
        $this->out = fopen('php://temp', 'w+') ?: throw new RuntimeException('no temp');
        $this->server = new McpStdio($this->session, $this->serverIn, $this->out);
        $this->server->start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        $this->session->dispose();
        fclose($this->hostIn);
        fclose($this->out);
        putenv('PIG_HOME');
        self::remove($this->cwd);
        self::remove($this->cwd . '-home');
    }

    public function testInitializeAndToolsListAreOneLineEach(): void
    {
        $lines = $this->exchange([
            self::rpc(1, 'initialize', ['protocolVersion' => '2025-06-18']),
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            self::rpc(2, 'tools/list'),
        ], expect: 2);

        $this->assertSame('2025-06-18', $lines[0]['result']['protocolVersion']);
        $this->assertSame(['ask'], array_column($lines[1]['result']['tools'], 'name'));
    }

    public function testAskAnswersWithProgressThenTheResult(): void
    {
        $this->answers = ['forty-two'];

        $lines = $this->exchange([
            self::rpc(3, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => '6*7?'], '_meta' => ['progressToken' => 'p']]),
        ], expect: 2);

        $this->assertSame('notifications/progress', $lines[0]['method']);
        $this->assertSame('forty-two', $lines[0]['params']['message']);
        $this->assertSame(3, $lines[1]['id']);
        $this->assertSame('forty-two', $lines[1]['result']['content'][0]['text']);
    }

    public function testALineThatIsNotJsonIsAParseErrorAndTheNextLineStillWorks(): void
    {
        $lines = $this->exchange(['nope', self::rpc(4, 'ping')], expect: 2);

        $this->assertSame(-32700, $lines[0]['error']['code']);
        $this->assertNull($lines[0]['id']);
        $this->assertSame(4, $lines[1]['id']);
    }

    public function testAnAskIsATurnInTheSessionFileItWasStartedWith(): void
    {
        // `--session <file>` / `-c` resolve in `bin/pig` before the mode is picked, so what the
        // mode gets is a session over an existing store — the same thing this builds by hand.
        $store = SessionManager::create($this->cwd);
        $path = $store->path;

        $this->server->stop();
        $this->session->dispose();
        $this->session = new AgentSession($this->agent, $this->cwd, $store);
        $this->server = new McpStdio($this->session, $this->serverIn, $this->out);
        $this->server->start();

        $this->answers = ['kept'];
        $this->exchange([self::rpc(5, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'remember this']])], expect: 1);

        $texts = [];

        foreach (SessionManager::open($path)->messages() as $message) {
            if ($message instanceof AssistantMessage) {
                $texts[] = $message->content[0]->text;
            }
        }

        $this->assertSame(['kept'], $texts, 'the turn is in the file a later `--session` would open');
    }

    public function testClosingStdinStopsTheLoop(): void
    {
        fclose($this->hostIn);
        $this->hostIn = fopen('php://memory', 'r') ?: throw new RuntimeException('no stand-in');

        // Returns only because the server stopped the loop on EOF; a hang here is the failure.
        Async::run(function (): void {
            Async::delay(5.0);
        });

        $this->assertTrue(true);
    }

    /**
     * @param list<string> $lines what the host writes, one per line
     * @return list<array<string, mixed>> what the server wrote back, decoded
     */
    private function exchange(array $lines, int $expect): array
    {
        fwrite($this->hostIn, implode("\n", $lines) . "\n");

        return Async::run(function () use ($expect): array {
            $deadline = microtime(true) + 5.0;

            while (microtime(true) < $deadline) {
                $written = $this->written();

                if (count($written) >= $expect) {
                    return $written;
                }

                Async::delay(0.01);
            }

            throw new RuntimeException("Only " . count($this->written()) . " of {$expect} lines came back");
        });
    }

    /** @return list<array<string, mixed>> */
    private function written(): array
    {
        rewind($this->out);
        $raw = stream_get_contents($this->out) ?: '';
        fseek($this->out, 0, SEEK_END);

        $lines = [];

        foreach (explode("\n", trim($raw)) as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $params */
    private static function rpc(int $id, string $method, array $params = []): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]);
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $text): void {
            $stream->push(new StartEvent($this->message('')));
            $stream->push(new TextStartEvent(0, $this->message('')));
            $stream->push(new TextDeltaEvent(0, $text, $this->message($text)));
            $stream->push(new TextEndEvent(0, $text, $this->message($text)));
            $stream->push(new DoneEvent(StopReason::Stop, $this->message($text)));
            $stream->end();
        });

        return $stream;
    }

    private function message(string $text): AssistantMessage
    {
        return new AssistantMessage($text === '' ? [] : [new TextContent($text)], Api::AnthropicMessages, 'anthropic', 'claude-test', new Usage(), StopReason::Stop);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
