<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\SessionInfoChangedEvent;
use Pig\CodingAgent\Session\AgentSession;
use Throwable;

/**
 * Micro HTTP & SSE server for Web UI, operating purely on Loop's stream_select.
 *
 * Implements Workerman-style non-blocking connections with zero external dependencies.
 */
final class HttpServer
{
    private mixed $serverSocket = null;
    private ?string $serverWatcher = null;
    private ?Closure $unsubscribeSession = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, Connection> */
    private array $sseClients = [];

    private int $nextConnectionId = 0;
    private bool $isRunning = false;

    public function __construct(
        private readonly AgentSession $session,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
    ) {
    }

    /**
     * Start the HTTP server on the async loop.
     *
     * @throws \RuntimeException on socket bind failure
     */
    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $address = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';

        set_error_handler(static fn () => true);

        try {
            $socket = stream_socket_server(
                $address,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new \RuntimeException("Could not bind Web UI server to {$address}: {$errstr} ({$errno})");
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

            $id = ++$this->nextConnectionId;
            $conn = new Connection(
                $client,
                onMessage: fn (Connection $c, array $req) => $this->handleRequest($c, $req),
                onClose: fn (Connection $c) => $this->handleClose($id),
            );

            $this->connections[$id] = $conn;
            $watcher = Loop::get()->onReadable($client, static fn () => $conn->onReadable());
            $conn->setReadableWatcher($watcher);
        });

        // Subscribe to agent session events to broadcast over SSE
        $this->unsubscribeSession = $this->session->subscribe(function (AgentEvent $event): void {
            $this->broadcastEvent($event);
        });

        $this->isRunning = true;
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->isRunning = false;

        if ($this->unsubscribeSession !== null) {
            ($this->unsubscribeSession)();
            $this->unsubscribeSession = null;
        }

        if ($this->serverWatcher !== null) {
            Loop::get()->cancel($this->serverWatcher);
            $this->serverWatcher = null;
        }

        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }

        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->sseClients = [];
    }

    private function handleClose(int $connectionId): void
    {
        unset($this->connections[$connectionId]);
        unset($this->sseClients[$connectionId]);
    }

    /**
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function handleRequest(Connection $conn, array $req): void
    {
        $path = $req['path'];

        if ($req['method'] === 'OPTIONS') {
            $conn->sendResponse(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            ], '');

            return;
        }

        // 1. Static HTML page
        if ($path === '/' || $path === '/index.html') {
            $htmlFile = __DIR__ . '/assets/index.html';
            $html = is_file($htmlFile) ? (file_get_contents($htmlFile) ?: 'HTML missing') : 'PIG Web UI';
            $conn->sendResponse(200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ], $html);

            return;
        }

        // 2. Server-Sent Events stream
        if ($path === '/api/events') {
            $conn->send("HTTP/1.1 200 OK\r\n"
                . "Content-Type: text/event-stream\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Connection: keep-alive\r\n"
                . "Access-Control-Allow-Origin: *\r\n\r\n");

            // Register as SSE subscriber
            $id = array_search($conn, $this->connections, true);
            if ($id !== false) {
                $this->sseClients[$id] = $conn;
            }

            return;
        }

        // 3. User message submission
        if ($path === '/api/prompt' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $message = is_array($data) ? ($data['message'] ?? '') : '';

            if (trim($message) !== '') {
                Async::spawn(function () use ($message): void {
                    try {
                        if ($this->session->isStreaming()) {
                            $this->session->steer($message);
                        } else {
                            $this->session->prompt($message);
                        }
                    } catch (Throwable) {}
                });
            }

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode(['ok' => true]));

            return;
        }

        // 4. Abort turn
        if ($path === '/api/abort' && $req['method'] === 'POST') {
            Async::spawn(function (): void {
                $this->session->abort();
            });

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode(['ok' => true]));

            return;
        }

        // 5. Session state telemetry
        if ($path === '/api/state') {
            $stats = $this->session->stats();
            $model = $this->session->model();
            $window = $model?->contextWindow ?? 0;
            $percent = $window > 0 ? (int) ($stats->input / $window * 100) : 0;

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode([
                'cwd' => basename($this->session->cwd()),
                'branch' => 'main',
                'sessionName' => $this->session->getSessionName(),
                'model' => $model?->id ?? 'no-model',
                'provider' => $model?->provider ?? 'unknown',
                'thinkingLevel' => $this->session->thinkingLevel()?->value ?? 'off',
                'tokensIn' => $stats->input,
                'tokensOut' => $stats->output,
                'cost' => number_format($stats->cost, 3),
                'contextWindow' => $window < 1000000 ? round($window / 1000) . 'k' : sprintf('%.1fM', $window / 1000000),
                'contextPercent' => sprintf('%.1f', $percent),
            ]));

            return;
        }

        // 6. Messages history
        if ($path === '/api/messages') {
            $history = [];
            foreach ($this->session->messages() as $m) {
                if ($m instanceof UserMessage) {
                    $history[] = [
                        'role' => 'user',
                        'content' => array_map(static fn ($c) => ['type' => 'text', 'text' => $c->text], $m->content),
                    ];
                } elseif ($m instanceof AssistantMessage) {
                    $blocks = [];
                    foreach ($m->content as $c) {
                        if ($c instanceof TextContent) {
                            $blocks[] = ['type' => 'text', 'text' => $c->text];
                        }
                    }
                    $history[] = [
                        'role' => 'assistant',
                        'content' => $blocks,
                    ];
                }
            }

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode($history));

            return;
        }

        $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Not Found: {$path}");
    }

    private function broadcastEvent(AgentEvent $event): void
    {
        if ($this->sseClients === []) {
            return;
        }

        $payload = match (true) {
            $event instanceof AgentStartEvent => ['type' => 'agent_start'],
            $event instanceof TurnStartEvent => ['type' => 'turn_start'],
            $event instanceof TurnEndEvent => ['type' => 'turn_end'],
            $event instanceof AgentEndEvent => ['type' => 'agent_end'],
            $event instanceof MessageStartEvent => ['type' => 'message_start'],
            $event instanceof MessageEndEvent => ['type' => 'message_end'],
            $event instanceof MessageUpdateEvent => $this->serializeMessageUpdate($event),
            $event instanceof ToolExecutionStartEvent => [
                'type' => 'tool_execution_start',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'arguments' => $event->arguments,
            ],
            $event instanceof ToolExecutionEndEvent => [
                'type' => 'tool_execution_end',
                'toolCallId' => $event->toolCallId,
                'result' => $event->result,
                'isError' => $event->isError,
            ],
            $event instanceof SessionInfoChangedEvent => [
                'type' => 'session_info_changed',
                'name' => $event->name,
            ],
            default => null,
        };

        if ($payload === null) {
            return;
        }

        $line = 'data: ' . json_encode($payload) . "\n\n";

        foreach ($this->sseClients as $id => $client) {
            $client->send($line);
        }
    }

    /** @return array<string, mixed>|null */
    private function serializeMessageUpdate(MessageUpdateEvent $event): ?array
    {
        $raw = $event->assistantMessageEvent;

        if ($raw instanceof TextDeltaEvent) {
            return ['type' => 'text_delta', 'delta' => $raw->delta];
        }

        if ($raw instanceof ThinkingDeltaEvent) {
            return ['type' => 'thinking_delta', 'delta' => $raw->delta];
        }

        return null;
    }
}
