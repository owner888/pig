<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\ToolExecutionUpdateEvent;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Models;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Rpc\RpcUi;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\AutoCompactionStartEvent;
use Pig\CodingAgent\Session\RetryEndEvent;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Web\Protocols\Websocket;
use Pig\Tui\Process;
use Throwable;

/**
 * Micro HTTP, SSE & WebSocket server for Web UI, operating purely on Loop's stream_select.
 *
 * Implements Workerman-style non-blocking connections and protocol decoupling with zero external dependencies.
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

    /** @var array<int, Connection> */
    private array $wsClients = [];

    private int $nextConnectionId = 0;
    private bool $isRunning = false;
    private readonly Auth $auth;

    /**
     * The hooks' and custom tools' UI, when this server is the mode — `RpcUi` over the browser.
     *
     * Null when the server was started from inside the terminal with `/web`: there the TUI is the
     * mode, it wired the hooks to its own dialogs, and a second UI would be two places asking one
     * question. Standalone `--mode web` had **no** UI at all until this — a `tool_call` guard got
     * `NoUi`, every `confirm()` was false, and every tool call was refused without a word.
     */
    private ?RpcUi $ui = null;

    public function __construct(
        private readonly AgentSession $session,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
        ?Auth $auth = null,
        private readonly ?HookRunner $hooks = null,
        private readonly ?CustomToolSet $customTools = null,
    ) {
        $this->auth = $auth ?? Auth::discover();
    }

    /**
     * Wire the hooks and custom tools to the browser — what `RpcMode::start()` does for its
     * host, with the same `RpcUi` and the same `hook_ui_request` / `hook_ui_response` lines, so
     * a hook's `confirm()` is a dialog in the page and the answer resumes the parked tool call.
     * Called by `WebMode` and not by `/web`, for the reason on `$ui`.
     */
    public function wireHooks(): void
    {
        $this->ui = new RpcUi(fn (array $payload) => $this->broadcast($payload), Palette::named('dark'));

        $this->hooks?->initialize(
            getModel: fn () => $this->session->model(),
            isIdle: fn (): bool => !$this->session->isStreaming(),
            abort: function (): void {
                $this->session->abort();
            },
            hasQueuedMessages: fn (): bool => $this->session->queued() !== [],
            signal: fn () => $this->session->signal(),
            ui: $this->ui,
            send: function (HookMessage $message, bool $triggerTurn): void {
                $this->session->sendHookMessage($message, $triggerTurn);
            },
            note: function (string $customType, mixed $data): void {
                $this->session->appendHookEntry($customType, $data);
            },
            getApiKey: fn ($m) => $this->session->keyFor($m),
            setSessionName: fn (string $name) => $this->session->setSessionName($name),
            getSessionName: fn () => $this->session->getSessionName(),
        );

        $this->hooks?->onError(function (HookError $error): void {
            $this->broadcast([
                'type' => 'hook_error',
                'hookPath' => $error->hookPath,
                'event' => $error->event,
                'error' => $error->error,
            ]);
        });

        $this->customTools?->withUi($this->ui);
        $this->customTools?->withContext(
            fn () => $this->hooks?->context() ?? new HookContext($this->session->cwd(), ui: $this->ui, hasUi: true),
        );

        $this->hooks?->emit(new SessionStartEvent());

        foreach ($this->customTools?->notify('start') ?? [] as $problem) {
            $this->broadcast(['type' => 'tool_error', 'error' => $problem->toText()]);
        }
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
                onMessage: function (Connection $c, mixed $data) use ($id): void {
                    if (is_array($data)) {
                        $this->handleRequest($c, $data, $id);
                    } elseif (is_string($data)) {
                        $this->handleWsMessage($c, $data);
                    }
                },
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

        // Two things that are not agent events and change what the page shows: a rename, and the
        // terminal moving to another conversation. Without the second the tab bar is a claim
        // about a session the process is no longer in.
        $this->session->onSessionNameChanged(function (string $name): void {
            $this->broadcast(['type' => 'session_info_changed', 'name' => $name]);
        });
        $this->session->onSessionSwitched(function (string $reason, ?string $previous): void {
            $this->broadcast(['type' => 'session_switch', 'reason' => $reason, 'previous' => $previous]);
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
        $this->wsClients = [];
    }

    private function handleClose(int $connectionId): void
    {
        unset($this->connections[$connectionId], $this->sseClients[$connectionId], $this->wsClients[$connectionId]);
    }

    /**
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function handleRequest(Connection $conn, array $req, ?int $connectionId = null): void
    {
        $path = $req['path'];

        // 0. WebSocket upgrade handling (RFC 6455)
        if ($path === '/ws' || ($req['headers']['upgrade'] ?? '') === 'websocket') {
            if (Websocket::handshake($req, $conn)) {
                $id = $connectionId ?? array_search($conn, $this->connections, true);
                if ($id !== false) {
                    $this->wsClients[$id] = $conn;
                }

                return;
            }
        }

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

        // 3. User message submission (supports text & multimodal images)
        if ($path === '/api/prompt' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $message = is_array($data) ? ($data['message'] ?? '') : '';
            $rawImages = is_array($data) ? ($data['images'] ?? []) : [];
            $images = $this->parseImages(is_array($rawImages) ? $rawImages : []);

            if (trim($message) !== '' || $images !== []) {
                Async::spawn(function () use ($message, $images): void {
                    try {
                        if ($this->session->isStreaming()) {
                            $this->session->steer($message);
                        } else {
                            $this->session->prompt($message, $images);
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
        // The SSE fallback's way to answer a hook's question; over WebSocket it is a message.
        if ($path === '/api/hook_ui_response' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);

            if (is_array($data)) {
                $this->ui?->answer($data);
            }

            $conn->sendResponse(200, ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'], json_encode(['ok' => true]));

            return;
        }

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
            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode($this->getStatePayload()));

            return;
        }

        // 6. Messages history
        if ($path === '/api/messages') {
            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode($this->getMessagesPayload()));

            return;
        }

        // 7. Available models list
        if ($path === '/api/models') {
            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode($this->getModelsPayload()));

            return;
        }

        // 8. Folders / Workspaces list (matching pi-web /api/folders)
        if ($path === '/api/folders') {
            $currentCwd = $this->session->cwd();
            $workspaces = $this->listAllWorkspaces($currentCwd);

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode([
                'currentCwd' => $currentCwd,
                'workspaces' => $workspaces,
            ]));

            return;
        }

        // 9. Sessions list for a directory (matching pi-web /api/sessions?cwd=...)
        if ($path === '/api/sessions') {
            $targetCwd = $req['query']['cwd'] ?? $this->session->cwd();
            $sessions = \Pig\CodingAgent\Session\SessionManager::listFor($targetCwd);
            $items = array_map(static fn ($s) => [
                'id' => $s->id,
                'path' => $s->path,
                'filename' => basename($s->path),
                'opening' => $s->opening,
                'timestamp' => $s->timestamp,
            ], $sessions);

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode($items));

            return;
        }

        // 10. Switch session
        if ($path === '/api/session/switch' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $targetPath = is_array($data) ? (string) ($data['path'] ?? '') : '';
            $headers = ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'];

            if (!is_file($targetPath)) {
                $conn->sendResponse(404, $headers, json_encode(['ok' => false, 'error' => 'No session file at that path.']));

                return;
            }

            // Answered from inside the fiber, *after* the switch: `switchTo()` may wait for a turn
            // in flight to stop, and a page that reloads its messages on the response must find
            // the new conversation there rather than the old one still being left.
            Async::spawn(function () use ($conn, $targetPath, $headers): void {
                try {
                    $result = $this->session->switchTo($targetPath);
                    $conn->sendResponse(
                        $result->switched ? 200 : 409,
                        $headers,
                        json_encode(['ok' => $result->switched, 'error' => $result->switched ? null : 'A hook declined to leave this session.']),
                    );
                } catch (Throwable $e) {
                    $conn->sendResponse(500, $headers, json_encode(['ok' => false, 'error' => $e->getMessage()]));
                }
            });

            return;
        }

        // 10.1 New session
        if ($path === '/api/session/new' && $req['method'] === 'POST') {
            $headers = ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'];

            // After the switch, for the reason the switch endpoint gives — and because the state
            // this used to answer with was read *before* `startNew()` had run: the old session's.
            Async::spawn(function () use ($conn, $headers): void {
                try {
                    $result = $this->session->startNew();
                    $conn->sendResponse($result->switched ? 200 : 409, $headers, json_encode([
                        'ok' => $result->switched,
                        'sessionFile' => basename($this->session->store()?->path ?? ''),
                        'state' => $this->getStatePayload(),
                    ]));
                } catch (Throwable $e) {
                    $conn->sendResponse(500, $headers, json_encode(['ok' => false, 'error' => $e->getMessage()]));
                }
            });

            return;
        }

        // 10.2 Git diff of current working directory
        if ($path === '/api/diff') {
            $cwd = $this->session->cwd();
            $res = Process::run(['git', 'diff'], cwd: $cwd);
            $diffText = $res->exitCode === 0 ? $res->stdout : '';

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode([
                'ok' => $res->exitCode === 0,
                'diff' => $diffText,
                'cwd' => $cwd,
            ]));

            return;
        }

        // 10.3 System health doctor inspection
        // 10.3c Antigravity accounts: the several Google sign-ins the extension keeps, which one is
        // live, and the active one's quota. Every write goes through `Auth`, so the store and
        // `auth.json` move together — the same methods `/antigravity.accounts` uses.
        if (str_starts_with($path, '/api/accounts')) {
            $this->handleAccounts($conn, $path, $req);

            return;
        }

        if ($path === '/api/doctor') {
            $report = Doctor::inspect($this->session, $this->auth);

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode([
                'ok' => true,
                'plain' => Doctor::renderPlain($report),
                'report' => [
                    'php' => $report->php,
                    'binaries' => $report->binaries,
                    'auth' => $report->auth,
                    'proxy' => $report->proxy,
                    'session' => $report->session,
                ],
            ]));

            return;
        }

        // 10.3b Bug report: write it, answer the path and a prefilled GitHub issue URL
        if ($path === '/api/bug' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $hint = is_array($data) ? trim((string) ($data['hint'] ?? '')) : '';
            $includeTranscript = is_array($data) && ($data['includeTranscript'] ?? false) === true;

            try {
                $report = \Pig\CodingAgent\BugReport::build($this->session, $this->auth, $hint, $includeTranscript);
                $written = \Pig\CodingAgent\BugReport::write($report);
                $conn->sendResponse(200, [
                    'Content-Type' => 'application/json',
                    'Access-Control-Allow-Origin' => '*',
                ], json_encode([
                    'ok' => true,
                    'path' => $written,
                    'issueUrl' => \Pig\CodingAgent\BugReport::issueUrl($hint, $report),
                    'report' => $report,
                ]));
            } catch (Throwable $e) {
                $conn->sendResponse(500, [
                    'Content-Type' => 'application/json',
                    'Access-Control-Allow-Origin' => '*',
                ], json_encode(['ok' => false, 'error' => $e->getMessage()]));
            }

            return;
        }

        // 10.4 Export session as HTML, Markdown, or PR Description
        if ($path === '/api/export') {
            $format = $req['query']['format'] ?? 'html';
            $store = $this->session->store();

            if ($format === 'pr') {
                try {
                    $pr = \Pig\CodingAgent\Export\MarkdownExport::generatePrDescription($this->session);
                    $conn->sendResponse(200, [
                        'Content-Type' => 'application/json',
                        'Access-Control-Allow-Origin' => '*',
                    ], json_encode(['ok' => true, 'markdown' => $pr, 'format' => 'pr']));
                } catch (Throwable $e) {
                    $conn->sendResponse(500, [
                        'Content-Type' => 'application/json',
                        'Access-Control-Allow-Origin' => '*',
                    ], json_encode(['ok' => false, 'error' => $e->getMessage()]));
                }

                return;
            }

            if ($format === 'md' || $format === 'markdown') {
                $md = $store !== null ? \Pig\CodingAgent\Export\MarkdownExport::render($store->messages(), $this->session->cwd()) : '';
                $conn->sendResponse(200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Access-Control-Allow-Origin' => '*',
                    'Content-Disposition' => 'attachment; filename="session.md"',
                ], $md);

                return;
            }

            // Default HTML export
            $html = $store !== null ? \Pig\CodingAgent\Export\HtmlExport::render($store->messages(), $this->session->cwd()) : '';
            $conn->sendResponse(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => 'attachment; filename="session.html"',
            ], $html);

            return;
        }

        // 11. Switch model via HTTP POST
        if ($path === '/api/model' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $modelId = is_array($data) ? (string) ($data['modelId'] ?? $data['model'] ?? '') : '';
            $provider = is_array($data) && isset($data['provider']) ? (string) $data['provider'] : null;
            $thinkingStr = is_array($data) && isset($data['thinkingLevel']) ? (string) $data['thinkingLevel'] : null;
            $thinking = $thinkingStr !== null ? ThinkingLevel::tryFrom($thinkingStr) : null;

            if ($modelId !== '') {
                try {
                    $model = Models::get($provider === null ? $modelId : "{$provider}/{$modelId}") ?? Models::get($modelId);
                    if ($model !== null) {
                        $this->session->setModel($model, $thinking, persistAsDefault: false);
                    }
                } catch (Throwable) {}
            }

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode(['ok' => true, 'state' => $this->getStatePayload()]));

            return;
        }

        // 12. Switch thinking level via HTTP POST
        if ($path === '/api/thinking' && $req['method'] === 'POST') {
            $data = json_decode($req['body'], true);
            $levelStr = is_array($data) ? (string) ($data['level'] ?? '') : '';
            $level = ThinkingLevel::tryFrom($levelStr);

            if ($level !== null) {
                try {
                    $this->session->setThinkingLevel($level, persistAsDefault: false);
                } catch (Throwable) {}
            }

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], json_encode(['ok' => true, 'state' => $this->getStatePayload()]));

            return;
        }

        $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Not Found: {$path}");
    }

    private function broadcastEvent(AgentEvent $event): void
    {
        if ($this->sseClients === [] && $this->wsClients === []) {
            return;
        }

        $payload = match (true) {
            $event instanceof AgentStartEvent => ['type' => 'agent_start'],
            $event instanceof TurnStartEvent => ['type' => 'turn_start'],
            $event instanceof TurnEndEvent => ['type' => 'turn_end'],
            $event instanceof AgentEndEvent => [
                'type' => 'agent_end',
                'error' => $this->session->agent->state->error,
            ],
            $event instanceof MessageStartEvent => ['type' => 'message_start'],
            $event instanceof MessageEndEvent => ['type' => 'message_end'],
            $event instanceof MessageUpdateEvent => $this->serializeMessageUpdate($event),
            $event instanceof ToolExecutionStartEvent => [
                'type' => 'tool_execution_start',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'arguments' => $event->arguments,
            ],
            $event instanceof ToolExecutionUpdateEvent => [
                'type' => 'tool_execution_update',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'output' => $this->extractToolOutput($event->partialResult),
            ],
            $event instanceof ToolExecutionEndEvent => [
                'type' => 'tool_execution_end',
                'toolCallId' => $event->toolCallId,
                'result' => $event->result,
                'isError' => $event->isError,
            ],
            $event instanceof RetryStartEvent => [
                'type' => 'retry_start',
                'attempt' => $event->attempt,
                'maxAttempts' => $event->maxAttempts,
                'delaySeconds' => $event->delaySeconds,
                'error' => $event->error,
            ],
            $event instanceof RetryEndEvent => [
                'type' => 'retry_end',
                'succeeded' => $event->succeeded,
                'attempts' => $event->attempts,
                'error' => $event->error,
            ],
            $event instanceof AutoCompactionStartEvent => [
                'type' => 'auto_compaction_start',
                'error' => $event->error,
            ],
            $event instanceof AutoCompactionEndEvent => [
                'type' => 'auto_compaction_end',
                'succeeded' => $event->succeeded,
                'willRetry' => $event->willRetry,
                'error' => $event->error,
            ],
            default => null,
        };

        if ($payload === null) {
            return;
        }

        $this->broadcast($payload);
    }

    /** @param array<string, mixed> $payload */
    private function broadcast(array $payload): void
    {
        if ($this->sseClients !== []) {
            $line = 'data: ' . json_encode($payload) . "\n\n";
            foreach ($this->sseClients as $client) {
                $client->sendRaw($line);
            }
        }

        foreach ($this->wsClients as $wsClient) {
            $wsClient->send($payload);
        }
    }

    private function handleWsMessage(Connection $conn, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            return;
        }

        $id = $data['id'] ?? null;
        $type = (string) ($data['type'] ?? '');

        // A hook's question being answered: not a command, and it must not be spawned — the
        // fiber it resumes is the one parked inside the tool call.
        if ($type === 'hook_ui_response') {
            $this->ui?->answer($data);

            return;
        }

        match ($type) {
            'prompt' => Async::spawn(function () use ($conn, $id, $data): void {
                $text = (string) ($data['message'] ?? '');
                $rawImages = is_array($data['images'] ?? null) ? $data['images'] : [];
                $images = $this->parseImages($rawImages);

                if (trim($text) !== '' || $images !== []) {
                    try {
                        if ($this->session->isStreaming()) {
                            $this->session->steer($text);
                        } else {
                            $this->session->prompt($text, $images);
                        }
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => true]);
                    } catch (Throwable $e) {
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => false, 'error' => $e->getMessage()]);
                    }
                }
            }),
            'steer' => Async::spawn(function () use ($conn, $id, $data): void {
                $text = (string) ($data['message'] ?? '');
                if (trim($text) !== '') {
                    $this->session->steer($text);
                    $conn->send(['id' => $id, 'type' => 'response', 'success' => true]);
                }
            }),
            'abort' => Async::spawn(function () use ($conn, $id): void {
                $this->session->abort();
                $conn->send(['id' => $id, 'type' => 'response', 'success' => true]);
            }),
            'set_model' => Async::spawn(function () use ($conn, $id, $data): void {
                $modelId = (string) ($data['modelId'] ?? '');
                $provider = isset($data['provider']) ? (string) $data['provider'] : null;
                $thinkingStr = isset($data['thinkingLevel']) ? (string) $data['thinkingLevel'] : null;
                $thinking = $thinkingStr !== null ? ThinkingLevel::tryFrom($thinkingStr) : null;

                if ($modelId !== '') {
                    try {
                        $model = Models::get($provider === null ? $modelId : "{$provider}/{$modelId}") ?? Models::get($modelId);
                        if ($model === null) {
                            throw new \RuntimeException("No such model: {$provider}/{$modelId}");
                        }
                        $this->session->setModel($model, $thinking, persistAsDefault: false);
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => true, 'state' => $this->getStatePayload()]);
                    } catch (Throwable $e) {
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => false, 'error' => $e->getMessage()]);
                    }
                }
            }),
            'set_thinking_level' => Async::spawn(function () use ($conn, $id, $data): void {
                $level = \Pig\Agent\ThinkingLevel::tryFrom((string) ($data['level'] ?? ''));
                if ($level !== null) {
                    $this->session->setThinkingLevel($level, persistAsDefault: false);
                    $conn->send(['id' => $id, 'type' => 'response', 'success' => true]);
                }
            }),
            'switch_session' => Async::spawn(function () use ($conn, $id, $data): void {
                $path = (string) ($data['path'] ?? '');
                if (is_file($path)) {
                    try {
                        $this->session->switchTo($path);
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => true]);
                    } catch (Throwable $e) {
                        $conn->send(['id' => $id, 'type' => 'response', 'success' => false, 'error' => $e->getMessage()]);
                    }
                }
            }),
            'new_session' => Async::spawn(function () use ($conn, $id): void {
                try {
                    $this->session->startNew();
                    $conn->send([
                        'id' => $id,
                        'type' => 'response',
                        'success' => true,
                        'sessionFile' => basename($this->session->store()?->path ?? ''),
                        'state' => $this->getStatePayload(),
                    ]);
                } catch (Throwable $e) {
                    $conn->send(['id' => $id, 'type' => 'response', 'success' => false, 'error' => $e->getMessage()]);
                }
            }),
            'get_state' => $conn->send([
                'id' => $id,
                'type' => 'state',
                'state' => $this->getStatePayload(),
            ]),
            'get_messages' => $conn->send([
                'id' => $id,
                'type' => 'messages',
                'messages' => $this->getMessagesPayload(),
            ]),
            'get_models' => $conn->send([
                'id' => $id,
                'type' => 'models',
                'models' => $this->getModelsPayload(),
            ]),
            default => null,
        };
    }

    /**
     * @param list<mixed> $rawImages
     * @return list<ImageContent>
     */
    private function parseImages(array $rawImages): array
    {
        $images = [];

        foreach ($rawImages as $img) {
            if (is_array($img) && isset($img['data'], $img['mimeType']) && is_string($img['data']) && is_string($img['mimeType'])) {
                $images[] = new ImageContent($img['data'], $img['mimeType']);
            }
        }

        return $images;
    }

    /** @return list<array<string, mixed>> */
    private function getModelsPayload(): array
    {
        $models = $this->auth->availableModels();

        return array_values(array_map(static fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'provider' => $m->provider,
            'reasoning' => $m->reasoning,
            'supportsImages' => in_array('image', $m->input, true),
            'thinkingLevels' => array_map(static fn ($l) => $l->value, ThinkingLevel::supportedBy($m)),
            'contextWindow' => $m->contextWindow,
            'maxTokens' => $m->maxTokens,
        ], $models));
    }

    /** @param array{method: string, body: string, query: array<string, string>} $req */
    private function handleAccounts(Connection $conn, string $path, array $req): void
    {
        $headers = ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'];
        $json = static fn (array $data): string => (string) json_encode($data);

        if ($path === '/api/accounts' && $req['method'] === 'GET') {
            $conn->sendResponse(200, $headers, $json($this->accountsPayload()));

            return;
        }

        if ($path === '/api/accounts/usage' && $req['method'] === 'GET') {
            if ($this->auth->credentials(\Pig\Ai\Utils\Oauth\Provider::Antigravity) === null) {
                $conn->sendResponse(404, $headers, $json(['ok' => false, 'error' => 'No Antigravity account is signed in.']));

                return;
            }

            // A network call, so answered from a fiber: the loop keeps serving the page meanwhile.
            // The token comes through `apiKey()` and not `credentials()`, because only the first
            // renews one that has expired — and the stored one usually has, which is a 401 from
            // the quota endpoint that reads as the account being broken.
            Async::spawn(function () use ($conn, $headers, $json): void {
                try {
                    $decoded = json_decode((string) $this->auth->apiKey(\Pig\Ai\Utils\Oauth\Provider::Antigravity->value), true);
                    $token = is_array($decoded) ? (string) ($decoded['token'] ?? '') : '';
                    $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;
                    $usage = (new \Pig\CodingAgent\Antigravity\QuotaClient())->fetchUsage($token, is_string($project) ? $project : null);
                    $conn->sendResponse(200, $headers, $json(['ok' => true, 'usage' => $usage]));
                } catch (Throwable $e) {
                    $conn->sendResponse(502, $headers, $json(['ok' => false, 'error' => $e->getMessage()]));
                }
            });

            return;
        }

        if ($req['method'] !== 'POST') {
            $conn->sendResponse(405, $headers, $json(['ok' => false, 'error' => 'POST only.']));

            return;
        }

        $data = json_decode($req['body'], true);
        $id = is_array($data) ? (string) ($data['id'] ?? '') : '';

        try {
            $result = match ($path) {
                '/api/accounts/activate' => $this->auth->activateAntigravityAccount($id) !== null
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => "No account called '{$id}'."],
                '/api/accounts/remove' => $this->auth->removeAntigravityAccount($id)
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => "No account called '{$id}'."],
                '/api/accounts/rotate' => $this->auth->rotateAntigravityAccount() !== null
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => 'Only one Antigravity account; nothing to rotate to.'],
                default => null,
            };
        } catch (Throwable $e) {
            $conn->sendResponse(500, $headers, $json(['ok' => false, 'error' => $e->getMessage()]));

            return;
        }

        if ($result === null) {
            $conn->sendResponse(404, $headers, $json(['ok' => false, 'error' => 'No such endpoint.']));

            return;
        }

        $conn->sendResponse($result['ok'] ? 200 : 400, $headers, $json([...$result, ...$this->accountsPayload()]));
    }

    /** @return array<string, mixed> */
    private function accountsPayload(): array
    {
        $accounts = $this->auth->accounts();
        $rows = [];

        foreach ($accounts->accounts() as $id => $entry) {
            $expires = is_int($entry['expires'] ?? null) ? $entry['expires'] : null;
            $rows[] = [
                'id' => (string) $id,
                'email' => is_string($entry['email'] ?? null) ? $entry['email'] : (string) $id,
                'projectId' => is_string($entry['projectId'] ?? null) ? $entry['projectId'] : null,
                // Milliseconds since the epoch, as `Credentials::$expires` and the file have it.
                'expires' => $expires,
                'active' => (string) $id === $accounts->activeId(),
            ];
        }

        return [
            'ok' => true,
            'accounts' => $rows,
            'activeId' => $accounts->activeId(),
            'path' => $accounts->path(),
            'problems' => $accounts->problems(),
        ];
    }

    /** The first thing said in this conversation, as a tab label when nobody named it. */
    private function opening(): string
    {
        foreach ($this->session->messages() as $message) {
            if ($message instanceof \Pig\Ai\UserMessage) {
                foreach ($message->content as $block) {
                    if ($block instanceof \Pig\Ai\TextContent && trim($block->text) !== '') {
                        return mb_substr(preg_replace('/\s+/u', ' ', trim($block->text)) ?? '', 0, 60);
                    }
                }
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function getStatePayload(): array
    {
        $stats = $this->session->stats();
        $model = $this->session->model();
        $window = $model?->contextWindow ?? 0;
        $percent = $window > 0 ? (int) ($stats->input / $window * 100) : 0;

        return [
            'cwd' => basename($this->session->cwd()),
            'branch' => 'main',
            'sessionFile' => basename($this->session->store()?->path ?? ''),
            'sessionPath' => $this->session->store()?->path,
            'sessionName' => $this->session->getSessionName(),
            'opening' => $this->opening(),
            'cwdPath' => $this->session->cwd(),
            'model' => $model?->id ?? 'no-model',
            'provider' => $model?->provider ?? 'unknown',
            'thinkingLevel' => $this->session->thinkingLevel()?->value ?? 'off',
            'tokensIn' => $stats->input,
            'tokensOut' => $stats->output,
            'cost' => number_format($stats->cost, 3),
            'contextWindow' => $window < 1000000 ? round($window / 1000) . 'k' : sprintf('%.1fM', $window / 1000000),
            'contextPercent' => sprintf('%.1f', $percent),
        ];
    }

    /** @return list<array{role: string, content: mixed}> */
    private function getMessagesPayload(): array
    {
        $history = [];
        foreach ($this->session->messages() as $m) {
            if ($m instanceof UserMessage) {
                $blocks = [];
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $blocks[] = ['type' => 'text', 'text' => $c->text];
                    } elseif ($c instanceof ImageContent) {
                        $blocks[] = ['type' => 'image', 'mimeType' => $c->mimeType, 'data' => $c->data];
                    }
                }
                $history[] = [
                    'role' => 'user',
                    'content' => $blocks,
                ];
            } elseif ($m instanceof AssistantMessage) {
                $blocks = [];
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $blocks[] = ['type' => 'text', 'text' => $c->text];
                    } elseif ($c instanceof ThinkingContent) {
                        $blocks[] = ['type' => 'thinking', 'text' => $c->thinking];
                    } elseif ($c instanceof ToolCall) {
                        $blocks[] = [
                            'type' => 'tool_call',
                            'id' => $c->id,
                            'name' => $c->name,
                            'arguments' => $c->arguments,
                        ];
                    }
                }
                $history[] = [
                    'role' => 'assistant',
                    'content' => $blocks,
                    'stopReason' => $m->stopReason->value,
                    'errorMessage' => $m->errorMessage,
                ];
            } elseif ($m instanceof ToolResultMessage) {
                $textResult = '';
                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $textResult .= $c->text;
                    }
                }
                $history[] = [
                    'role' => 'tool_result',
                    'toolCallId' => $m->toolCallId,
                    'toolName' => $m->toolName,
                    'result' => $textResult,
                    'isError' => $m->isError,
                    'details' => $m->details,
                ];
            }
        }

        return $history;
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

    private function extractToolOutput(mixed $result): string
    {
        if ($result instanceof AgentToolResult) {
            if (is_array($result->details) && isset($result->details['output']) && is_string($result->details['output'])) {
                return $result->details['output'];
            }
            $out = '';
            foreach ($result->content as $c) {
                if ($c instanceof TextContent) {
                    $out .= $c->text;
                }
            }

            return $out;
        }

        if (is_string($result)) {
            return $result;
        }

        return '';
    }

    /** @return list<array{path: string, name: string, sessionCount: int, isCurrent: bool}> */
    private function listAllWorkspaces(string $currentCwd): array
    {
        $roots = [
            \Pig\CodingAgent\Config::home() . '/sessions',
            \Pig\CodingAgent\Config::piHome() . '/sessions',
        ];

        $workspaces = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) ?: [] as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }

                $fullDir = $root . '/' . $dir;
                if (!is_dir($fullDir)) {
                    continue;
                }

                $files = glob($fullDir . '/*.jsonl') ?: [];
                if ($files === []) {
                    continue;
                }

                $firstLine = '';
                $fh = fopen($files[0], 'r');
                if ($fh !== false) {
                    $firstLine = fgets($fh) ?: '';
                    fclose($fh);
                }
                $header = json_decode($firstLine, true);
                $cwd = is_array($header) ? ($header['cwd'] ?? null) : null;

                if (is_string($cwd) && $cwd !== '') {
                    if (!isset($workspaces[$cwd])) {
                        $workspaces[$cwd] = [
                            'path' => $cwd,
                            'name' => basename($cwd) ?: $cwd,
                            'sessionCount' => count($files),
                            'isCurrent' => $cwd === $currentCwd,
                        ];
                    } else {
                        $workspaces[$cwd]['sessionCount'] += count($files);
                    }
                }
            }
        }

        if (!isset($workspaces[$currentCwd]) && is_dir($currentCwd)) {
            $workspaces[$currentCwd] = [
                'path' => $currentCwd,
                'name' => basename($currentCwd) ?: $currentCwd,
                'sessionCount' => 0,
                'isCurrent' => true,
            ];
        }

        $list = array_values($workspaces);
        usort($list, static function ($a, $b) {
            if ($a['isCurrent']) {
                return -1;
            }
            if ($b['isCurrent']) {
                return 1;
            }

            return $b['sessionCount'] <=> $a['sessionCount'];
        });

        return $list;
    }
}
