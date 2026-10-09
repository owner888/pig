<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Pig\Agent\AgentEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Version;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Protocols\Sse;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\Protocol;
use Throwable;

/**
 * pig as an MCP server: one tool, `ask`, over Streamable HTTP.
 *
 * The other side of `Pig\Mcp` — that package is pig *using* servers; this is pig *being* one, so
 * another agent (Claude Code, another pig) can hand it a question and get the answer back the
 * way it would from any tool. The conversation behind the tool is this process's one
 * `AgentSession`: every `ask` is a turn in it, so the second question can refer to the first.
 *
 * Streamable HTTP (MCP 2025-03-26 and later), the smallest reading of it:
 *
 * - Every message is a `POST` to one path. A notification or a client response is acknowledged
 *   with `202` and no body. `initialize`, `ping` and `tools/list` are answered as one JSON
 *   document; `tools/call` as a `text/event-stream`, because the turn takes as long as it
 *   takes and a stream is what keeps the connection honest while it does — `notifications/
 *   progress` carries the text as it arrives when the caller asked for progress, a comment
 *   line keeps the stream alive when it does not, and the result is the last event.
 * - `Mcp-Session-Id` is issued on `initialize` and checked on everything after: a client still
 *   holding the id of a process that has since restarted gets `404`, which the spec says means
 *   "start over with `initialize`". `DELETE` forgets the id; the conversation stays.
 * - `GET` is `405`: the server has nothing to say that is not an answer to a request, so there
 *   is no standalone stream to open.
 *
 * The listening side is `Web\HttpServer`'s shape with the page, the pool and the WebSocket
 * taken out: the same `Connection`, the same `Http` framing, and `Sse` where the Web UI has
 * `Websocket`.
 */
final class McpServer
{
    public const string PATH = '/mcp';

    public const string TOOL = 'ask';

    private const float KEEPALIVE = 15.0;

    /** @var resource|null */
    private $serverSocket = null;

    private ?string $serverWatcher = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    private int $nextConnectionId = 0;

    private ?string $sessionId = null;

    private string $protocolVersion = Protocol::LATEST_VERSION;

    public function __construct(
        private readonly AgentSession $session,
        public readonly int $port = 8089,
        public readonly string $host = '127.0.0.1',
    ) {
    }

    /** @throws \RuntimeException when the port cannot be bound */
    public function start(): void
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';

        set_error_handler(static fn () => true);

        try {
            $socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new \RuntimeException("Could not bind MCP server to {$address}: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        $this->serverSocket = $socket;

        $this->serverWatcher = Loop::get()->onReadable($socket, function (): void {
            set_error_handler(static fn () => true);

            try {
                $client = stream_socket_accept($this->serverSocket, 0);
            } finally {
                restore_error_handler();
            }

            if ($client === false) {
                return;
            }

            $this->accept($client);
        });
    }

    /** @param resource $client */
    public function accept($client): void
    {
        $id = ++$this->nextConnectionId;
        $conn = new Connection(
            $client,
            onMessage: function (Connection $c, mixed $data): void {
                if (is_array($data)) {
                    $this->handle($c, $data);
                }
            },
            onClose: function () use ($id): void {
                unset($this->connections[$id]);
            },
            onError: static function (Connection $c, Throwable $e): void {
                Logger::error('[McpServer] ' . $e->getMessage());

                if (!$c->isClosed()) {
                    $c->sendResponse(500, ['Content-Type' => 'text/plain'], $e->getMessage());
                    $c->close();
                }
            },
        );

        $this->connections[$id] = $conn;
        $conn->setReadableWatcher(Loop::get()->onReadable($client, static fn () => $conn->onReadable()));
    }

    public function stop(): void
    {
        if ($this->serverWatcher !== null) {
            Loop::get()->cancel($this->serverWatcher);
            $this->serverWatcher = null;
        }

        foreach ($this->connections as $conn) {
            $conn->close();
        }

        $this->connections = [];

        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
        }

        $this->serverSocket = null;
    }

    /**
     * One HTTP request, as `Http::decode()` hands it over.
     *
     * @param array{method: string, path: string, headers: array<string, string>, body: string} $req
     */
    public function handle(Connection $conn, array $req): void
    {
        if ($req['path'] !== self::PATH) {
            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Not Found: {$req['path']}");

            return;
        }

        $sent = $req['headers']['mcp-session-id'] ?? null;

        if ($sent !== null && $sent !== $this->sessionId) {
            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'Session not found');

            return;
        }

        switch ($req['method']) {
            case 'DELETE':
                $this->sessionId = null;
                $conn->sendResponse(200, ['Content-Type' => 'text/plain'], '');

                return;

            case 'GET':
                $conn->sendResponse(405, ['Content-Type' => 'text/plain', 'Allow' => 'POST, DELETE'], 'Method Not Allowed');

                return;

            case 'POST':
                break;

            default:
                $conn->sendResponse(405, ['Content-Type' => 'text/plain', 'Allow' => 'POST, DELETE'], 'Method Not Allowed');

                return;
        }

        $message = json_decode($req['body'], true);

        if (!JsonRpc::isObject($message)) {
            $this->sendJson($conn, 400, self::error(null, JsonRpc::PARSE_ERROR, 'Expected one JSON-RPC message'));

            return;
        }

        if (!JsonRpc::isRequest($message)) {
            // A notification, or the client's answer to something — pig asks nothing, so there
            // is nothing to match it to. Both are acknowledged and dropped.
            $conn->sendResponse(202, [], '');

            return;
        }

        $id = $message['id'];
        $params = JsonRpc::isObject($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'initialize':
                $this->sessionId = bin2hex(random_bytes(16));
                $asked = $params['protocolVersion'] ?? null;
                $this->protocolVersion = is_string($asked) && in_array($asked, Protocol::SUPPORTED_VERSIONS, true)
                    ? $asked
                    : Protocol::LATEST_VERSION;

                $this->sendJson($conn, 200, self::result($id, [
                    'protocolVersion' => $this->protocolVersion,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'pig', 'version' => Version::current()],
                    'instructions' => 'Ask pig, a coding agent working in ' . $this->session->cwd()
                        . '. Each call is a turn in one conversation, so a follow-up can refer to an earlier answer.',
                ]), ['Mcp-Session-Id' => $this->sessionId]);

                return;

            case 'ping':
                $this->sendJson($conn, 200, self::result($id, new \stdClass()));

                return;

            case 'tools/list':
                $this->sendJson($conn, 200, self::result($id, ['tools' => [self::toolDefinition()]]));

                return;

            case 'tools/call':
                if (($params['name'] ?? null) !== self::TOOL) {
                    $this->sendJson($conn, 200, self::error($id, JsonRpc::INVALID_PARAMS, 'Unknown tool: ' . json_encode($params['name'] ?? null)));

                    return;
                }

                $prompt = $params['arguments']['prompt'] ?? null;

                if (!is_string($prompt) || trim($prompt) === '') {
                    $this->sendJson($conn, 200, self::error($id, JsonRpc::INVALID_PARAMS, '`prompt` must be a non-empty string'));

                    return;
                }

                $token = $params['_meta']['progressToken'] ?? null;
                $this->ask($conn, $id, $prompt, JsonRpc::isId($token) ? $token : null);

                return;

            default:
                $this->sendJson($conn, 200, self::error($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$message['method']}"));
        }
    }

    /**
     * The turn, as a stream: progress while it runs, the answer when it ends, then the close
     * that ends the body.
     */
    private function ask(Connection $conn, string|int|float $id, string $prompt, string|int|float|null $token): void
    {
        Sse::open($conn);

        if ($this->session->isStreaming()) {
            $conn->send(['event' => 'message', 'data' => self::result($id, self::toolResult(
                'pig is busy with another ask; try again when it has answered.',
                isError: true,
            ))]);
            $conn->closeAfterSend();

            return;
        }

        $progress = 0;
        $unsubscribe = $this->session->subscribe(function (AgentEvent $event) use ($conn, $token, &$progress): void {
            if ($token === null || $conn->isClosed()) {
                return;
            }

            if ($event instanceof MessageUpdateEvent && $event->assistantMessageEvent instanceof TextDeltaEvent) {
                $progress += strlen($event->assistantMessageEvent->delta);
                $conn->send(['event' => 'message', 'data' => [
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => ['progressToken' => $token, 'progress' => $progress, 'message' => $event->assistantMessageEvent->delta],
                ]]);
            }
        });

        $keepalive = null;
        $tick = function () use ($conn, &$keepalive, &$tick): void {
            if ($conn->isClosed()) {
                $keepalive = null;

                return;
            }

            $conn->send(['comment' => 'keep-alive']);
            $keepalive = Loop::get()->delay(self::KEEPALIVE, $tick);
        };
        $keepalive = Loop::get()->delay(self::KEEPALIVE, $tick);

        Async::spawn(function () use ($conn, $id, $prompt, $unsubscribe, &$keepalive): void {
            try {
                $this->session->prompt($prompt, [], 'rpc');
                $result = $this->answer();
            } catch (Throwable $e) {
                $result = self::toolResult($e->getMessage(), isError: true);
            } finally {
                $unsubscribe();

                if ($keepalive !== null) {
                    Loop::get()->cancel($keepalive);
                    $keepalive = null;
                }
            }

            if (!$conn->isClosed()) {
                $conn->send(['event' => 'message', 'data' => self::result($id, $result)]);
                $conn->closeAfterSend();
            }
        });
    }

    /**
     * The last answer as a tool result: its text blocks, or its error — `PrintMode::say()`'s
     * reading of the transcript, for a caller that is a program rather than a pipe.
     *
     * @return array{content: list<array{type: 'text', text: string}>, isError?: bool}
     */
    private function answer(): array
    {
        $messages = $this->session->messages();
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            return self::toolResult('');
        }

        if ($last->stopReason->isFailure()) {
            return self::toolResult($last->errorMessage ?? "Request {$last->stopReason->value}", isError: true);
        }

        $text = [];

        foreach ($last->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            }
        }

        return self::toolResult(implode("\n", $text));
    }

    /** @return array<string, mixed> */
    public static function toolDefinition(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Ask pig, a coding agent with its own tools (read, write, edit, bash) in its working directory. '
                . 'Returns the final answer as text. Calls share one conversation, so a follow-up can build on an earlier answer.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'What to ask or have done.'],
                ],
                'required' => ['prompt'],
            ],
        ];
    }

    /** @return array{content: list<array{type: 'text', text: string}>, isError?: bool} */
    private static function toolResult(string $text, bool $isError = false): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $text]]];

        if ($isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function result(string|int|float $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(string|int|float|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function sendJson(Connection $conn, int $status, array $message, array $headers = []): void
    {
        $conn->sendResponse(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
