<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Pig\Async\Loop;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Protocols\Sse;
use Pig\Mcp\Protocol\JsonRpc;
use Throwable;

/**
 * pig as an MCP server over Streamable HTTP. The methods and the tool are `McpDispatcher`'s;
 * this is the wire: one path, the session id, and SSE for the answer that takes a while.
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

    private const float KEEPALIVE = 15.0;

    /** @var resource|null */
    private $serverSocket = null;

    private ?string $serverWatcher = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    private int $nextConnectionId = 0;

    private ?string $sessionId = null;

    private readonly McpDispatcher $dispatcher;

    public function __construct(
        AgentSession $session,
        public readonly int $port = 8089,
        public readonly string $host = '127.0.0.1',
    ) {
        $this->dispatcher = new McpDispatcher($session);
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

            case 'POST':
                break;

            default:
                $conn->sendResponse(405, ['Content-Type' => 'text/plain', 'Allow' => 'POST, DELETE'], 'Method Not Allowed');

                return;
        }

        $message = json_decode($req['body'], true);

        if (!JsonRpc::isObject($message)) {
            $this->sendJson($conn, 400, McpDispatcher::error(null, JsonRpc::PARSE_ERROR, 'Expected one JSON-RPC message'));

            return;
        }

        if (($message['method'] ?? null) === 'initialize') {
            $this->sessionId = bin2hex(random_bytes(16));
        }

        // What the answer looks like on the wire is decided by when it comes. One that arrives
        // while `dispatch()` is still on the stack — `initialize`, `ping`, a bad `tools/call` —
        // is a JSON document. One that does not — an `ask` waiting its turn, then running — is
        // a stream opened once `dispatch()` has returned without answering, and everything it
        // sends after that is an event on it.
        $headers = ($message['method'] ?? null) === 'initialize' ? ['Mcp-Session-Id' => (string) $this->sessionId] : [];
        $dispatching = true;
        $answeredNow = false;
        $keepalive = null;

        $send = function (array $reply) use ($conn, $headers, &$dispatching, &$answeredNow, &$keepalive): void {
            if ($dispatching) {
                $answeredNow = true;
                $this->sendJson($conn, 200, $reply, $headers);

                return;
            }

            if ($conn->isClosed()) {
                return;
            }

            $conn->send(['event' => 'message', 'data' => $reply]);

            if (JsonRpc::isResponse($reply)) {
                if ($keepalive !== null) {
                    Loop::get()->cancel($keepalive);
                    $keepalive = null;
                }

                $conn->closeAfterSend();
            }
        };

        $willAnswer = $this->dispatcher->dispatch($message, $send);
        $dispatching = false;

        if (!$willAnswer) {
            $conn->sendResponse(202, [], '');

            return;
        }

        if ($answeredNow) {
            return;
        }

        Sse::open($conn);

        // A comment every 15 s while the turn waits or runs, so nothing between here and the
        // caller decides an idle stream is a dead one.
        $tick = function () use ($conn, &$keepalive, &$tick): void {
            if ($conn->isClosed()) {
                $keepalive = null;

                return;
            }

            $conn->send(['comment' => 'keep-alive']);
            $keepalive = Loop::get()->delay(self::KEEPALIVE, $tick);
        };
        $keepalive = Loop::get()->delay(self::KEEPALIVE, $tick);
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function sendJson(Connection $conn, int $status, array $message, array $headers = []): void
    {
        $conn->sendResponse($status, ['Content-Type' => 'application/json', ...$headers], McpDispatcher::encode($message));
    }
}
