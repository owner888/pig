<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Models;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Web\Protocols\Websocket;
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
                $sessionArg = $sessionFile !== null ? (SessionManager::find($cwd, $sessionFile) ?? $sessionFile) : null;

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
                        $this->handleWsMessage($c, $id, $data);
                    }
                },
                onClose: fn (Connection $c) => $this->handleClose($id),
            );

            $this->connections[$id] = $conn;
            $watcher = Loop::get()->onReadable($client, static fn () => $conn->onReadable());
            $conn->setReadableWatcher($watcher);
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

        // 10.3c Antigravity accounts: the several Google sign-ins the extension keeps, which one is
        // live, and the active one's quota. Every write goes through `Auth`, so the store and
        // `auth.json` move together — the same methods `/antigravity.accounts` uses.
        if (str_starts_with($path, '/api/accounts')) {
            $this->handleAccounts($conn, $path, $req);

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
