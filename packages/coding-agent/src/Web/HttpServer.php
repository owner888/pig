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
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Rpc\RpcUi;
use Pig\CodingAgent\Accounts\User;
use Pig\CodingAgent\Sandbox\DockerSandbox;
use Pig\CodingAgent\Sandbox\Runtime;
use Pig\CodingAgent\Sandbox\SshSandbox;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\SqlSessionStore;
use Pig\CodingAgent\Web\Node\NodeManager;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\Protocols\Websocket;
use Pig\CodingAgent\Web\Pty\PtyManager;
use Pig\CodingAgent\Web\Schedule\ScheduleManager;
use Pig\CodingAgent\Logger;
use Throwable;

/**
 * The web shell: HTTP for the page and the lists, a WebSocket per browser tab, and one
 * `pig --mode rpc` child per conversation behind it, in a `SessionPool`.
 *
 * pi-web's `server.js`, as `HttpServer` was always meant to become. Until step ② of the process
 * model (docs/dev/design/web.md, "Three ways in") this class held **one** `AgentSession` and every tab in the
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
 * **The socket protocol**, pi-web's, three lines in and three out. Keep-alive is the WebSocket
 * protocol's own ping and pong (`armHeartbeat()`), which a browser answers by itself:
 *
 * | in | out |
 * |---|---|
 * | `start_session {cwd, sessionFile?, incognito?}` — bind this socket to a conversation, starting its child if there is none (`--incognito` for a private one) | `rpc_event {event}` — a line from the bound child, verbatim |
 * | `detach_session` | `session_ended {reason}` — the bound child is gone |
 * | `rpc_command {command}` — forwarded whole, id and all, to the bound child | `error {message}` |
 *
 * `extension_ui_request` and `extension_ui_response` are not special here any more: the child's `RpcMode`
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

    /** @var array<string, array{0: string, 1: string}> an asset's gzip, by path: its ETag and the bytes */
    private array $gzipped = [];

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, Connection> the connections that have upgraded to WebSocket */
    private array $wsClients = [];

    /** @var array<int, string> a stable id per socket, for the key a new conversation gets */
    private array $clientIds = [];

    /** @var array<int, string> per WebSocket, the login session it was opened with (`WebLogin::sessionKey()`) */
    private array $wsSessions = [];

    /** @var array<int, User> per WebSocket, who opened it */
    private array $wsUsers = [];

    /** @var array<string, string> terminalId => the account it is (`User::$id`), with accounts */
    private array $terminalOwners = [];

    /** Where Docker accounts work (`Settings::sandbox()`), read when first needed. */
    private ?DockerSandbox $sandbox = null;

    /** Where SSH accounts connect from (`Settings::sshSandbox()`), read when first needed. */
    private ?SshSandbox $ssh = null;

    private ?PtyManager $ptyManager = null;

    /** @var array<string, int> terminalId => connectionId */
    private array $terminalClient = [];

    /**
     * @var array<int, array<string, RpcUi>> connectionId => requestId => the `$ui` of an extension
     *      panel's action that page started, while it runs — what its answers are handed to
     */
    private array $panelUis = [];

    private ?string $heartbeatTimer = null;

    /**
     * Per page connection, the newest `tool_execution_update` of each running command it has not
     * been sent yet because it was behind, by `tabId|toolCallId`.
     *
     * @var array<int, array<string, array<string, mixed>>>
     */
    private array $held = [];
    private const float PING_INTERVAL = 25.0;

    /**
     * A browser answers every protocol ping with a pong, so a socket silent for two of them is a
     * peer that is gone or asleep — an iPad with its lid shut, a tab iOS has suspended. TCP would
     * take many minutes to say so, and until then the session kept the tab bound and its events
     * piled up behind it. Closed, the page reconnects when it wakes (`network.js`) and loads the
     * conversation again.
     */
    public const float SILENT_LIMIT = 2 * self::PING_INTERVAL + 10.0;

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
    private readonly NodeManager $nodeManager;
    private readonly ScheduleManager $schedules;

    /** Who may use the page — see `WebLogin` — and what every request passes through first. */
    private readonly WebLogin $login;

    /** @var list<Middleware> in order: a request from another site is refused before the login is asked */
    private readonly array $middlewares;

    /**
     * @param string $cwd where a new conversation opens when the page names no directory
     * @param (Closure(string, ?string): RpcClient)|null $spawn how a child is made; the default
     *        starts `bin/pig --mode rpc` in the conversation's directory, with `--session <file>`
     *        when it has one. Injectable so a test can hand back a client over a fixture.
     * @param WebLogin|null $login what the `storage` setting means unless given — the password in
     *        the agent directory, or the accounts in the database (`WebLogin::configured()`);
     *        `WebLogin::off()` only for tests of everything else
     */
    public function __construct(
        private readonly string $cwd,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
        ?Auth $auth = null,
        ?Closure $spawn = null,
        float $idleTtl = SessionPool::IDLE_TTL,
        ?WebLogin $login = null,
    ) {
        $this->auth = $auth ?? Auth::discover();
        $this->login = $login ?? WebLogin::configured(\Pig\CodingAgent\Config::home());
        $this->middlewares = [new Middlewares\SameOriginMiddleware(), new Middlewares\LoginMiddleware($this->login), new Middlewares\AdminMiddleware($this->login)];
        $this->nodeManager = new NodeManager();
        $this->pool = new SessionPool(
            spawn: $spawn ?? static function (string $cwd, ?string $sessionFile, ?string $theme = null, bool $incognito = false, ?string $owner = null, array $environment = []): RpcClient {
                $sessionArg = $sessionFile !== null && !$incognito ? SessionManager::find($cwd, $sessionFile) : null;
                // With accounts, the child writes as the conversation's owner: the one opening a new
                // conversation, or whoever owns the one opened — nobody, for one from before
                // accounts — so what is forked from it is the same person's.
                $store = SessionManager::sessionStore();
                if ($store instanceof SqlSessionStore && $sessionArg !== null) {
                    $owner = $store->ownerOf($sessionArg);
                }

                // A Docker or SSH account's child is started in a directory of this machine
                // (`Runtime::here()`), told the folder it works in there.
                $here = $environment['PIG_SANDBOX_HERE'] ?? null;
                unset($environment['PIG_SANDBOX_HERE']);

                return new RpcClient(
                    cwd: $here ?? $cwd,
                    arguments: [
                        ...match (true) {
                            $incognito => ['--incognito'],
                            $sessionArg !== null => ['--session', $sessionArg],
                            default => [],
                        },
                        // Such a child loads nothing of the project's own `.pig/`: it would run
                        // here, on the host, outside the container or the other machine.
                        ...($here !== null ? ['--no-approve', '--cwd', $cwd] : []),
                    ],
                    // The page's theme, which `RpcMode` makes the child's before `session_start`.
                    environment: array_filter([...$environment, 'PIG_WEB_THEME' => $theme, 'PIG_SESSION_OWNER' => $owner ?? ''], static fn (?string $v): bool => $v !== null),
                );
            },
            onEvent: $this->onSessionEvent(...),
            idleTtl: $idleTtl,
        );
        // With sessions in a database the tasks are kept there too, beside them (phase 4 step 5).
        $sessions = SessionManager::sessionStore();
        $this->schedules = new ScheduleManager(
            $this->pool,
            $this->broadcastSchedules(...),
            url: $this->localUrl(),
            store: $sessions instanceof SqlSessionStore
                ? new Schedule\SqlScheduleStore($sessions->database(), \Pig\CodingAgent\Config::home() . '/scheduled-tasks.json')
                : null,
            environmentFor: $this->environmentOf(...),
            elsewhere: $this->isElsewhere(...),
        );
    }

    /**
     * Where pig on this machine reaches this server: the address it listens on, or this machine's
     * loopback when it listens on every address.
     */
    public function localUrl(): string
    {
        $host = trim($this->host, '[]');
        $host = match ($host) {
            '0.0.0.0', '' => '127.0.0.1',
            '::' => '::1',
            default => $host,
        };

        return 'http://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$this->port}";
    }

    /** The login the page is behind: what `pig web start` asks whether there is an account yet. */
    public function login(): WebLogin
    {
        return $this->login;
    }

    /**
     * The scheduled tasks. `start()` does not start them: `WebMode` does, so a test that serves
     * the page from the developer's own home cannot fire the developer's tasks.
     */
    public function schedules(): ScheduleManager
    {
        return $this->schedules;
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
        // and not a reason the page does not come up — and so is a package that cannot be
        // resolved, as `bin/pig` treats it. The installed packages' extensions are loaded too, as
        // a child loads them: a language pack is a package, and `/api/locales` is the shell's.
        $settings = $this->auth->settings();
        [, $problems] = ExtensionLoader::load(
            $this->cwd,
            $settings?->extensions() ?? [],
            auth: $this->auth,
            packageExtensions: function () use ($settings): array {
                try {
                    return $settings === null ? [] : (new PackageManager($this->cwd, $settings))->resolve(static fn (): string => 'skip')->enabled('extensions');
                } catch (PackageError $error) {
                    fwrite(STDERR, "packages: {$error->getMessage()}\n");

                    return [];
                }
            },
        );

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
        $this->wsSessions = [];

        $this->ptyManager?->dispose();
        $this->ptyManager = null;
        $this->terminalClient = [];

        $this->schedules->stop();
        $this->pool->shutdown();
    }

    /** What a page that fell behind is sent once it has caught up: the newest of each. */
    private function flushHeld(int $connectionId): void
    {
        $lines = $this->held[$connectionId] ?? [];
        unset($this->held[$connectionId]);
        $client = $this->wsClients[$connectionId] ?? null;

        if ($client === null || $client->isClosed()) {
            return;
        }

        $client->onDrain(null);

        foreach ($lines as $line) {
            $client->send($line);
        }
    }

    private function handleClose(int $connectionId): void
    {
        unset($this->held[$connectionId]);
        // A panel's action asking a page that has gone is answered as a cancel, and finishes.
        foreach ($this->panelUis[$connectionId] ?? [] as $ui) {
            $ui->withdrawAll();
        }
        unset($this->panelUis[$connectionId]);

        $this->pool->detach($connectionId);
        unset($this->connections[$connectionId], $this->wsClients[$connectionId], $this->clientIds[$connectionId], $this->wsSessions[$connectionId], $this->wsUsers[$connectionId]);
    }

    private function ptyManager(): PtyManager
    {
        if ($this->ptyManager === null) {
            $this->ptyManager = new PtyManager(
                onOutput: function (string $terminalId, string $data): void {
                    $connId = $this->terminalClient[$terminalId] ?? null;
                    $conn = $connId !== null ? ($this->wsClients[$connId] ?? null) : null;
                    if ($conn !== null && !$conn->isClosed()) {
                        $conn->send([
                            'type' => 'terminal_output',
                            'terminalId' => $terminalId,
                            'data' => $data,
                        ]);
                    }
                },
                onExit: function (string $terminalId, ?int $exitCode): void {
                    $connId = $this->terminalClient[$terminalId] ?? null;
                    $conn = $connId !== null ? ($this->wsClients[$connId] ?? null) : null;
                    if ($conn !== null && !$conn->isClosed()) {
                        $conn->send([
                            'type' => 'terminal_exit',
                            'terminalId' => $terminalId,
                            'exitCode' => $exitCode,
                        ]);
                    }
                    unset($this->terminalClient[$terminalId]);
                },
            );
        }

        return $this->ptyManager;
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
                if ($wsClient->isClosed()) {
                    continue;
                }

                if ($wsClient->silentFor() > self::SILENT_LIMIT) {
                    $wsClient->close('silent for ' . (int) $wsClient->silentFor() . ' s, no pong');

                    continue;
                }

                Websocket::ping($wsClient);
            }

            $this->armHeartbeat();
        });
    }

    // ---- HTTP: the page and the lists ------------------------------------------------------

    /**
     * Every request through the middlewares (`Middleware`), then to `route()`.
     *
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function handleRequest(Connection $conn, array $req, int $connectionId): void
    {
        $conn->httpGzip = preg_match('/\bgzip\b/i', $req['headers']['accept-encoding'] ?? '') === 1;

        $next = function () use ($conn, $req, $connectionId): void {
            $this->route($conn, $req, $connectionId);
        };
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static fn () => $middleware->handle($req, $conn, $next);
        }
        $next();
    }

    /**
     * The login page and its four endpoints (`WebLogin`), which `LoginMiddleware` lets through
     * without a session: whether one is needed and how (`state`: the first account to make or not,
     * whether there can be several, and who is logged in), the first account while there is none (`setup`), a name and its
     * password (`/api/login`), and logging out. Answers whether it was one.
     *
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function routeLogin(Connection $conn, array $req): bool
    {
        $path = $req['path'];
        $json = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];

        if ($path === '/login') {
            $conn->sendResponse(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], (string) file_get_contents(__DIR__ . '/assets/login.html'));

            return true;
        }

        if ($path === '/api/login/state') {
            $user = $this->login->user($req['headers']);
            $conn->sendResponse(200, $json, (string) json_encode([
                'loggedIn' => $user !== null,
                'setup' => $this->login->enabled() && !$this->login->hasPassword(),
                'accounts' => $this->login->several(),
                'user' => $user?->toArray(),
            ]));

            return true;
        }

        if ($path === '/api/logout') {
            // The session's WebSockets go with it: a page kept open on one would otherwise stay
            // live until its first `fetch` came back 401, with the session already gone.
            $key = $this->login->sessionKey($req['headers']);
            $this->login->logOut($req['headers']);
            $conn->sendResponse(200, [...$json, 'Set-Cookie' => WebLogin::cookie(null)], (string) json_encode(['ok' => true]));

            if ($key !== null) {
                foreach ($this->wsSessions as $id => $sessionKey) {
                    if ($sessionKey === $key && isset($this->wsClients[$id]) && !$this->wsClients[$id]->isClosed()) {
                        // No reason given: a reason is a warning in the log, and this is not one.
                        $this->wsClients[$id]->close();
                    }
                }
            }

            return true;
        }

        if ($path !== '/api/login' && $path !== '/api/login/setup') {
            return false;
        }

        if ($req['method'] !== 'POST') {
            $conn->sendResponse(405, $json, (string) json_encode(['ok' => false, 'error' => 'POST only.']));

            return true;
        }

        $data = json_decode($req['body'], true);
        $data = is_array($data) ? $data : [];
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $address = $conn->remoteAddress();

        [$reason, $token] = $path === '/api/login/setup'
            ? $this->login->setUp($name, $password, $address)
            : $this->login->logIn($name, $password, $address);

        if ($token === null) {
            $conn->sendResponse(401, $json, (string) json_encode(['ok' => false, 'reason' => $reason, 'error' => WebLogin::REASONS[$reason] ?? $reason]));

            return true;
        }

        $conn->sendResponse(200, [...$json, 'Set-Cookie' => WebLogin::cookie($token)], (string) json_encode(['ok' => true]));

        return true;
    }

    /**
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     */
    private function route(Connection $conn, array $req, int $connectionId): void
    {
        $path = $req['path'];
        $json = ['Content-Type' => 'application/json'];

        if ($this->routeLogin($conn, $req) || $this->routeAccounts($conn, $req)) {
            return;
        }

        // 0. WebSocket upgrade handling (RFC 6455)
        if ($path === '/ws' || ($req['headers']['upgrade'] ?? '') === 'websocket') {
            if (Websocket::handshake($req, $conn)) {
                $this->wsClients[$connectionId] = $conn;
                $this->clientIds[$connectionId] = bin2hex(random_bytes(8));
                $key = $this->login->sessionKey($req['headers']);
                if ($key !== null) {
                    $this->wsSessions[$connectionId] = $key;
                }
                $this->wsUsers[$connectionId] = $this->userOf($req);

                return;
            }
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
            ], self::FAVICON_SVG);

            return;
        }

        // 1.2 Theme colours, written from the TUI theme files
        if ($path === '/theme.css') {
            $conn->sendResponse(200, [
                'Content-Type' => 'text/css; charset=utf-8',
                'Cache-Control' => 'no-cache',
            ], ThemeStylesheet::css());

            return;
        }

        // 1.3 Modular static assets (/assets/..., /css/..., /js/...) with path traversal guard
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

                // `no-cache` still: a page must see the assets of the pig it talks to, the moment
                // `pig update` has replaced them. What was missing was a way to ask cheaply — with no
                // validator, every load downloaded all of them again, 894KB in 28 requests. The ETag
                // is the file's mtime and size, weak because the gzip below is the same file encoded.
                $stat = stat($target);
                $etag = sprintf('W/"%x-%x"', $stat['mtime'] ?? 0, $stat['size'] ?? 0);
                $compressible = in_array($ext, ['css', 'js', 'mjs', 'svg', 'json'], true);
                $headers = ['Content-Type' => $mime, 'Cache-Control' => 'no-cache', 'ETag' => $etag];

                if ($compressible) {
                    $headers['Vary'] = 'Accept-Encoding';
                }

                if (str_contains($req['headers']['if-none-match'] ?? '', $etag)) {
                    $conn->sendResponse(304, $headers, '');

                    return;
                }

                $body = file_get_contents($target) ?: '';

                // Text compresses to about a quarter (899KB of assets to 239KB), which is the whole
                // load on a phone over a VPN. Each file once per version, then from memory.
                if ($compressible && preg_match('/\bgzip\b/i', $req['headers']['accept-encoding'] ?? '') === 1 && function_exists('gzencode')) {
                    $cached = $this->gzipped[$target] ?? null;
                    if ($cached === null || $cached[0] !== $etag) {
                        $cached = [$etag, gzencode($body, 6) ?: $body];
                        $this->gzipped[$target] = $cached;
                    }

                    if ($cached[1] !== $body) {
                        $body = $cached[1];
                        $headers['Content-Encoding'] = 'gzip';
                    }
                }

                $conn->sendResponse(200, $headers, $body);

                return;
            }

            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], "Asset Not Found: {$path}");

            return;
        }

        // 1.4 One image of a conversation's history, by the `ref` its `get_messages` gave
        // (`RpcMode::imagesAsRefs()`): the page puts this URL in an `<img loading="lazy">`, so a
        // history of screenshots costs a page only the ones it scrolls to. Asked of the running
        // child, from a fiber; the same image is the same bytes, so the browser keeps it.
        if ($path === '/api/session-image') {
            $query = $req['query'] ?? [];
            $cwd = is_string($query['cwd'] ?? null) ? $query['cwd'] : '';
            $file = is_string($query['file'] ?? null) ? $query['file'] : '';
            $ref = is_string($query['ref'] ?? null) ? $query['ref'] : '';
            $managed = $cwd !== '' && $file !== '' && $ref !== '' && $this->mayOpen($this->userOf($req), $cwd, $file) ? $this->pool->find($cwd, $file) : null;

            if ($managed === null) {
                $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'No such conversation running.');

                return;
            }

            Async::spawn(static function () use ($conn, $managed, $ref): void {
                try {
                    $image = $managed->client->getImage($ref);
                    $bytes = base64_decode($image['data'], true);
                } catch (Throwable $e) {
                    $conn->sendResponse(404, ['Content-Type' => 'text/plain'], $e->getMessage());

                    return;
                }

                // Raster only: an SVG is a document that can run script, on this page's origin.
                if ($bytes === false || !in_array($image['mimeType'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                    $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'Not an image.');

                    return;
                }

                $conn->sendResponse(200, [
                    'Content-Type' => $image['mimeType'],
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ], $bytes);
            });

            return;
        }

        // 2. Available models list — a fact about this machine's keys, not about any session.
        if ($path === '/api/models') {
            // Read again: a key stored by the terminal's pig since this one started counts.
            if ($this->auth->path() !== null) {
                $this->auth->reload();
            }

            $conn->sendResponse(200, $json, json_encode($this->getModelsPayload()));

            return;
        }

        // 2.0a API keys: which providers take one and whether each has one, and storing or
        // removing one — the page's key panel, and the prompt it opens when no model has a key.
        if ($path === '/api/keys') {
            if ($this->auth->path() !== null) {
                $this->auth->reload();
            }

            $conn->sendResponse(200, $json, (string) json_encode(['providers' => $this->keysPayload()]));

            return;
        }

        if ($path === '/api/keys/set' || $path === '/api/keys/remove') {
            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $json, (string) json_encode(['error' => 'POST only']));

                return;
            }

            $data = json_decode($req['body'] ?: '{}', true);
            $error = $this->changeKey($path === '/api/keys/set', is_array($data) ? $data : []);
            $conn->sendResponse($error === null ? 200 : 400, $json, (string) json_encode(
                $error === null ? ['providers' => $this->keysPayload()] : ['error' => $error],
            ));

            return;
        }

        // 2.1 The themes the page can show, for its theme picker.
        if ($path === '/api/themes') {
            $conn->sendResponse(200, $json, json_encode(ThemeStylesheet::payload()));

            return;
        }

        // 3. Which children are running, for a page reconnecting after a reload: it can tell a
        // tab whose agent is still working from one whose process has gone.
        if ($path === '/api/running') {
            $running = [];
            $user = $this->userOf($req);
            foreach ($this->pool->all() as $managed) {
                // Somebody else's conversation is not anybody else's business, an admin's included:
                // one's own, and of the new ones not saved yet, those one's own pages are on.
                $theirs = $managed->sessionFile !== null
                    ? $this->mayOpen($user, $managed->cwd, $managed->sessionFile)
                    : array_filter(array_keys($managed->clients), fn (int $id): bool => ($this->wsUsers[$id] ?? null)?->id === $user->id) !== [];
                if (!$theirs) {
                    continue;
                }

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

        // 7b. Scheduled tasks
        if (str_starts_with($path, '/api/scheduled')) {
            $this->handleScheduled($conn, $req, $path, $json);

            return;
        }

        // 8. Folders / Workspaces list (matching pi-web /api/folders)
        if ($path === '/api/folders') {
            // A Docker account's own directory is where its new conversations start.
            // The folders the admin has given it are listed beside it, to start one in too. An SSH
            // account's are another machine's, and are not looked for here.
            $folderUser = $this->userOf($req);
            $runtime = $this->sandboxOf($folderUser);
            try {
                $roots = $runtime?->roots($folderUser) ?? [$this->cwd];
            } catch (\Pig\Agent\AgentError) {
                $roots = [''];
            }
            $currentCwd = $roots[0];
            $workspaces = $this->asUser($folderUser, fn (): array => $this->listAllWorkspaces($currentCwd, array_slice($roots, 1), here: !$runtime instanceof SshSandbox));

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
            ], json_encode([
                'currentCwd' => $currentCwd,
                'workspaces' => $workspaces,
            ]));

            return;
        }

        // 8b. A directory's subdirectories, for the working-directory picker of a new conversation
        if ($path === '/api/dirs') {
            // A Docker account picks a directory inside its own or a folder given to it, and nowhere
            // else on the host; an SSH account's are listed there (`Runtime::dirs()`).
            $user = $this->userOf($req);
            $runtime = $this->sandboxOf($user);
            $asked = (string) ($req['query']['path'] ?? '');
            $hidden = ($req['query']['hidden'] ?? '') === '1';

            if ($runtime !== null) {
                Async::spawn(static function () use ($conn, $runtime, $user, $asked, $hidden): void {
                    $conn->sendResponse(200, ['Content-Type' => 'application/json'], (string) json_encode($runtime->dirs($user, $asked, $hidden)));
                });

                return;
            }

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
            ], (string) json_encode(DirectoryListing::list($asked, $this->cwd, getenv('HOME') ?: '/', $hidden)));

            return;
        }

        // 9. Sessions list for a directory (matching pi-web /api/sessions?cwd=...)
        if ($path === '/api/sessions') {
            $targetCwd = $req['query']['cwd'] ?? $this->cwd;
            $sessions = $this->asUser($this->userOf($req), static fn (): array => SessionManager::listFor($targetCwd));
            $items = array_map(static fn ($s) => [
                'id' => $s->id,
                'path' => $s->path,
                'filename' => basename($s->path),
                'opening' => $s->opening,
                'timestamp' => $s->timestamp,
            ], $sessions);

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
            ], json_encode($items));

            return;
        }

        // 9a. Every conversation across every project, newest first, for the sidebar (?q= searches)
        if ($path === '/api/sessions/all') {
            $limit = max(1, (int) ($req['query']['limit'] ?? 30));
            $offset = max(0, (int) ($req['query']['offset'] ?? 0));
            $query = trim((string) ($req['query']['q'] ?? ''));

            $viewer = $this->userOf($req);
            $scheduled = $this->schedules->sessionTasks($viewer);
            $taskIds = $this->schedules->sessionTaskIds($viewer);
            $unread = $this->schedules->unread($viewer);

            [$sessions, $total] = $this->asUser($this->userOf($req), static function () use ($query, $limit, $offset): array {
                if ($query === '') {
                    return SessionManager::listAll($limit, $offset, glance: true);
                }

                $found = SessionManager::searchAll($query, $limit);

                return [$found, count($found)];
            });

            $items = array_map(static fn ($s) => [
                'id' => $s->id,
                'path' => $s->path,
                'filename' => basename($s->path),
                'cwd' => $s->cwd,
                'opening' => $s->opening,
                'timestamp' => $s->timestamp,
                // Grouped by the last write, which is the order the list is in; the header's
                // timestamp is when the conversation started.
                'modified' => $s->modified,
                'scheduledTask' => $scheduled[basename($s->path)] ?? null,
                'scheduledTaskId' => $taskIds[basename($s->path)] ?? null,
                'scheduledUnread' => isset($unread[basename($s->path)]),
            ], $sessions);

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
            ], json_encode(['sessions' => $items, 'total' => $total]));

            return;
        }

        // 9b. Delete a session file
        if ($path === '/api/sessions/delete') {
            $headers = [
                'Content-Type' => 'application/json',
            ];

            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $headers, json_encode(['success' => false, 'error' => 'POST only']));
                return;
            }

            $data = json_decode($req['body'] ?? '{}', true) ?: [];
            $rawPath = is_string($data['path'] ?? null) ? $data['path'] : '';
            $targetCwd = is_string($data['cwd'] ?? null) ? $data['cwd'] : null;
            $safePath = $this->asUser($this->userOf($req), fn (): ?string => $this->resolveSafeSessionPath($rawPath, $targetCwd));

            if ($safePath === null) {
                $conn->sendResponse(400, $headers, json_encode(['success' => false, 'error' => 'Invalid session file']));
                return;
            }

            $err = \Pig\CodingAgent\Cli\SessionList::deleteSessionFile($safePath);

            if ($err !== null) {
                $conn->sendResponse(500, $headers, json_encode(['success' => false, 'error' => $err]));
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
            ];

            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $headers, json_encode(['success' => false, 'error' => 'POST only']));
                return;
            }

            $data = json_decode($req['body'] ?? '{}', true) ?: [];
            $rawPath = is_string($data['path'] ?? null) ? $data['path'] : '';
            $newName = trim((string) ($data['name'] ?? ''));
            $targetCwd = is_string($data['cwd'] ?? null) ? $data['cwd'] : null;
            $safePath = $this->asUser($this->userOf($req), fn (): ?string => $this->resolveSafeSessionPath($rawPath, $targetCwd));

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

            // A Docker or SSH account's files are read where its tools read them: a link it made to
            // the host's `/etc` is the container's `/etc` there.
            $user = $this->userOf($req);
            if (($runtime = $this->sandboxOf($user)) !== null) {
                $mime = match (strtolower(pathinfo((string) $filePath, PATHINFO_EXTENSION))) {
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'application/octet-stream',
                };
                Async::spawn(static function () use ($conn, $runtime, $user, $filePath, $mime): void {
                    try {
                        [$exit, $bytes] = \Pig\Tui\Process::runAsync($runtime->shell($user)->exec(['cat', '--', (string) $filePath]), 60.0);
                    } catch (\Pig\Agent\AgentError) {
                        $exit = 1;
                        $bytes = '';
                    }
                    $exit === 0
                        ? $conn->sendResponse(200, ['Content-Type' => $mime, 'Cache-Control' => 'max-age=86400'], $bytes)
                        : $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'File not found');
                });

                return;
            }

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
                    'Cache-Control' => 'max-age=86400',
                ], file_get_contents($realPath));

                return;
            }

            $conn->sendResponse(404, ['Content-Type' => 'text/plain'], 'File not found');

            return;
        }

        // 10.2c Stage a file picked or dropped in the browser (`ClipboardFile` for any file),
        // under its own name in a temp directory so its path goes into the prompt. Left behind on
        // purpose, as `ClipboardFile`'s are and as upstream leaves its `pi-clipboard-*`: the agent
        // reads the file after the prompt is sent, and a later visit to the conversation opens it
        // from the message's tile; the system temp directory is what cleans them up.
        if ($path === '/api/upload') {
            if ($req['method'] !== 'POST') {
                $conn->sendResponse(405, $json, (string) json_encode(['ok' => false, 'error' => 'POST only']));

                return;
            }

            $query = $req['query'] ?? [];
            $id = is_string($query['id'] ?? null) && preg_match('/^[0-9a-f]{16}$/', $query['id']) === 1
                ? $query['id']
                : bin2hex(random_bytes(8));
            $rawName = is_string($query['name'] ?? null) ? $query['name'] : '';
            $safeName = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename(str_replace('\\', '/', $rawName))) ?? '';
            $safeName = trim($safeName, '. ');
            if ($safeName === '') {
                $safeName = 'file';
            }

            // A Docker or SSH account's file goes into its own directory, written from where its
            // tools run: the agent reads it there, and a link it made cannot point the write at
            // the host.
            $user = $this->userOf($req);
            if (($runtime = $this->sandboxOf($user)) !== null) {
                $redirect = ($query['append'] ?? '') === '1' ? '>>' : '>';
                try {
                    $target = $runtime->workspace($user) . "/.pig-uploads/{$id}/{$safeName}";
                    [$exit, , $errors] = $runtime->ensure($user)->run(['sh', '-c', 'mkdir -p "$(dirname "$1")" && cat ' . $redirect . ' "$1"', 'sh', $target], $req['body']);
                } catch (\Pig\Agent\AgentError $e) {
                    [$exit, $errors, $target] = [1, $e->getMessage(), ''];
                }
                $exit === 0
                    ? $conn->sendResponse(200, $json, (string) json_encode(['ok' => true, 'path' => $target]))
                    : $conn->sendResponse(500, $json, (string) json_encode(['ok' => false, 'error' => 'Could not write uploaded file: ' . trim($errors)]));

                return;
            }

            $dir = rtrim(sys_get_temp_dir(), '/') . '/pig-upload-' . $id;
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                $conn->sendResponse(500, $json, (string) json_encode(['ok' => false, 'error' => 'Could not create upload directory']));

                return;
            }

            $target = $dir . '/' . $safeName;
            $flags = ($query['append'] ?? '') === '1' ? FILE_APPEND : 0;
            if (file_put_contents($target, $req['body'], $flags) === false) {
                $conn->sendResponse(500, $json, (string) json_encode(['ok' => false, 'error' => 'Could not write uploaded file']));

                return;
            }

            $conn->sendResponse(200, $json, (string) json_encode(['ok' => true, 'path' => $target]));

            return;
        }

        // 10.3c What the loaded extensions answer for: their panels (`WebPanels`, from
        // `ExtensionApi::registerWebPanel()`) and any endpoints of their own
        // (`ExtensionApi::registerHttpRoute()`), rather than either being written in here. Answered
        // from a fiber, because a handler may make a network call and the loop has to keep serving
        // the page.
        $route = WebPanels::handles($path)
            ? WebPanels::answer(...)
            : (str_starts_with($path, '/api/') ? ExtensionApi::httpRouteFor($path) : null);
        if ($route !== null) {
            \Pig\Async\Async::spawn(function () use ($conn, $route, $path, $req): void {
                $headers = ['Content-Type' => 'application/json'];

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
            ], (string) json_encode([
                'success' => true,
                'locales' => ExtensionApi::registeredLocales(),
            ]));

            return;
        }

        // 10.5 Web Terminal info & command execution
        if ($path === '/api/terminal/info') {
            $user = $this->userOf($req);
            if (($runtime = $this->sandboxOf($user)) !== null) {
                try {
                    $workspace = $runtime->workspace($user);
                } catch (\Pig\Agent\AgentError) {
                    $workspace = '/';
                }
                $conn->sendResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'success' => true,
                    'user' => $runtime instanceof SshSandbox ? (string) ($user->ssh['user'] ?? $user->name) : $user->name,
                    'hostname' => $runtime instanceof SshSandbox ? (string) ($user->ssh['host'] ?? 'ssh') : 'pig-sandbox',
                    'cwd' => (string) ($req['query']['cwd'] ?? '') ?: $workspace,
                    'home' => $workspace,
                ]));

                return;
            }

            $targetCwd = $req['query']['cwd'] ?? $this->cwd;
            $safeCwd = realpath($targetCwd) ?: $this->cwd;
            $user = getenv('USER') ?: (getenv('LOGNAME') ?: 'user');
            $hostname = gethostname() ?: 'pig';

            $conn->sendResponse(200, [
                'Content-Type' => 'application/json',
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
            ];

            // Completion reads the host's files and commands: a Docker or SSH account gets none.
            if ($this->sandboxOf($this->userOf($req)) !== null) {
                $conn->sendResponse(200, $headers, (string) json_encode(['success' => true, 'prefix' => '', 'completions' => [], 'commonPrefix' => '']));

                return;
            }

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

            // A Docker or SSH account's command runs where its tools do — `cd` too, which is asked
            // there.
            $user = $this->userOf($req);
            if (($sandbox = $this->sandboxOf($user)) !== null) {
                Async::spawn(function () use ($conn, $headers, $command, $targetCwd, $sandbox, $user): void {
                    $cwd = $targetCwd;
                    try {
                        $cwd = $targetCwd !== '' ? $targetCwd : $sandbox->workspace($user);
                        $container = $sandbox->ensure($user);
                        $cd = preg_match('/^cd(?:\s+(.*))?$/', $command, $m) === 1;
                        $inside = $cd ? ['bash', '-c', 't=$1; case $t in "~"*) t=$HOME${t#"~"};; esac; cd -- "${t:-$HOME}" && pwd', 'bash', trim($m[1] ?? '')] : ['bash', '-c', $command];
                        [$exit, $stdout, $stderr] = \Pig\Tui\Process::runAsync($container->exec($inside, $cwd), timeout: 120.0);
                        $conn->sendResponse(200, $headers, (string) json_encode([
                            'success' => true,
                            'stdout' => $cd ? '' : $stdout,
                            'stderr' => $stderr,
                            'exitCode' => $exit,
                            'stopped' => $exit === \Pig\Tui\Process::STOPPED,
                            'cwd' => $cd && $exit === 0 ? trim($stdout) : $cwd,
                        ]));
                    } catch (\Throwable $e) {
                        $conn->sendResponse(500, $headers, (string) json_encode(['success' => false, 'error' => $e->getMessage()]));
                    }
                });

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

        if ($type === 'start_session') {
            $asked = is_string($data['cwd'] ?? null) && trim($data['cwd']) !== '' ? $data['cwd'] : '';
            $rawCwd = $asked !== '' ? $asked : $this->cwd;
            $cwd = is_dir($rawCwd) ? $rawCwd : $this->cwd;
            $file = is_string($data['sessionFile'] ?? null) && $data['sessionFile'] !== '' ? $data['sessionFile'] : null;
            $tabId = is_string($data['tabId'] ?? null) && $data['tabId'] !== '' ? $data['tabId'] : 'default';
            $theme = is_string($data['theme'] ?? null) && $data['theme'] !== '' ? $data['theme'] : null;
            // A private tab (the page's "New private session"): a child started with `--incognito`.
            $incognito = ($data['incognito'] ?? false) === true;
            // The page's own id, the same across its reconnects (pig's): a tab whose conversation is
            // not saved yet is bound to the same child again rather than a new one, so a prompt sent
            // again under its `requestId` after a dropped connection reaches the child that took it.
            // Per connection for a page that sends none.
            $pageId = is_string($data['pageId'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $data['pageId']) === 1 ? $data['pageId'] : null;

            // The child is started inside `bind()`, and starting is a `proc_open` plus two
            // watchers — synchronous, no fiber needed. What *is* needed is a catch: a child that
            // cannot start (no `bin/pig`, a bad `--session` path) is an `error` line for this
            // tab and nothing else, not a server that falls over.
            // Somebody else's conversation is answered as one that is not there.
            $user = $this->wsUsers[$connectionId] ?? new User('', '', true);
            $runtime = $this->sandboxOf($user);
            if ($file !== null && !$incognito && !$this->mayOpen($user, $runtime !== null && $asked !== '' ? $asked : $cwd, $file)) {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId !== 'default' ? $tabId : null,
                    'message' => 'Could not start the session: no such conversation.',
                ], static fn (mixed $v): bool => $v !== null));

                return;
            }

            $clientTag = ($pageId ?? $this->clientIds[$connectionId] ?? (string) $connectionId) . ($tabId !== 'default' ? ":{$tabId}" : '');
            $failed = static function (Throwable $e) use ($conn, $tabId): void {
                $conn->send(array_filter([
                    'type' => 'error',
                    'tabId' => $tabId !== 'default' ? $tabId : null,
                    'message' => 'Could not start the session: ' . $e->getMessage(),
                ], static fn (mixed $v): bool => $v !== null));
            };

            // A Docker or SSH account works where its tools run: its container started if it is
            // not running, its connection made — on the loop, since that can take a while (an
            // unreachable machine, `ConnectTimeout`) and nobody else's page should wait on it.
            if ($runtime !== null) {
                Async::spawn(function () use ($conn, $connectionId, $runtime, $user, $asked, $file, $clientTag, $tabId, $theme, $incognito, $failed): void {
                    try {
                        $shell = $runtime->ensure($user);
                        $where = $asked !== '' && $runtime->contains($user, $asked) ? $asked : $runtime->workspace($user);
                        $managed = $this->pool->bind($connectionId, $where, $file, $clientTag, $tabId, $theme, $incognito, $user->id === '' ? null : $user->id, [
                            ...$shell->environment(),
                            'PIG_SANDBOX_HERE' => $runtime->here($user, $where),
                        ]);
                    } catch (Throwable $e) {
                        $failed($e);

                        return;
                    }

                    $this->sessionBound($conn, $managed, $tabId);
                });

                return;
            }

            try {
                $managed = $this->pool->bind($connectionId, $cwd, $file, $clientTag, $tabId, $theme, $incognito, $user->id === '' ? null : $user->id);
            } catch (Throwable $e) {
                $failed($e);

                return;
            }

            $this->sessionBound($conn, $managed, $tabId);

            return;
        }

        if ($type === 'detach_session') {
            $tabId = is_string($data['tabId'] ?? null) && $data['tabId'] !== '' ? $data['tabId'] : null;
            $this->pool->detach($connectionId, $tabId);

            return;
        }

        if (str_starts_with($type, 'terminal_')) {
            $pty = $this->ptyManager();
            $user = $this->wsUsers[$connectionId] ?? new User('', '', true);
            $targetTermId = (string) ($data['terminalId'] ?? $data['id'] ?? '');

            // A terminal is the account's that opened it: nobody else's page may type into it,
            // read it or list it.
            if ($targetTermId !== '' && isset($this->terminalOwners[$targetTermId]) && $this->terminalOwners[$targetTermId] !== $user->id) {
                return;
            }

            if ($targetTermId !== '') {
                $this->terminalClient[$targetTermId] = $connectionId;
            }
            $mine = fn (): array => array_values(array_filter($pty->list(), fn (array $t): bool => ($this->terminalOwners[$t['id']] ?? $user->id) === $user->id));

            match ($type) {
                'terminal_create' => (function () use ($conn, $pty, $data, $connectionId, $user, $mine): void {
                    $id = (string) ($data['terminalId'] ?? $data['id'] ?? bin2hex(random_bytes(6)));
                    $this->terminalClient[$id] = $connectionId;
                    $cwd = (string) ($data['cwd'] ?? $this->cwd);
                    $cols = (int) ($data['cols'] ?? 80);
                    $rows = (int) ($data['rows'] ?? 24);
                    $command = isset($data['command']) && is_string($data['command']) && $data['command'] !== ''
                        ? $data['command']
                        : null;

                    $open = function (string $cwd, ?string $command) use ($conn, $pty, $id, $cols, $rows, $user, $mine): void {
                        try {
                            $this->terminalOwners[$id] = $user->id;
                            $proc = $pty->create($id, $cwd, $cols, $rows, $command);
                        } catch (Throwable $e) {
                            $conn->send([
                                'type' => 'terminal_exit',
                                'terminalId' => $id,
                                'exitCode' => 1,
                                'error' => $e->getMessage(),
                            ]);
                            return;
                        }

                        $conn->send([
                            'type' => 'terminal_list',
                            'terminals' => $mine(),
                        ]);

                        if ($proc->scrollback() !== '') {
                            $conn->send([
                                'type' => 'terminal_output',
                                'terminalId' => $id,
                                'data' => $proc->scrollback(),
                            ]);
                        }
                    };

                    // A Docker or SSH account's terminal is a shell where its tools run, never one
                    // here: started from a directory of this machine (`Runtime::here()`), once its
                    // container is up or its connection made, on the loop.
                    $runtime = $this->sandboxOf($user);
                    if ($runtime === null) {
                        $open($cwd, $command);

                        return;
                    }

                    Async::spawn(function () use ($conn, $runtime, $user, $id, $cwd, $command, $open): void {
                        try {
                            $shell = $runtime->ensure($user);
                            $cwd = $runtime->contains($user, $cwd) ? $cwd : $runtime->workspace($user);
                            $command = 'exec ' . implode(' ', array_map('escapeshellarg', $shell->exec(
                                $command === null ? ['bash', '-l'] : ['bash', '-lc', $command],
                                $cwd,
                                ['TERM' => 'xterm-256color'],
                                tty: true,
                            )));
                            $here = $runtime->here($user, $cwd);
                        } catch (Throwable $e) {
                            $conn->send(['type' => 'terminal_exit', 'terminalId' => $id, 'exitCode' => 1, 'error' => $e->getMessage()]);

                            return;
                        }

                        $open($here, $command);
                    });
                })(),
                'terminal_input' => (function () use ($pty, $data): void {
                    $tid = (string) ($data['terminalId'] ?? '');
                    $inputData = (string) ($data['data'] ?? '');
                    $pty->input($tid, $inputData);
                })(),
                'terminal_resize' => $pty->resize(
                    (string) ($data['terminalId'] ?? ''),
                    (int) ($data['cols'] ?? 80),
                    (int) ($data['rows'] ?? 24),
                ),
                'terminal_kill' => (function () use ($conn, $pty, $data, $mine): void {
                    $id = (string) ($data['terminalId'] ?? '');
                    $pty->kill($id);
                    unset($this->terminalOwners[$id]);
                    $conn->send([
                        'type' => 'terminal_list',
                        'terminals' => $mine(),
                    ]);
                })(),
                'terminal_list' => $conn->send([
                    'type' => 'terminal_list',
                    'terminals' => $mine(),
                ]),
                default => null,
            };

            return;
        }

        // An extension panel's action (`WebPanels`), from the page that pressed the button: it runs
        // in a fiber with an `RpcUi` whose questions — `confirm()`, `select()`, `input()`… — go to
        // this page as `panel_ui` and whose answers come back as `panel_ui_response`; the outcome
        // and the panel drawn again are `panel_result`. The same request id ties the three.
        if ($type === 'panel_action') {
            $requestId = is_string($data['requestId'] ?? null) ? $data['requestId'] : '';
            if ($requestId === '' || isset($this->panelUis[$connectionId][$requestId])) {
                return;
            }

            // The server's extensions' panels are the admin's (`AdminMiddleware`).
            if ($this->login->several() && !($this->wsUsers[$connectionId] ?? null)?->admin) {
                $conn->send(['type' => 'panel_result', 'requestId' => $requestId, 'ok' => false, 'error' => 'Only an admin may do that.']);

                return;
            }

            $ui = new RpcUi(static function (array $line) use ($conn, $requestId): void {
                $conn->send(['type' => 'panel_ui', 'requestId' => $requestId, 'request' => $line]);
            });
            $this->panelUis[$connectionId][$requestId] = $ui;

            Async::spawn(function () use ($conn, $connectionId, $requestId, $data, $ui): void {
                try {
                    $result = WebPanels::actFor($data, $ui);
                } finally {
                    unset($this->panelUis[$connectionId][$requestId]);
                }

                if (isset($this->wsClients[$connectionId])) {
                    $conn->send(['type' => 'panel_result', 'requestId' => $requestId, ...$result]);
                }
            });

            return;
        }

        if ($type === 'panel_ui_response') {
            $requestId = is_string($data['requestId'] ?? null) ? $data['requestId'] : '';
            $reply = is_array($data['reply'] ?? null) ? $data['reply'] : [];
            ($this->panelUis[$connectionId][$requestId] ?? null)?->answer($reply);

            return;
        }

        if ($type === 'node_request') {
            $action = (string) ($data['action'] ?? '');
            $requestId = (string) ($data['requestId'] ?? '');

            // The SSH nodes are the server's, as its keys are (`Middlewares\AdminMiddleware`).
            if ($this->login->several() && !($this->wsUsers[$connectionId] ?? null)?->admin) {
                $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Only an admin may use the SSH nodes.']);

                return;
            }
            $nodeId = isset($data['nodeId']) && is_string($data['nodeId']) ? $data['nodeId'] : null;
            $p = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

            match ($action) {
                'state' => $conn->send([
                    'type' => 'node_event',
                    'event' => 'state',
                    'requestId' => $requestId,
                    'data' => [
                        'nodes' => $this->nodeManager->listNodes(),
                        'sources' => $this->nodeManager->detectSshConfig(),
                    ],
                ]),
                'save' => (function () use ($conn, $requestId, $p): void {
                    $secret = isset($p['secret']) && is_string($p['secret']) ? $p['secret'] : null;
                    $node = $this->nodeManager->saveNode($p, $secret);
                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'save',
                        'data' => ['id' => $node->id],
                    ]);
                    $this->broadcastNodeState();
                })(),
                'delete' => (function () use ($conn, $requestId, $nodeId): void {
                    if ($nodeId !== null) {
                        $this->nodeManager->deleteNode($nodeId);
                    }
                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'delete',
                        'data' => ['success' => true],
                    ]);
                    $this->broadcastNodeState();
                })(),
                'connect' => (function () use ($conn, $requestId, $nodeId): void {
                    $node = $nodeId ? $this->nodeManager->getNode($nodeId) : null;
                    if ($node === null) {
                        $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Node not found']);
                        return;
                    }

                    $fp = $this->nodeManager->scanFingerprint($node->host, $node->port);
                    if ($node->fingerprint === null && $fp !== null) {
                        $conn->send([
                            'type' => 'node_event',
                            'event' => 'fingerprint_prompt',
                            'requestId' => $requestId,
                            'nodeId' => $node->id,
                            'data' => ['fingerprint' => $fp],
                        ]);
                        return;
                    }

                    if ($node->fingerprint !== null && $fp !== null && $node->fingerprint !== $fp) {
                        $conn->send([
                            'type' => 'node_event',
                            'event' => 'failure',
                            'requestId' => $requestId,
                            'message' => 'Host key changed! Potential security mismatch.',
                        ]);
                        return;
                    }

                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'connect',
                        'nodeId' => $node->id,
                        'data' => ['status' => 'connected', 'nodeId' => $node->id],
                    ]);
                })(),
                'trust' => (function () use ($conn, $requestId, $nodeId, $p): void {
                    $fp = (string) ($p['fingerprint'] ?? '');
                    if ($nodeId && $fp) {
                        $this->nodeManager->trustFingerprint($nodeId, $fp);
                    }
                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'trust',
                        'data' => ['status' => 'trusted', 'nodeId' => $nodeId],
                    ]);
                    $this->broadcastNodeState();
                })(),
                'forget_host_key' => (function () use ($conn, $requestId, $nodeId): void {
                    if ($nodeId) {
                        $this->nodeManager->forgetFingerprint($nodeId);
                    }
                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'forget_host_key',
                        'data' => ['success' => true],
                    ]);
                    $this->broadcastNodeState();
                })(),
                'terminal_open' => (function () use ($conn, $connectionId, $requestId, $nodeId, $data, $p): void {
                    $node = $nodeId ? $this->nodeManager->getNode($nodeId) : null;
                    if ($node === null) {
                        $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Node not found']);
                        return;
                    }

                    $sshCmd = $this->nodeManager->buildSshCommand($node, interactive: true);
                    $termId = (string) ($data['terminalId'] ?? 'ssh-' . bin2hex(random_bytes(4)));
                    $this->terminalClient[$termId] = $connectionId;
                    $pty = $this->ptyManager();
                    $cols = (int) ($p['cols'] ?? 80);
                    $rows = (int) ($p['rows'] ?? 24);
                    $cmdStr = implode(' ', array_map('escapeshellarg', $sshCmd));
                    $pty->create($termId, $this->cwd, $cols, $rows, $cmdStr);

                    $conn->send([
                        'type' => 'node_event',
                        'event' => 'result',
                        'requestId' => $requestId,
                        'action' => 'terminal_open',
                        'terminalId' => $termId,
                        'nodeId' => $node->id,
                        'data' => ['terminalId' => $termId],
                    ]);
                })(),
                'list' => (function () use ($conn, $requestId, $nodeId, $p): void {
                    $node = $nodeId ? $this->nodeManager->getNode($nodeId) : null;
                    if ($node === null) {
                        $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Node not found']);
                        return;
                    }

                    $path = (string) ($p['path'] ?? $node->defaultDir);
                    Async::spawn(function () use ($conn, $requestId, $node, $path): void {
                        try {
                            $entries = $this->nodeManager->listRemoteDir($node, $path);
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'result',
                                'requestId' => $requestId,
                                'action' => 'list',
                                'nodeId' => $node->id,
                                'data' => ['path' => $path, 'entries' => $entries],
                            ]);
                        } catch (Throwable $e) {
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'failure',
                                'requestId' => $requestId,
                                'action' => 'list',
                                'message' => $e->getMessage(),
                            ]);
                        }
                    });
                })(),
                'read' => (function () use ($conn, $requestId, $nodeId, $p): void {
                    $node = $nodeId ? $this->nodeManager->getNode($nodeId) : null;
                    if ($node === null) {
                        $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Node not found']);
                        return;
                    }

                    $path = (string) ($p['path'] ?? '');
                    Async::spawn(function () use ($conn, $requestId, $node, $path): void {
                        try {
                            $text = $this->nodeManager->readRemoteFile($node, $path);
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'result',
                                'requestId' => $requestId,
                                'action' => 'read',
                                'nodeId' => $node->id,
                                'data' => ['path' => $path, 'text' => $text],
                            ]);
                        } catch (Throwable $e) {
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'failure',
                                'requestId' => $requestId,
                                'action' => 'read',
                                'message' => $e->getMessage(),
                            ]);
                        }
                    });
                })(),
                'write' => (function () use ($conn, $requestId, $nodeId, $p): void {
                    $node = $nodeId ? $this->nodeManager->getNode($nodeId) : null;
                    if ($node === null) {
                        $conn->send(['type' => 'node_event', 'event' => 'failure', 'requestId' => $requestId, 'message' => 'Node not found']);
                        return;
                    }

                    $path = (string) ($p['path'] ?? '');
                    $text = (string) ($p['text'] ?? '');
                    Async::spawn(function () use ($conn, $requestId, $node, $path, $text): void {
                        try {
                            $this->nodeManager->writeRemoteFile($node, $path, $text);
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'result',
                                'requestId' => $requestId,
                                'action' => 'write',
                                'nodeId' => $node->id,
                                'data' => ['path' => $path, 'success' => true],
                            ]);
                        } catch (Throwable $e) {
                            $conn->send([
                                'type' => 'node_event',
                                'event' => 'failure',
                                'requestId' => $requestId,
                                'action' => 'write',
                                'message' => $e->getMessage(),
                            ]);
                        }
                    });
                })(),
                default => $conn->send([
                    'type' => 'node_event',
                    'event' => 'failure',
                    'requestId' => $requestId,
                    'message' => "Unknown node action '{$action}'",
                ]),
            };

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

            if ($command['type'] === 'extension_ui_response' && is_string($command['id'] ?? null)) {
                unset($managed->pendingUi[$command['id']]);
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
        $this->schedules->observe($managed, $event);

        $browserEvent = self::slimForBrowser($event);
        if ($browserEvent === null) {
            return;
        }

        $base = ($browserEvent['type'] ?? null) === 'session_ended' ? $browserEvent : ['type' => 'rpc_event', 'event' => $browserEvent];
        $type = $browserEvent['type'] ?? null;
        $toolCallId = is_string($browserEvent['toolCallId'] ?? null) ? $browserEvent['toolCallId'] : null;

        foreach ($managed->clients as $connectionId => $tabIds) {
            $client = $this->wsClients[$connectionId] ?? null;

            if ($client === null || $client->isClosed()) {
                continue;
            }

            foreach (array_keys($tabIds) as $tabId) {
                $line = $tabId === 'default' ? $base : [...$base, 'tabId' => $tabId];
                $key = "{$tabId}|{$toolCallId}";

                // A running command's output is a snapshot each time: a page that is behind gets
                // the newest when it catches up, not every one in between (`flushHeld()`).
                if ($type === 'tool_execution_update' && $toolCallId !== null && $client->hasBacklog()) {
                    $this->held[$connectionId][$key] = $line;
                    $client->onDrain(fn () => $this->flushHeld($connectionId));

                    continue;
                }

                // The end says it all; an update held for it would only arrive after it.
                if ($type === 'tool_execution_end') {
                    unset($this->held[$connectionId][$key]);
                }

                $client->send($line);
            }
        }
    }

    /**
     * Strip live RPC events that the browser page never draws or that duplicate full messages
     * and base64 screenshots 4–5 times per turn over a slow tunnel (`entry_appended`, `turn_end`,
     * `message_update`'s `done`/`text_end`/`thinking_end`/`toolcall_*`, `agent_end.messages`, and
     * `message_end`'s full `toolResult` / `assistant` payloads).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private static function slimForBrowser(array $event): ?array
    {
        $type = $event['type'] ?? null;

        if ($type === 'entry_appended' || $type === 'turn_end') {
            return null;
        }

        if ($type === 'message_update') {
            $sub = $event['assistantMessageEvent']['type'] ?? null;
            if (!in_array($sub, ['text_delta', 'thinking_delta', 'error'], true)) {
                return null;
            }
            unset($event['usage']);

            return $event;
        }

        if ($type === 'agent_end') {
            unset($event['messages']);

            return $event;
        }

        if ($type === 'message_start') {
            $role = $event['message']['role'] ?? null;
            if ($role === 'system' || $role === 'assistant' || $role === 'toolResult' || $role === 'tool_result') {
                return ['type' => 'message_start', 'message' => ['role' => $role]];
            }
        }

        if ($type === 'message_end') {
            $msg = $event['message'] ?? null;
            if (!is_array($msg)) {
                return $event;
            }
            $role = $msg['role'] ?? null;
            if ($role === 'user' || $role === 'system' || $role === 'toolResult' || $role === 'tool_result') {
                // `tool_execution_end` and `message_start` already delivered the message; `message_end` only counts for `LIVE_CAP`.
                return ['type' => 'message_end', 'message' => ['role' => $role === 'tool_result' ? 'toolResult' : $role]];
            }
            if ($role === 'assistant') {
                $hasToolCall = false;
                foreach ($msg['content'] ?? [] as $c) {
                    if (is_array($c) && in_array($c['type'] ?? null, ['toolCall', 'tool_call'], true)) {
                        $hasToolCall = true;
                        break;
                    }
                }

                return [
                    'type' => 'message_end',
                    'message' => array_filter([
                        'role' => 'assistant',
                        'stopReason' => $msg['stopReason'] ?? null,
                        'errorMessage' => $msg['errorMessage'] ?? null,
                        'content' => $hasToolCall ? [['type' => 'toolCall']] : null,
                    ], static fn (mixed $v): bool => $v !== null),
                ];
            }
        }

        return $event;
    }

    /** Every page re-reads `/api/scheduled` on this; the list is small and one shape is enough. */
    private function broadcastSchedules(): void
    {
        foreach ($this->wsClients as $client) {
            if (!$client->isClosed()) {
                $client->send(['type' => 'scheduled_update']);
            }
        }
    }

    private function broadcastNodeState(): void
    {
        $payload = [
            'type' => 'node_event',
            'event' => 'state',
            'data' => [
                'nodes' => $this->nodeManager->listNodes(),
                'sources' => $this->nodeManager->detectSshConfig(),
            ],
        ];

        foreach ($this->wsClients as $client) {
            if (!$client->isClosed()) {
                $client->send($payload);
            }
        }
    }

    /**
     * `/api/scheduled` and the five writes under it. A mistake in what was sent is a 400 with the
     * reason, and a write to a schedule another server owns is a 409.
     *
     * @param array{method: string, path: string, query: array<string, string>, body: string} $req
     * @param array<string, string> $json
     */
    private function handleScheduled(Connection $conn, array $req, string $path, array $json): void
    {
        $answer = static fn (int $status, array $body) => $conn->sendResponse($status, $json, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        // Each account its own tasks (phase 4 step 5): the page's by its login, pig's on this
        // machine by the account its conversation speaks for.
        $user = $this->userOf($req);

        if ($path === '/api/scheduled') {
            $answer(200, [
                'tasks' => $this->schedules->list($user),
                'owner' => $this->schedules->owns(),
                'problems' => $this->schedules->problems(),
            ]);

            return;
        }

        if ($path === '/api/scheduled/runs') {
            $answer(200, ['runs' => $this->schedules->runs((string) ($req['query']['id'] ?? ''), $user)]);

            return;
        }

        if ($req['method'] !== 'POST') {
            $answer(405, ['success' => false, 'error' => 'POST only']);

            return;
        }

        $data = json_decode($req['body'] ?: '{}', true);
        $data = is_array($data) ? $data : [];
        $id = is_string($data['id'] ?? null) ? $data['id'] : '';

        try {
            // A Docker account's task works in its own directory or a folder given to it, as its
            // conversations do; an SSH account's in a folder there, looked for there.
            $sandbox = $this->sandboxOf($user);
            if ($path === '/api/scheduled/save' && $sandbox !== null) {
                $cwd = is_string($data['cwd'] ?? null) ? rtrim($data['cwd'], '/') ?: '/' : '';
                if ($cwd === '' || !$sandbox->contains($user, $cwd)) {
                    throw new \InvalidArgumentException('A task works in your own folder (' . $sandbox->workspace($user) . ') or one given to you.');
                }
                if ($sandbox instanceof SshSandbox && \Pig\Tui\Process::runAsync($sandbox->shell($user)->exec(['test', '-d', $cwd]), 30.0)[0] !== 0) {
                    throw new \InvalidArgumentException("The folder '{$cwd}' does not exist there.");
                }
            }

            $result = match ($path) {
                '/api/scheduled/save' => ['task' => $this->schedules->save($data, $user)->toArray()],
                '/api/scheduled/delete' => $this->schedules->delete($id, $user) ?? [],
                '/api/scheduled/toggle' => $this->schedules->setEnabled($id, ($data['enabled'] ?? false) === true, $user) ?? [],
                '/api/scheduled/run' => $this->schedules->runNow($id, $user) ?? [],
                '/api/scheduled/seen' => $this->schedules->markSeen(is_string($data['sessionFile'] ?? null) ? $data['sessionFile'] : '', $user) ?? [],
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $answer(400, ['success' => false, 'error' => $e->getMessage()]);

            return;
        } catch (\RuntimeException $e) {
            $answer(409, ['success' => false, 'error' => $e->getMessage()]);

            return;
        }

        if ($result === null) {
            $answer(404, ['success' => false, 'error' => "No such endpoint: {$path}"]);

            return;
        }

        $answer(200, ['success' => true, ...$result]);
    }

    // ---- payloads --------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    /**
     * Every provider a key can be typed in for, and what it has now.
     *
     * A provider is on the list when it reads a key from the environment or an extension gave it
     * an api-key sign-in; Bedrock (AWS credentials), a subscription that only signs in with OAuth
     * and a provider of nothing but virtual models are not. `state` is where its key comes from —
     * `stored` in `auth.json`, `oauth` for a subscription signed in from the terminal, `env` for
     * one of the variables named in `env`, `none` — and the key itself is never sent back.
     *
     * @return list<array{id: string, env: list<string>, state: string}>
     */
    private function keysPayload(): array
    {
        $rows = [];

        foreach (Models::all() as $model) {
            $id = $model->provider;

            if (isset($rows[$id]) || Models::isVirtualOnlyProvider($id)) {
                continue;
            }

            $env = \Pig\Ai\Stream::envApiKeyNames($id);

            if ($env === [] && \Pig\Ai\Extension\ProviderRegistry::apiKeyAuthFor($id) === null) {
                continue;
            }

            $kind = $this->auth->kind($id);
            $rows[$id] = [
                'id' => $id,
                'env' => $env,
                'state' => match (true) {
                    $kind === 'api_key' => 'stored',
                    $kind === 'oauth' => 'oauth',
                    $this->auth->hasKeyFor($id) => 'env',
                    default => 'none',
                },
            ];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Store or remove one provider's key. Answers what was wrong, or null.
     *
     * A subscription signed in with OAuth is left alone either way: storing a key over it would
     * sign it out, which is `/logout`'s to do and not a text field's.
     *
     * @param array<mixed> $data
     */
    private function changeKey(bool $set, array $data): ?string
    {
        $id = is_string($data['provider'] ?? null) ? $data['provider'] : '';

        if (!in_array($id, array_column($this->keysPayload(), 'id'), true)) {
            return "No such provider: {$id}";
        }

        if ($this->auth->kind($id) === 'oauth') {
            return "{$id} is signed in with a subscription; sign out in the terminal with /logout first.";
        }

        if (!$set) {
            if ($this->auth->kind($id) === 'api_key') {
                $this->auth->remove($id);
            }

            return null;
        }

        $key = trim(is_string($data['key'] ?? null) ? $data['key'] : '');

        if ($key === '') {
            return 'The key is empty.';
        }

        $this->auth->setApiKey($id, $key);

        return null;
    }

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

    /**
     * The projects with conversations, the one new ones start in (`$currentCwd`) first, then by how
     * many; and the folders `$given` a Docker account, with none yet. Those two are looked for
     * on this machine unless `$here` is false (an SSH account's).
     *
     * @param list<string> $given
     * @return list<array{path: string, name: string, sessionCount: int, isCurrent: bool}>
     */
    private function listAllWorkspaces(string $currentCwd, array $given = [], bool $here = true): array
    {
        $workspaces = [];

        foreach (SessionManager::sessionStore()->projects() as ['cwd' => $cwd, 'sessions' => $count]) {
            if (!isset($workspaces[$cwd])) {
                $workspaces[$cwd] = [
                    'path' => $cwd,
                    'name' => basename($cwd) ?: $cwd,
                    'sessionCount' => $count,
                    'isCurrent' => $cwd === $currentCwd,
                ];
            } else {
                $workspaces[$cwd]['sessionCount'] += $count;
            }
        }

        if ($currentCwd !== '' && !isset($workspaces[$currentCwd]) && (!$here || is_dir($currentCwd))) {
            $workspaces[$currentCwd] = [
                'path' => $currentCwd,
                'name' => basename($currentCwd) ?: $currentCwd,
                'sessionCount' => 0,
                'isCurrent' => true,
            ];
        }

        foreach ($given as $folder) {
            if (!isset($workspaces[$folder]) && (!$here || is_dir($folder))) {
                $workspaces[$folder] = ['path' => $folder, 'name' => basename($folder) ?: $folder, 'sessionCount' => 0, 'isCurrent' => false];
            }
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
     * The accounts, for the page that manages them — an admin's only (`Middlewares\AdminMiddleware`
     * has seen to that): the list (`GET /api/accounts`), and adding, removing, changing, setting a
     * password, giving a Docker account folders of this machine and saying where an SSH account
     * connects (`POST /api/accounts/{add,remove,update,password,folders,ssh}`). Each change answers
     * the list as it is now. `POST /api/accounts/check` starts an account's container or makes its
     * connection, and answers whether it could. Only with accounts: with the one password there is
     * nothing to manage here.
     *
     * Removing an account or setting its password ends its login sessions, and with them the pages
     * it has open, as logging out does. What its conversations hold stays, theirs.
     *
     * @param array{method: string, path: string, headers: array<string, string>, body: string} $req
     */
    private function routeAccounts(Connection $conn, array $req): bool
    {
        $path = $req['path'];

        if ($path !== '/api/accounts' && !str_starts_with($path, '/api/accounts/')) {
            return false;
        }

        $json = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        $answer = function (int $status, array $body) use ($conn, $json): bool {
            $conn->sendResponse($status, $json, (string) json_encode($body));

            return true;
        };

        if (!$this->login->several()) {
            return $answer(404, ['ok' => false, 'error' => 'There are accounts only with sessions kept in a database.']);
        }

        $accounts = $this->login->accounts();
        $list = static fn (): array => array_map(static fn (User $u): array => $u->toArray(), $accounts->users());

        if ($path === '/api/accounts') {
            return $answer(200, ['ok' => true, 'accounts' => $list()]);
        }

        if ($req['method'] !== 'POST') {
            return $answer(405, ['ok' => false, 'error' => 'POST only.']);
        }

        $data = json_decode($req['body'], true);
        $data = is_array($data) ? $data : [];
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $account = array_values(array_filter($accounts->users(), static fn (User $u): bool => $u->name === $name))[0] ?? null;
        $id = $account?->id;

        // Whether the account's tools can be reached where they run: on the loop, as a connection
        // that does not answer takes its `ConnectTimeout`.
        if ($path === '/api/accounts/check') {
            $runtime = $account !== null ? $this->sandboxOf($account) : null;
            if ($account === null || $runtime === null) {
                return $answer($account === null ? 404 : 200, $account === null
                    ? ['ok' => false, 'error' => "There is no account named {$name}."]
                    : ['ok' => true, 'reachable' => true, 'where' => 'host']);
            }

            Async::spawn(static function () use ($answer, $runtime, $account): void {
                try {
                    $runtime->ensure($account);
                    $answer(200, ['ok' => true, 'reachable' => true, 'where' => $account->runtime]);
                } catch (\Throwable $e) {
                    $answer(200, ['ok' => true, 'reachable' => false, 'where' => $account->runtime, 'error' => $e->getMessage()]);
                }
            });

            return true;
        }

        try {
            $done = match ($path) {
                '/api/accounts/add' => (function () use ($accounts, $name, $password, $data): bool {
                    if (!WebLogin::validName($name)) {
                        throw new \InvalidArgumentException(WebLogin::REASONS['bad_name']);
                    }
                    if (($weak = WebLogin::weak($password)) !== null) {
                        throw new \InvalidArgumentException($weak);
                    }

                    $accounts->add($name, $password, ($data['admin'] ?? false) === true, is_string($data['runtime'] ?? null) ? $data['runtime'] : User::DOCKER);

                    return true;
                })(),
                '/api/accounts/remove' => $accounts->remove($name),
                '/api/accounts/update' => $accounts->update(
                    $name,
                    is_bool($data['admin'] ?? null) ? $data['admin'] : null,
                    is_string($data['runtime'] ?? null) ? $data['runtime'] : null,
                ),
                // Each checked (`DockerSandbox::checkFolder()`) and kept as its real path. The
                // container is made again with them the next time it is needed.
                '/api/accounts/folders' => (function () use ($accounts, $account, $name, $data): bool {
                    if ($account === null) {
                        return false;
                    }
                    if ($account->runtime !== User::DOCKER) {
                        throw new \InvalidArgumentException("{$name} works on this machine, with every folder its OS user may open; folders are given to Docker accounts.");
                    }

                    $sandbox = $this->sandbox ??= \Pig\CodingAgent\Settings::sandbox();
                    $folders = [];
                    foreach (is_array($data['folders'] ?? null) ? $data['folders'] : [] as $folder) {
                        $path = is_array($folder) && is_string($folder['path'] ?? null) ? $folder['path'] : '';
                        $folders[] = ['path' => $sandbox->checkFolder($path), 'readonly' => ($folder['readonly'] ?? false) === true];
                    }

                    return $accounts->setFolders($name, $folders);
                })(),
                // Checked (`SshSandbox::checkConfig()`); whether it connects is `check`'s to say.
                '/api/accounts/ssh' => (function () use ($accounts, $account, $data): bool {
                    if ($account === null) {
                        return false;
                    }

                    $ssh = User::ssh(is_array($data['ssh'] ?? null) ? $data['ssh'] : null);
                    if ($ssh !== null) {
                        ($this->ssh ??= \Pig\CodingAgent\Settings::sshSandbox())->checkConfig($ssh);
                    }

                    return $accounts->setSsh($account->name, $ssh);
                })(),
                '/api/accounts/password' => (function () use ($name, $password): bool {
                    $this->login->setPassword($password, $name);

                    return true;
                })(),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $answer(400, ['ok' => false, 'error' => $e->getMessage()]);
        }

        if ($done === null) {
            return $answer(404, ['ok' => false, 'error' => "Not Found: {$path}"]);
        }
        if ($done === false) {
            return $answer(404, ['ok' => false, 'error' => "There is no account named {$name}."]);
        }

        if ($id !== null && in_array($path, ['/api/accounts/remove', '/api/accounts/password'], true)) {
            $this->closeSocketsOf($id);
        }

        // A removed account's tasks stop with it: kept, switched off, and nobody's to see.
        if ($id !== null && $path === '/api/accounts/remove') {
            $this->schedules->disableAllOf($id);
        }

        return $answer(200, ['ok' => true, 'accounts' => $list()]);
    }

    /** `session_bound` for the tab, then what the child put on screen and asked before it was there. */
    private function sessionBound(Connection $conn, ManagedSession $managed, string $tabId): void
    {
        $conn->send(array_filter([
            'type' => 'session_bound',
            'tabId' => $tabId !== 'default' ? $tabId : null,
            'cwd' => $managed->cwd,
            'sessionFile' => $managed->sessionFile,
            'shared' => count($managed->clients) > 1,
            'incognito' => $managed->incognito ? true : null,
        ], static fn (mixed $v): bool => $v !== null));

        // What the extensions put on screen before this tab was here — widgets, statuses, the
        // title — is drawn again, and a question asked before it — a scheduled run nobody was
        // watching, or a reload — is asked again, or the turn stays parked on something nobody
        // can see. The page takes the same id arriving twice as one question.
        foreach ([...$managed->toldUi, ...$managed->pendingUi] as $request) {
            $line = ['type' => 'rpc_event', 'event' => $request];
            $conn->send($tabId === 'default' ? $line : [...$line, 'tabId' => $tabId]);
        }
    }

    /** Close the pages `$userId` has open: their login sessions have just ended. */
    private function closeSocketsOf(string $userId): void
    {
        foreach ($this->wsUsers as $connectionId => $user) {
            if ($user->id === $userId && isset($this->wsClients[$connectionId]) && !$this->wsClients[$connectionId]->isClosed()) {
                $this->wsClients[$connectionId]->close();
            }
        }
    }

    /**
     * Where `$user`'s tools run, when that is not here (with accounts): a container of theirs
     * (`User::DOCKER`) or an OS user over SSH (`User::SSH`). Null for this machine.
     */
    private function sandboxOf(User $user): ?Runtime
    {
        if (!$this->login->several()) {
            return null;
        }

        return match ($user->runtime) {
            User::DOCKER => $this->sandbox ??= \Pig\CodingAgent\Settings::sandbox(),
            User::SSH => $this->ssh ??= \Pig\CodingAgent\Settings::sshSandbox(),
            default => null,
        };
    }

    /** Whether the account `$owner`'s folders are another machine's (`ScheduleManager::$elsewhere`). */
    private function isElsewhere(string $owner): bool
    {
        if (!$this->login->enabled() || !$this->login->several()) {
            return false;
        }

        foreach ($this->login->accounts()->users() as $account) {
            if ($account->id === $owner) {
                return $account->runtime === User::SSH;
            }
        }

        return false;
    }

    /**
     * Who made the request: the account its cookie is logged in as. A request without one got past
     * the login only as pig on this machine (the local token) or with the login off, and is the
     * admin's.
     *
     * @param array{headers: array<string, string>} $req
     */
    private function userOf(array $req): User
    {
        $user = $this->login->user($req['headers']);

        if ($user !== null) {
            return $user;
        }

        // pig on this machine, from a conversation started for an account: that account, said in a
        // header that is believed only beside the local token, which nobody else can read.
        $owner = $req['headers'][strtolower(Schedule\ScheduleClient::OWNER_HEADER)] ?? '';
        if ($owner !== '' && $this->login->enabled() && $this->login->several() && $this->login->hasLocalToken($req['headers'])) {
            foreach ($this->login->accounts()->users() as $account) {
                if ($account->id === $owner) {
                    return $account;
                }
            }

            // An account that is gone speaks for nobody.
            return new User($owner, '', false);
        }

        return new User('', '', true);
    }

    /**
     * What the child of a scheduled run of `$owner`'s task is started with: a Docker account's
     * container, started if need be (`ScheduleManager::$environmentFor`).
     *
     * @return array<string, string>
     * @throws \RuntimeException when the account is gone, which stops the run with that reason
     */
    private function environmentOf(?string $owner): array
    {
        if ($owner === null || !$this->login->enabled() || !$this->login->several()) {
            return [];
        }

        foreach ($this->login->accounts()->users() as $account) {
            if ($account->id === $owner) {
                $runtime = $this->sandboxOf($account);
                if ($runtime === null) {
                    return [];
                }

                $shell = $runtime->ensure($account);

                return [...$shell->environment(), 'PIG_SANDBOX_HERE' => $runtime->here($account, $runtime->workspace($account))];
            }
        }

        throw new \RuntimeException('The account this task belongs to has been removed.');
    }

    /**
     * Run `$work` seeing the sessions `$user` may list, open, delete or rename: with accounts, only
     * their own (`SqlSessionStore::visibleTo()`) — an admin's too, with those from before accounts
     * that nobody owns. Nobody sees anybody else's conversations, an admin included: what people
     * say to the agent is theirs (the developer's call). `$work` must not wait
     * (`withSessionStore()`).
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function asUser(User $user, Closure $work): mixed
    {
        $store = SessionManager::sessionStore();

        return $store instanceof SqlSessionStore
            ? SessionManager::withSessionStore($store->visibleTo($user->id, unowned: $user->admin), $work)
            : $work();
    }

    /**
     * Whether `$user` may open the conversation `$sessionFile` in `$cwd`: always without accounts,
     * and any new one; otherwise only one `asUser()` sees.
     */
    private function mayOpen(User $user, string $cwd, ?string $sessionFile): bool
    {
        if ($sessionFile === null || $sessionFile === '' || !SessionManager::sessionStore() instanceof SqlSessionStore) {
            return true;
        }

        return $this->asUser($user, static function () use ($cwd, $sessionFile): bool {
            $store = SessionManager::sessionStore();

            return $store->canonical($sessionFile) !== null || SessionManager::find($cwd, $sessionFile) !== null;
        });
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

        // Only a session of the store, as the store names it — for pi's files a `.jsonl` under a
        // sessions directory, links resolved — whether the page sent its location or its id.
        $store = SessionManager::sessionStore();
        $safe = $store->canonical($rawPath);

        if ($safe === null && $cwd !== null && !$store->exists($rawPath)) {
            $found = SessionManager::find($cwd, $rawPath);
            $safe = $found !== null ? $store->canonical($found) : null;
        }

        return $safe;
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
