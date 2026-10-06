<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Models;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Web\Protocols\Websocket;
use Pig\CodingAgent\Logger;
use Throwable;

/**
 * The web shell: HTTP for the page and the lists, a WebSocket per browser tab, and one
 * `pig --mode rpc` child per conversation behind it, in a `SessionPool`.
 *
 * pi-web's `server.js`, as `HttpServer` was always meant to become. Until step ② of the process
 * model (CLAUDE.md, "Three ways in") this class held **one** `AgentSession` and every tab in the
 * page was a `switchTo()` on it — so the TUI sharing that session moved with the browser, a turn
 * in flight on tab A was aborted by opening tab B, and two browsers on two conversations pulled
 * one process back and forth. Now a tab is a child process, and the server relays.
 *
 * **What the server knows and what it does not.** It knows the children it started, which
 * connection is bound to which, and how to list what is on disk (`/api/folders`,
 * `/api/sessions`). It does not know what any conversation *says*: that is in the child, and
 * the browser asks the child through the relay. So `/api/state`, `/api/messages`, `/api/model`
 * and the rest of the per-session HTTP endpoints are gone — the page sends `get_state`,
 * `get_messages`, `set_model` as `rpc_command` lines over its socket, which is pi-web's shape and
 * also means every command a host can send, a browser can.
 *
 * **The socket protocol**, pi-web's, four lines in and four out:
 *
 * | in | out |
 * |---|---|
 * | `start_session {cwd, sessionFile?}` — bind this socket to a conversation, starting its child if there is none | `rpc_event {event}` — a line from the bound child, verbatim |
 * | `detach_session` | `session_ended {reason}` — the bound child is gone |
 * | `rpc_command {command}` — forwarded whole, id and all, to the bound child | `error {message}` |
 * | `ping` | `pong` |
 *
 * `hook_ui_request` and `hook_ui_response` are not special here any more: the child's `RpcMode`
 * sends the request as an event and reads the response as a command, and both go through the
 * relay like anything else. What used to be `HttpServer::$ui` and `wireHooks()` is now
 * `RpcMode::start()`'s, in the child — which is where it was for `--mode rpc` all along.
 *
 * **One id for both halves.** A browser's command carries its own id and the child's response
 * carries it back, so the server never has to match them; it forwards both verbatim. The relay
 * is `RpcClient::relay()`, which writes and does not wait — `send()` would park a fiber per
 * command and serialise every tab through one.
 *
 * `--mode web`'s own `cwd` is the directory a *new* conversation opens in when the page does not
 * say otherwise, and the one `/api/folders` marks as current. Nothing else about the server is
 * per-directory.
 */
final class HttpServer
{
    private mixed $serverSocket = null;
    private ?string $serverWatcher = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, Connection> the connections that have upgraded to WebSocket */
    private array $wsClients = [];

    /** @var array<int, string> a stable id per socket, for the key a new conversation gets */
    private array $clientIds = [];

    private ?string $heartbeatTimer = null;
    private const float PING_INTERVAL = 25.0;

    public const FAVICON_SVG = '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">'
        . '<circle cx="50" cy="50" r="44" fill="#F472B6"/>'
        . '<ellipse cx="50" cy="56" rx="22" ry="16" fill="#FBCFE8"/>'
        . '<ellipse cx="42" cy="56" rx="4" ry="6" fill="#BE185D"/>'
        . '<ellipse cx="58" cy="56" rx="4" ry="6" fill="#BE185D"/>'
        . '<circle cx="34" cy="38" r="6" fill="#1F2937"/>'
        . '<circle cx="66" cy="38" r="6" fill="#1F2937"/>'
        . '<circle cx="36" cy="36" r="2" fill="#FFFFFF"/>'
        . '<circle cx="68" cy="36" r="2" fill="#FFFFFF"/>'
        . '<path d="M22 28C18 16 30 18 36 24" fill="#F472B6" stroke="#DB2777" stroke-width="3"/>'
        . '<path d="M78 28C82 16 70 18 64 24" fill="#F472B6" stroke="#DB2777" stroke-width="3"/>'
        . '</svg>';

    private int $nextConnectionId = 0;
    private bool $isRunning = false;
    private readonly Auth $auth;
    private readonly SessionPool $pool;

    /**
     * @param string $cwd where a new conversation opens when the page names no directory
     * @param (Closure(string, ?string): RpcClient)|null $spawn how a child is made; the default
     *        starts `bin/pig --mode rpc` in the conversation's directory, with `--session <file>`
     *        when it has one. Injectable so a test can hand back a client over a fixture.
     */
    public function __construct(
        private readonly string $cwd,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
        ?Auth $auth = null,
        ?Closure $spawn = null,
        float $idleTtl = SessionPool::IDLE_TTL,
    ) {
        $this->auth = $auth ?? Auth::discover();
        $this->pool = new SessionPool(
            spawn: $spawn ?? static function (string $cwd, ?string $sessionFile): RpcClient {
                $sessionArg = $sessionFile !== null ? SessionManager::find($cwd, $sessionFile) : null;

                return new RpcClient(
                    cwd: $cwd,
                    arguments: $sessionArg !== null ? ['--session', $sessionArg] : [],
                );
            },
            onEvent: $this->onSessionEvent(...),
            idleTtl: $idleTtl,
        );
    }

    /** @internal for tests and `WebMode::stop()` */
    public function pool(): SessionPool
    {
        return $this->pool;
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

        // The extensions, for what they bring the page: HTTP routes, locales, and the providers
        // `/api/models` lists. The children each load their own for their conversation; this is
        // the shell's copy, for the shell's endpoints. A broken one is a line on standard error
        // and not a reason the page does not come up.
        [, $problems] = ExtensionLoader::load($this->cwd, $this->auth->settings()?->extensions() ?? [], auth: $this->auth);

        foreach ($problems as $problem) {
            fwrite(STDERR, "extension {$problem->toText()}\n");
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
                    try {
                        if (is_array($data)) {
                            $this->handleRequest($c, $data, $id);
                        } elseif (is_string($data)) {
                            $this->handleWsMessage($c, $id, $data);
                        }
                    } catch (Throwable $e) {
                        Logger::error("[HttpServer] Error processing message (#{$id}): " . $e->getMessage(), [
                            'trace' => $e->getTraceAsString(),
                        ]);
                        if (!$c->isClosed()) {
                            if (is_array($data)) {
                                $c->sendResponse(500, ['Content-Type' => 'application/json'], json_encode([
                                    'error' => 'Internal Server Error: ' . $e->getMessage(),
                                ]));
                            } elseif (is_string($data)) {
                                Websocket::send($c, json_encode([
                                    'type' => 'error',
                                    'message' => 'Internal server error: ' . $e->getMessage(),
                                ]));
                            }
                        }
                    }
                },
                onClose: fn (Connection $c) => $this->handleClose($id),
                onError: function (Connection $c, Throwable $e) use ($id): void {
                    Logger::error("[HttpServer] Connection error (#{$id}): " . $e->getMessage());
                    if (!$c->isClosed()) {
                        if ($c->getProtocol() === Http::class) {
                            $c->sendResponse(500, ['Content-Type' => 'application/json'], json_encode([
                                'error' => 'Internal Server Error: ' . $e->getMessage(),
                            ]));
                        } elseif ($c->getProtocol() === Websocket::class) {
                            Websocket::send($c, json_encode([
                                'type' => 'error',
                                'message' => 'Internal server error: ' . $e->getMessage(),
                            ]));
                        }
                        $c->close();
                    }
                },
            );

            $this->connections[$id] = $conn;
            $watcher = Loop::get()->onReadable($client, static fn () => $conn->onReadable());
            $conn->setReadableWatcher($watcher);
        });

        // Fault isolation boundary: keep the daemon running on uncaught loop callback errors
        Loop::get()->setErrorHandler(static function (Throwable $e): void {
            Logger::error("[HttpServer] Uncaught loop error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        });

        $this->isRunning = true;
        $this->armHeartbeat();
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Stop: close every connection, then every child — cleanly, through `RpcClient::stop()`, so
     * each child's `session_shutdown` hook fires. This is what `pig web stop`'s SIGTERM reaches.
     * Suspends while the children wind down, so it wants a fiber.
     */
    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->isRunning = false;
        Loop::get()->setErrorHandler(null);

        if ($this->heartbeatTimer !== null) {
            Loop::get()->cancel($this->heartbeatTimer);
            $this->heartbeatTimer = null;
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
        $this->wsClients = [];
        $this->clientIds = [];

        $this->pool->shutdown();
    }

    private function handleClose(int $connectionId): void
    {
        $this->pool->detach($connectionId);
        unset($this->connections[$connectionId], $this->wsClients[$connectionId], $this->clientIds[$connectionId]);
    }

    private function armHeartbeat(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->heartbeatTimer = Loop::get()->delay(self::PING_INTERVAL, function (): void {
            if (!$this->isRunning) {
                return;
            }

            foreach ($this->wsClients as $wsClient) {
                if (!$wsClient->isClosed()) {
                    Websocket::ping($wsClient);
                }
            }

            $this->armHeartbeat();
        });
    }

    // ---- HTTP: the page and the lists ------------------------------------------------------

    /**
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function handleRequest(Connection $conn, array $req, int $connectionId): void
    {
        $path = $req['path'];
        $json = ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'];

        // 0. WebSocket upgrade handling (RFC 6455)
        if ($path === '/ws' || ($req['headers']['upgrade'] ?? '') === 'websocket') {
            if (Websocket::handshake($req, $conn)) {
                $this->wsClients[$connectionId] = $conn;
                $this->clientIds[$connectionId] = bin2hex(random_bytes(8));

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

        // 1.1 Mascot pig favicon (/favicon.ico & /favicon.svg)
        if ($path === '/favicon.ico' || $path === '/favicon.svg') {
            $conn->sendResponse(200, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=86400',
                'Access-Control-Allow-Origin' => '*',
            ], self::FAVICON_SVG);

            return;
        }

        // 1.2 Modular static assets (/assets/..., /css/..., /js/...) with path traversal guard
        if (str_starts_with($path, '/assets/') || str_starts_with($path, '/css/') || str_starts_with($path, '/js/')) {
            $rel = str_starts_with($path, '/assets/') ? substr($path, 8) : ltrim($path, '/');
            $assetBase = realpath(__DIR__ . '/assets') ?: (__DIR__ . '/assets');
            $target = realpath($assetBase . '/' . $rel);

            if ($target !== false && str_starts_with($target, $assetBase) && is_file($target)) {
                $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'css' => 'text/css; charset=utf-8',
                    'js', 'mjs' => 'application/javascript; charset=utf-8',
                    'svg' => 'image/svg+xml; charset=utf-8',
                    'json' => 'application/json; charset=utf-8',
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    default => 'application/octet-stream',
                };

                $conn->sendResponse(200, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'no-cache',
                    'Access-Control-Allow-Origin' => '*',
                ], file_get_contents($target) ?: '');

                return;
            }

            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Asset Not Found: {$path}");

            return;
        }

        // 2. Available models list — a fact about this machine's keys, not about any session.
        if ($path === '/api/models') {
            $conn->sendResponse(200, $json, json_encode($this->getModelsPayload()));

            return;
        }

        // 3. Which children are running, for a page reconnecting after a reload: it can tell a
        // tab whose agent is still working from one whose process has gone.
        if ($path === '/api/running') {
            $running = [];
            foreach ($this->pool->all() as $managed) {
                $running[] = [
                    'cwd' => $managed->cwd,
                    'sessionFile' => $managed->sessionFile,
                    'keys' => array_keys($managed->keys),
                    'clients' => count($managed->clients),
                    'running' => $managed->running,
                ];
            }
            $conn->sendResponse(200, $json, json_encode(['cwd' => $this->cwd, 'sessions' => $running]));

            return;
        }

        // 8. Folders / Workspaces list (matching pi-web /api/folders)
        if ($path === '/api/folders') {
            $currentCwd = $this->cwd;
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
            $targetCwd = $req['query']['cwd'] ?? $this->cwd;
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

        // 9b. Delete a session file
        if ($path === '/api/sessions/delete') {
            $headers = [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ];

            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $headers, json_encode(['success' => false, 'error' => 'POST only']));
                return;
            }

            $data = json_decode($req['body'] ?? '{}', true) ?: [];
            $rawPath = is_string($data['path'] ?? null) ? $data['path'] : '';
            $targetCwd = is_string($data['cwd'] ?? null) ? $data['cwd'] : null;
            $safePath = $this->resolveSafeSessionPath($rawPath, $targetCwd);

            if ($safePath === null) {
                $conn->sendResponse(400, $headers, json_encode(['success' => false, 'error' => 'Invalid session file']));
                return;
            }

            set_error_handler(static fn () => true);
            $ok = unlink($safePath);
            restore_error_handler();

            if (!$ok) {
                $conn->sendResponse(500, $headers, json_encode(['success' => false, 'error' => 'Failed to delete session file']));
                return;
            }

            $this->pool->closeBySessionFile($safePath);
            $conn->sendResponse(200, $headers, json_encode(['success' => true]));
            return;
        }

        // 9c. Rename a session (append SessionInfoEntry)
        if ($path === '/api/sessions/rename') {
            $headers = [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ];

            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $headers, json_encode(['success' => false, 'error' => 'POST only']));
                return;
            }

            $data = json_decode($req['body'] ?? '{}', true) ?: [];
            $rawPath = is_string($data['path'] ?? null) ? $data['path'] : '';
            $newName = trim((string) ($data['name'] ?? ''));
            $targetCwd = is_string($data['cwd'] ?? null) ? $data['cwd'] : null;
            $safePath = $this->resolveSafeSessionPath($rawPath, $targetCwd);

            if ($safePath === null) {
                $conn->sendResponse(400, $headers, json_encode(['success' => false, 'error' => 'Invalid session file']));
                return;
            }

            try {
                $sm = SessionManager::open($safePath);
                $sm->setSessionName($newName);
            } catch (Throwable $e) {
                $conn->sendResponse(500, $headers, json_encode(['success' => false, 'error' => $e->getMessage()]));
                return;
            }

            $conn->sendResponse(200, $headers, json_encode(['success' => true, 'name' => $newName]));
            return;
        }

        // 10.2b Serve local file/image for preview
        if ($path === '/api/file') {
            $filePath = $req['query']['path'] ?? '';
            $realPath = realpath($filePath);
            if ($realPath && is_file($realPath)) {
                $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'application/octet-stream',
                };
                $conn->sendResponse(200, [
                    'Content-Type' => $mime,
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'max-age=86400',
                ], file_get_contents($realPath));

                return;
            }

            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'File not found');

            return;
        }

        // 10.3c What the loaded extensions answer for: an extension with a panel in this page
        // registers its endpoints (`ExtensionApi::registerHttpRoute()`) rather than having them
        // written in here — the Antigravity accounts panel is the first. Answered from a fiber,
        // because a handler may make a network call and the loop has to keep serving the page.
        if (str_starts_with($path, '/api/') && ($route = ExtensionApi::httpRouteFor($path)) !== null) {
            \Pig\Async\Async::spawn(function () use ($conn, $route, $path, $req): void {
                $headers = ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'];

                try {
                    $answer = $route($path, ['method' => $req['method'], 'body' => $req['body'], 'query' => $req['query'] ?? []]);
                } catch (Throwable $e) {
                    $conn->sendResponse(500, $headers, (string) json_encode(['ok' => false, 'error' => $e->getMessage()]));

                    return;
                }

                if ($answer === null) {
                    $conn->sendResponse(404, $headers, (string) json_encode(['ok' => false, 'error' => 'No such endpoint.']));

                    return;
                }

                $conn->sendResponse($answer['status'], $headers, (string) json_encode($answer['body']));
            });

            return;
        }

        // 10.4 Custom extension locales and language packs
        if ($path === '/api/locales') {
            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], (string) json_encode([
                'success' => true,
                'locales' => ExtensionApi::registeredLocales(),
            ]));

            return;
        }

        // 10.5 Web Terminal info & command execution
        if ($path === '/api/terminal/info') {
            $targetCwd = $req['query']['cwd'] ?? $this->cwd;
            $safeCwd = realpath($targetCwd) ?: $this->cwd;
            $user = getenv('USER') ?: (getenv('LOGNAME') ?: 'user');
            $hostname = gethostname() ?: 'pig';

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ], (string) json_encode([
                'success' => true,
                'user' => $user,
                'hostname' => $hostname,
                'cwd' => $safeCwd,
                'home' => getenv('HOME') ?: '/',
            ]));

            return;
        }

        // 10.6 Web Terminal Tab completion (Commands, files and directories)
        if ($path === '/api/terminal/complete') {
            $headers = [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ];

            $line = (string) ($req['query']['line'] ?? '');
            $targetCwd = (string) ($req['query']['cwd'] ?? $this->cwd);
            $safeCwd = realpath($targetCwd) ?: $this->cwd;

            $completions = $this->getTerminalCompletions($line, $safeCwd);

            $conn->sendResponse(200, $headers, (string) json_encode([
                'success' => true,
                ...$completions,
            ]));

            return;
        }

        if ($path === '/api/terminal/exec') {
            $headers = [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ];

            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $headers, (string) json_encode(['success' => false, 'error' => 'POST only']));
                return;
            }

            $data = json_decode($req['body'], true);
            $command = is_array($data) ? trim((string) ($data['command'] ?? '')) : '';
            $targetCwd = is_array($data) ? (string) ($data['cwd'] ?? '') : '';
            $safeCwd = realpath($targetCwd) ?: $this->cwd;

            if ($command === '') {
                $conn->sendResponse(400, $headers, (string) json_encode(['success' => false, 'error' => 'Command is required']));
                return;
            }

            // Built-in cd command handling to update working directory across calls
            if (preg_match('/^cd(?:\s+(.*))?$/', $command, $m)) {
                $target = trim($m[1] ?? '');
                if ($target === '' || $target === '~') {
                    $target = getenv('HOME') ?: '/';
                } elseif (str_starts_with($target, '~/')) {
                    $home = getenv('HOME') ?: '';
                    $target = $home . substr($target, 1);
                } elseif (!str_starts_with($target, '/')) {
                    $target = $safeCwd . '/' . $target;
                }
                $realTarget = realpath($target);
                if ($realTarget !== false && is_dir($realTarget)) {
                    $conn->sendResponse(200, $headers, (string) json_encode([
                        'success' => true,
                        'stdout' => '',
                        'stderr' => '',
                        'exitCode' => 0,
                        'stopped' => false,
                        'cwd' => $realTarget,
                    ]));
                    return;
                }

                $conn->sendResponse(200, $headers, (string) json_encode([
                    'success' => true,
                    'stdout' => '',
                    'stderr' => "cd: no such file or directory: " . ($m[1] ?? '') . "\n",
                    'exitCode' => 1,
                    'stopped' => false,
                    'cwd' => $safeCwd,
                ]));
                return;
            }

            \Pig\Async\Async::spawn(function () use ($conn, $headers, $command, $safeCwd): void {
                try {
                    [$exit, $stdout, $stderr] = \Pig\Tui\Process::runAsync(
                        ['bash', '-c', $command],
                        timeout: 120.0,
                        cwd: $safeCwd,
                    );

                    $conn->sendResponse(200, $headers, (string) json_encode([
                        'success' => true,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                        'exitCode' => $exit,
                        'stopped' => $exit === \Pig\Tui\Process::STOPPED,
                        'cwd' => $safeCwd,
                    ]));
                } catch (\Throwable $e) {
                    $conn->sendResponse(500, $headers, (string) json_encode([
                        'success' => false,
                        'error' => $e->getMessage(),
                    ]));
                }
            });

            return;
        }

        $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Not Found: {$path}");
    }

    // ---- WebSocket: binding and the relay --------------------------------------------------

    private function handleWsMessage(Connection $conn, int $connectionId, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            return;
        }

        $type = (string) ($data['type'] ?? '');

        if ($type === 'ping') {
            $conn->send(['type' => 'pong']);

            return;
        }

        if ($type === 'start_session') {
            $cwd = is_string($data['cwd'] ?? null) && trim($data['cwd']) !== '' ? $data['cwd'] : $this->cwd;
            $file = is_string($data['sessionFile'] ?? null) && $data['sessionFile'] !== '' ? $data['sessionFile'] : null;
            $tabId = is_string($data['tabId'] ?? null) && $data['tabId'] !== '' ? $data['tabId'] : 'default';

            // The child is started inside `bind()`, and starting is a `proc_open` plus two
            // watchers — synchronous, no fiber needed. What *is* needed is a catch: a child that
            // cannot start (no `bin/pig`, a bad `--session` path) is an `error` line for this
            // tab and nothing else, not a server that falls over.
            try {
                $clientTag = ($this->clientIds[$connectionId] ?? (string) $connectionId) . ($tabId !== 'default' ? ":{$tabId}" : '');
                $managed = $this->pool->bind($connectionId, $cwd, $file, $clientTag, $tabId);
            } catch (Throwable $e) {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId !== 'default' ? $tabId : null,
                    'message' => 'Could not start the session: ' . $e->getMessage(),
                ], static fn (mixed $v): bool => $v !== null));

                return;
            }

            $conn->send(array_filter([
                'type' => 'session_bound',
                'tabId' => $tabId !== 'default' ? $tabId : null,
                'cwd' => $managed->cwd,
                'sessionFile' => $managed->sessionFile,
                'shared' => count($managed->clients) > 1,
            ], static fn (mixed $v): bool => $v !== null));

            return;
        }

        if ($type === 'detach_session') {
            $tabId = is_string($data['tabId'] ?? null) && $data['tabId'] !== '' ? $data['tabId'] : null;
            $this->pool->detach($connectionId, $tabId);

            return;
        }

        if ($type === 'rpc_command') {
            $tabId = is_string($data['tabId'] ?? null) && $data['tabId'] !== '' ? $data['tabId'] : null;
            $managed = $this->pool->boundTo($connectionId, $tabId);
            $command = $data['command'] ?? null;

            if ($managed === null) {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId,
                    'message' => 'no active session',
                    'id' => is_array($command) ? ($command['id'] ?? null) : null,
                ], static fn (mixed $v): bool => $v !== null));

                return;
            }

            if (!is_array($command) || !is_string($command['type'] ?? null)) {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId,
                    'message' => 'rpc_command wants a command object with a type',
                ], static fn (mixed $v): bool => $v !== null));

                return;
            }

            try {
                $managed->client->relay($command);
            } catch (Throwable $e) {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId,
                    'message' => $e->getMessage(),
                    'id' => $command['id'] ?? null,
                ], static fn (mixed $v): bool => $v !== null));
            }

            return;
        }

        $conn->send(['type' => 'error', 'message' => "unknown message type '{$type}'"]);
    }

    /**
     * A line from a child, to every socket bound to it. `session_ended` is the pool's own and
     * goes out as itself; everything else — events and responses alike — is wrapped as
     * `rpc_event`, which is pi-web's envelope and lets the page tell "the child said" from
     * "the server said".
     *
     * In single-connection multiplexed mode, events are tagged with each subscribed tab's ID,
     * so one physical WebSocket routes events to multiple concurrent browser tabs seamlessly.
     *
     * @param array<string, mixed> $event
     */
    private function onSessionEvent(ManagedSession $managed, array $event): void
    {
        $base = ($event['type'] ?? null) === 'session_ended' ? $event : ['type' => 'rpc_event', 'event' => $event];

        foreach ($managed->clients as $connectionId => $tabIds) {
            $client = $this->wsClients[$connectionId] ?? null;

            if ($client === null || $client->isClosed()) {
                continue;
            }

            foreach (array_keys($tabIds) as $tabId) {
                $line = $tabId === 'default' ? $base : [...$base, 'tabId' => $tabId];
                $client->send($line);
            }
        }
    }

    // ---- payloads --------------------------------------------------------------------------

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

    /** The first thing said in this conversation, as a tab label when nobody named it. */
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

    /**
     * Resolve and validate that a session file path is safe and points inside a legitimate session root.
     */
    private function resolveSafeSessionPath(string $rawPath, ?string $cwd = null): ?string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return null;
        }

        $resolved = null;
        if (is_file($rawPath)) {
            $resolved = realpath($rawPath);
        } elseif ($cwd !== null) {
            $found = SessionManager::find($cwd, $rawPath);
            if ($found !== null && is_file($found)) {
                $resolved = realpath($found);
            }
        }

        if ($resolved === false || $resolved === null || !str_ends_with($resolved, '.jsonl')) {
            return null;
        }

        $allowedRoots = [
            realpath(Config::home() . '/sessions'),
            realpath(Config::piHome() . '/sessions'),
        ];

        foreach ($allowedRoots as $root) {
            if ($root !== false && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Compute terminal tab completions for command names and filesystem paths.
     *
     * @return array{prefix: string, completions: list<string>, commonPrefix: string}
     */
    private function getTerminalCompletions(string $line, string $cwd): array
    {
        $line = ltrim($line);
        if ($line === '') {
            return ['prefix' => '', 'completions' => [], 'commonPrefix' => ''];
        }

        $hasTrailingSpace = str_ends_with($line, ' ');
        $parts = preg_split('/\s+/', trim($line));
        if ($parts === false || $parts === []) {
            return ['prefix' => '', 'completions' => [], 'commonPrefix' => ''];
        }

        $tokenIndex = $hasTrailingSpace ? count($parts) : count($parts) - 1;
        $currentToken = $hasTrailingSpace ? '' : end($parts);

        $completions = [];

        // 1. Command completion at position 0
        if ($tokenIndex === 0) {
            $commonCommands = [
                'git', 'composer', 'php', 'cat', 'ls', 'cd', 'pwd', 'clear', 'rm', 'cp', 'mv',
                'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'grep', 'find', 'curl', 'wget',
                'docker', 'npm', 'node', 'yarn', 'pnpm', 'python', 'pip', 'make', 'ssh', 'tar',
                'zip', 'unzip', 'pig', 'kill', 'ps', 'top', 'df', 'du', 'head', 'tail', 'less',
                'more', 'echo', 'which', 'env', 'export', 'source'
            ];
            foreach ($commonCommands as $cmd) {
                if ($currentToken === '' || str_starts_with($cmd, $currentToken)) {
                    $completions[] = $cmd;
                }
            }
        }

        // 2. File / Path completion (at position > 0 or if token starts with ./, /, or ~)
        if ($tokenIndex > 0 || str_starts_with($currentToken, './') || str_starts_with($currentToken, '/') || str_starts_with($currentToken, '~')) {
            $rawPath = $currentToken;
            $isHome = str_starts_with($rawPath, '~');
            if ($isHome) {
                $home = getenv('HOME') ?: '/';
                $expanded = $home . substr($rawPath, 1);
            } else {
                $expanded = $rawPath;
            }

            if (str_contains($expanded, '/')) {
                $lastSlash = (int) strrpos($expanded, '/');
                $dirPart = substr($expanded, 0, $lastSlash + 1);
                $filePrefix = substr($expanded, $lastSlash + 1);
                $searchDir = str_starts_with($dirPart, '/') ? $dirPart : ($cwd . '/' . $dirPart);
                $rawPrefix = substr($rawPath, 0, (int) strrpos($rawPath, '/') + 1);
            } else {
                $searchDir = $cwd;
                $filePrefix = $rawPath;
                $rawPrefix = '';
            }

            $realSearchDir = realpath($searchDir);
            if ($realSearchDir !== false && is_dir($realSearchDir) && is_readable($realSearchDir)) {
                $entries = scandir($realSearchDir) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (!str_starts_with($filePrefix, '.') && str_starts_with($entry, '.')) {
                        continue;
                    }

                    if ($filePrefix === '' || str_starts_with(strtolower($entry), strtolower($filePrefix))) {
                        $isDir = is_dir($realSearchDir . '/' . $entry);
                        $completions[] = $rawPrefix . $entry . ($isDir ? '/' : '');
                    }
                }
            }
        }

        sort($completions);
        $completions = array_values(array_unique($completions));

        $commonPrefix = '';
        if (count($completions) === 1) {
            $commonPrefix = $completions[0];
        } elseif (count($completions) > 1) {
            $first = $completions[0];
            $last = end($completions);
            $len = min(strlen($first), strlen($last));
            $i = 0;
            while ($i < $len && $first[$i] === $last[$i]) {
                $i++;
            }
            $commonPrefix = substr($first, 0, $i);
        }

        return [
            'prefix' => $currentToken,
            'completions' => $completions,
            'commonPrefix' => $commonPrefix,
        ];
    }
}
