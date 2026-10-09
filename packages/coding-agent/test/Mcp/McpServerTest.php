<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Mcp;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
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
use Pig\Async\Socket;
use Pig\CodingAgent\Mcp\McpServer;
use Pig\CodingAgent\Session\AgentSession;
use RuntimeException;

/**
 * pig as a server, from the client's side of the socket.
 *
 * A real listener on a loopback port and a real `Socket` into it, so what is asserted is the
 * bytes an MCP client would read — the status line, the headers the spec names, the SSE frames
 * — and not a method's return value. The model is scripted, as `PrintModeTest` scripts it.
 */
final class McpServerTest extends TestCase
{
    private AgentSession $session;

    private McpServer $server;

    private string $cwd;

    /** @var list<string> one per model call */
    private array $answers = [];

    private ?string $failure = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-mcp-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->cwd . '-home');
        $this->answers = [];
        $this->failure = null;

        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
        $agent->setModel(new Model('claude-test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, false));
        $agent->setTools([]);

        $this->session = new AgentSession($agent, $this->cwd, null);
        $this->server = new McpServer($this->session, self::freePort());
        $this->server->start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        $this->session->dispose();
        putenv('PIG_HOME');
        self::remove($this->cwd);
        self::remove($this->cwd . '-home');
    }

    // ---- the plain requests ------------------------------------------------------------------

    public function testInitializeIssuesASessionIdAndNamesTheOneTool(): void
    {
        $response = $this->post(self::rpc(1, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']]));

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response['headers']['mcp-session-id']);

        $body = json_decode($response['body'], true);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion'], 'a version it supports is the one it answers with');
        $this->assertSame('pig', $body['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $body['result']['capabilities']);

        $listed = json_decode($this->post(self::rpc(2, 'tools/list'), $response['headers']['mcp-session-id'])['body'], true);
        $this->assertSame(['ask'], array_column($listed['result']['tools'], 'name'));
        $this->assertSame(['prompt'], $listed['result']['tools'][0]['inputSchema']['required']);
    }

    public function testAVersionItDoesNotKnowGetsItsLatest(): void
    {
        $body = json_decode($this->post(self::rpc(1, 'initialize', ['protocolVersion' => '2031-01-01']))['body'], true);

        $this->assertSame('2025-11-25', $body['result']['protocolVersion']);
    }

    public function testANotificationIsAcknowledgedWithNoBody(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $this->assertSame(202, $response['status']);
        $this->assertSame('', $response['body']);
    }

    public function testAStaleSessionIdIsNotFoundSoTheClientStartsOver(): void
    {
        $this->post(self::rpc(1, 'initialize'));

        $this->assertSame(404, $this->post(self::rpc(2, 'ping'), 'deadbeef')['status']);
    }

    public function testDeleteForgetsTheSessionAndGetHasNoStreamToOffer(): void
    {
        $id = $this->post(self::rpc(1, 'initialize'))['headers']['mcp-session-id'];

        $this->assertSame(200, $this->request('DELETE', '', $id)['status']);
        $this->assertSame(404, $this->post(self::rpc(2, 'ping'), $id)['status'], 'forgotten is not found');
        $this->assertSame(405, $this->request('GET', '')['status']);
    }

    public function testBadJsonAndUnknownMethodsAreJsonRpcErrors(): void
    {
        $bad = $this->post('not json');
        $this->assertSame(400, $bad['status']);
        $this->assertSame(-32700, json_decode($bad['body'], true)['error']['code']);

        $unknown = json_decode($this->post(self::rpc(1, 'resources/list'))['body'], true);
        $this->assertSame(-32601, $unknown['error']['code']);

        $noSuchTool = json_decode($this->post(self::rpc(2, 'tools/call', ['name' => 'run', 'arguments' => []]))['body'], true);
        $this->assertSame(-32602, $noSuchTool['error']['code']);

        $noPrompt = json_decode($this->post(self::rpc(3, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => '']]))['body'], true);
        $this->assertSame(-32602, $noPrompt['error']['code']);
    }

    public function testAnotherPathIsNotFound(): void
    {
        $this->assertSame(404, $this->request('POST', self::rpc(1, 'ping'), null, '/api/prompt')['status']);
    }

    // ---- ask -----------------------------------------------------------------------------------

    public function testAskStreamsTheAnswerAsEventsAndEndsByClosing(): void
    {
        $this->answers = ['four'];

        $response = $this->post(self::rpc(7, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => '2+2?']]));

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/event-stream', $response['headers']['content-type']);
        $this->assertArrayNotHasKey('content-length', $response['headers'], 'the close is the end');

        $events = self::events($response['body']);
        $this->assertCount(1, $events, 'no progress asked for, so the result is the only event');
        $this->assertSame(7, $events[0]['id']);
        $this->assertSame([['type' => 'text', 'text' => 'four']], $events[0]['result']['content']);
        $this->assertArrayNotHasKey('isError', $events[0]['result']);
    }

    public function testAProgressTokenGetsTheTextAsItArrives(): void
    {
        $this->answers = ['hello there'];

        $response = $this->post(self::rpc(8, 'tools/call', [
            'name' => 'ask',
            'arguments' => ['prompt' => 'hi'],
            '_meta' => ['progressToken' => 'p1'],
        ]));

        $events = self::events($response['body']);
        $this->assertSame('notifications/progress', $events[0]['method']);
        $this->assertSame('p1', $events[0]['params']['progressToken']);
        $this->assertSame('hello there', $events[0]['params']['message']);
        $this->assertSame(strlen('hello there'), $events[0]['params']['progress']);
        $this->assertSame(8, end($events)['id'], 'the result is last');
    }

    public function testAFailedTurnIsAnErrorResultNotAProtocolError(): void
    {
        $this->answers = ['ignored'];
        $this->failure = 'quota exhausted';

        $events = self::events($this->post(self::rpc(9, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'hi']]))['body']);

        $this->assertTrue($events[0]['result']['isError']);
        $this->assertSame('quota exhausted', $events[0]['result']['content'][0]['text']);
    }

    public function testAnAskDuringAnAskWaitsItsTurnRatherThanFailing(): void
    {
        $this->answers = ['first', 'second'];

        [$a, $b] = Async::run(function (): array {
            $a = Async::spawn(fn () => $this->request('POST', self::rpc(1, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'one']])));
            $b = Async::spawn(fn () => $this->request('POST', self::rpc(2, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'two']])));

            return [$a->await(), $b->await()];
        });

        $this->assertSame('first', self::events($a['body'])[0]['result']['content'][0]['text']);
        $this->assertSame('second', self::events($b['body'])[0]['result']['content'][0]['text']);
        $this->assertArrayNotHasKey('isError', self::events($b['body'])[0]['result'], 'queued, not refused');
    }

    public function testTwoAsksAreOneConversation(): void
    {
        $this->answers = ['first', 'second'];

        $this->post(self::rpc(1, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'one']]));
        $this->post(self::rpc(2, 'tools/call', ['name' => 'ask', 'arguments' => ['prompt' => 'two']]));

        $texts = [];

        foreach ($this->session->messages() as $message) {
            if ($message instanceof AssistantMessage) {
                $texts[] = $message->content[0]->text;
            }
        }

        $this->assertSame(['first', 'second'], $texts);
    }

    // ---- the client's side -------------------------------------------------------------------

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function post(string $body, ?string $sessionId = null): array
    {
        return $this->request('POST', $body, $sessionId);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $body, ?string $sessionId = null, string $path = McpServer::PATH): array
    {
        $exchange = function () use ($method, $body, $sessionId, $path): array {
            $socket = Socket::connect('127.0.0.1', $this->server->port, timeout: 2.0);
            $head = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: application/json, text/event-stream\r\n"
                . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n"
                . ($sessionId !== null ? "Mcp-Session-Id: {$sessionId}\r\n" : '')
                . "\r\n";
            $socket->write($head . $body, 2.0);

            $raw = '';

            while (($chunk = $socket->read(65536, 5.0)) !== null) {
                $raw .= $chunk;

                $headEnd = strpos($raw, "\r\n\r\n");

                if ($headEnd === false) {
                    continue;
                }

                // A sized response is complete at its length; a stream only at the close.
                if (preg_match('/content-length:\s*(\d+)/i', substr($raw, 0, $headEnd), $m) === 1
                    && strlen($raw) >= $headEnd + 4 + (int) $m[1]) {
                    break;
                }
            }

            $socket->close();

            return self::parse($raw);
        };

        // From a test body there is no fiber yet, so one is made; from inside one, it is the one.
        return \Fiber::getCurrent() === null ? Async::run($exchange) : $exchange();
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function parse(string $raw): array
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $status = (int) explode(' ', array_shift($lines), 3)[1];
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** @return list<array<string, mixed>> the JSON of every `data:` event, comments dropped */
    private static function events(string $body): array
    {
        $events = [];

        foreach (explode("\n\n", trim($body)) as $frame) {
            $data = [];

            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $data[] = substr($line, 6);
                }
            }

            if ($data !== []) {
                $events[] = json_decode(implode("\n", $data), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $params */
    private static function rpc(int $id, string $method, array $params = []): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]);
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('no port');
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();
        $failure = $this->failure;

        Async::spawn(function () use ($stream, $text, $failure): void {
            $stream->push(new StartEvent($this->message('')));
            $stream->push(new TextStartEvent(0, $this->message('')));
            $stream->push(new TextDeltaEvent(0, $text, $this->message($text)));
            $stream->push(new TextEndEvent(0, $text, $this->message($text)));

            if ($failure !== null) {
                $stream->push(new ErrorEvent(StopReason::Error, new AssistantMessage([], Api::AnthropicMessages, 'anthropic', 'claude-test', new Usage(), StopReason::Error, $failure)));
                $stream->end();

                return;
            }

            $stream->push(new DoneEvent(StopReason::Stop, $this->message($text)));
            $stream->end();
        });

        return $stream;
    }

    private function message(string $text): AssistantMessage
    {
        return new AssistantMessage(
            $text === '' ? [] : [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );
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
