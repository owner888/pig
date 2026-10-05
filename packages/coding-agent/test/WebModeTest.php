<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\WebMode;

/**
 * The web shell against the fake rpc agent: the page and the lists over HTTP, and the bind /
 * relay protocol over a WebSocket, with one child per conversation behind it.
 *
 * Every test here used to build an `AgentSession` and hand it to the server; none of them
 * could, now, because the server holds none. What they test instead is what pi-web's
 * `server.js` does — and the old per-session HTTP endpoints (`/api/state`, `/api/model`,
 * `/api/session/switch`) are gone with the reason on `HttpServer`'s docblock.
 */
final class WebModeTest extends TestCase
{
    use \Pig\Test\WithoutProviderKeys;

    private const string FAKE = __DIR__ . '/fixtures/fake-rpc-agent.php';

    private string $cwd;
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-web-test-' . bin2hex(random_bytes(6));
        $this->home = $this->cwd . '/home';
        mkdir($this->home, 0700, true);
        $this->forgetProviderKeys();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreProviderKeys();
        if (is_dir($this->cwd)) {
            $this->rmrf($this->cwd);
        }
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }

    /** A server whose children are the fake agent, and whose idle reaping is quick. */
    private function server(int $port, ?Auth $auth = null): HttpServer
    {
        return new HttpServer(
            $this->cwd,
            $port,
            auth: $auth ?? Auth::inMemory(),
            spawn: static fn (string $cwd, ?string $file): RpcClient => new RpcClient(
                cwd: $cwd,
                binary: self::FAKE,
                arguments: $file !== null ? ['--session', $file] : [],
                timeout: 5.0,
            ),
            idleTtl: 0.2,
        );
    }

    // ---- a WebSocket client small enough to live in the test --------------------------------

    /** @return resource */
    private function wsConnect(int $port)
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $resp = '';
        for ($i = 0; $i < 50 && !str_contains($resp, "\r\n\r\n"); $i++) {
            Async::delay(0.01);
            $resp .= (string) fread($client, 4096);
        }
        $this->assertStringContainsString('101 Switching Protocols', $resp);

        return $client;
    }

    /** @param resource $client */
    private function wsSend($client, array $message): void
    {
        $payload = json_encode($message);
        $mask = random_bytes(4);
        $masked = '';
        for ($j = 0; $j < strlen($payload); $j++) {
            $masked .= $payload[$j] ^ $mask[$j % 4];
        }
        $len = strlen($payload);
        $head = $len < 126 ? chr(0x80 | $len) : (chr(0x80 | 126) . pack('n', $len));
        fwrite($client, "\x81" . $head . $mask . $masked);
    }

    /**
     * Read server frames until `$until` is satisfied or `$timeout` passes; returns every
     * decoded message seen, in order.
     *
     * @param resource $client
     * @param callable(array): bool $until
     * @return list<array<string, mixed>>
     */
    private function wsCollect($client, callable $until, float $timeout = 3.0): array
    {
        $buffer = '';
        $seen = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            Async::delay(0.01);
            $buffer .= (string) fread($client, 65536);

            while (strlen($buffer) >= 2) {
                $len = ord($buffer[1]) & 0x7F;
                $offset = 2;
                if ($len === 126) {
                    if (strlen($buffer) < 4) {
                        break;
                    }
                    $len = unpack('n', substr($buffer, 2, 2))[1];
                    $offset = 4;
                } elseif ($len === 127) {
                    if (strlen($buffer) < 10) {
                        break;
                    }
                    $len = unpack('J', substr($buffer, 2, 8))[1];
                    $offset = 10;
                }
                if (strlen($buffer) < $offset + $len) {
                    break;
                }
                $opcode = ord($buffer[0]) & 0x0F;
                $payload = substr($buffer, $offset, $len);
                $buffer = substr($buffer, $offset + $len);

                if ($opcode === 0x9) {
                    // A ping: answer it, as a browser would.
                    fwrite($client, "\x8A" . chr(0x80 | strlen($payload)) . "\0\0\0\0" . $payload);
                    continue;
                }
                if ($opcode !== 0x1) {
                    continue;
                }
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $seen[] = $decoded;
                    if ($until($decoded)) {
                        return $seen;
                    }
                }
            }
        }

        return $seen;
    }

    // ---- HTTP: the page and what needs no session ------------------------------------------

    public function testServesThePageTheFaviconTheModelsAndTheListsWithoutASession(): void
    {
        $port = 28089;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $http = new HttpClient(2.0);

            $index = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/"));
            $this->assertSame(200, $index->status);
            $this->assertStringContainsString('<!DOCTYPE html>', $index->body->all());

            $icon = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/favicon.ico"));
            $this->assertSame('image/svg+xml; charset=utf-8', $icon->header('content-type'));

            $models = json_decode($http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/models"))->body->all(), true);
            $this->assertIsArray($models);

            $folders = json_decode($http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/folders"))->body->all(), true);
            $this->assertSame($this->cwd, $folders['currentCwd']);

            $running = json_decode($http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/running"))->body->all(), true);
            $this->assertSame([], $running['sessions'], 'no child until a browser asks for one');

            // The per-session endpoints are gone, deliberately.
            $this->assertSame(404, $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/state"))->status);
            $this->assertSame(404, $http->follow(new Request('POST', "http://127.0.0.1:{$port}/api/model", body: '{}'))->status);

            $server->stop();
        });
    }

    // ---- WebSocket: bind, relay, fan-out -----------------------------------------------------

    public function testACommandBeforeBindingIsRefusedByName(): void
    {
        $port = 28090;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);

            $this->wsSend($ws, ['type' => 'rpc_command', 'command' => ['type' => 'get_state', 'id' => 'q1']]);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'error');

            $this->assertSame('no active session', $seen[0]['message'] ?? null);
            $this->assertSame('q1', $seen[0]['id'] ?? null, 'the id travels back so the page can fail that one request');

            fclose($ws);
            $server->stop();
        });
    }

    public function testStartSessionSpawnsAChildAndRpcCommandIsRelayedWithItsIdIntact(): void
    {
        $port = 28091;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);

            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'one.jsonl']);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertSame('one.jsonl', end($seen)['sessionFile']);
            $this->assertFalse(end($seen)['shared']);
            $this->assertCount(1, $server->pool()->all(), 'one child');

            $this->wsSend($ws, ['type' => 'rpc_command', 'command' => ['type' => 'get_state', 'id' => 'q7']]);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'rpc_event' && ($m['event']['id'] ?? null) === 'q7');
            $response = end($seen)['event'];

            $this->assertSame('response', $response['type']);
            $this->assertSame('get_state', $response['command']);
            $this->assertTrue($response['success']);
            $this->assertStringEndsWith('one.jsonl', $response['data']['sessionFile'], 'the child was started on the file the page named');

            fclose($ws);
            $server->stop();
        });
    }

    public function testATurnInOneTabIsNotDrawnInAnother(): void
    {
        $port = 28092;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $a = $this->wsConnect($port);
            $b = $this->wsConnect($port);

            $this->wsSend($a, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'a.jsonl']);
            $this->wsSend($b, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'b.jsonl']);
            $this->wsCollect($a, static fn (array $m) => $m['type'] === 'session_bound');
            $this->wsCollect($b, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertCount(2, $server->pool()->all(), 'two conversations, two children');

            $this->wsSend($a, ['type' => 'rpc_command', 'command' => ['type' => 'prompt', 'message' => 'only a', 'id' => 'p1']]);
            $onA = $this->wsCollect($a, static fn (array $m) => ($m['event']['type'] ?? null) === 'agent_end');
            $typesA = array_map(static fn ($m) => $m['event']['type'] ?? $m['type'], $onA);
            $this->assertContains('agent_start', $typesA);
            // The fixture speaks the real wire: a streamed answer is `message_update` with the
            // delta inside, not a bare `text_delta` — which is what the page is written to.
            $this->assertContains('message_update', $typesA);

            $onB = $this->wsCollect($b, static fn () => false, timeout: 0.3);
            $this->assertSame([], $onB, 'tab B heard nothing of tab A\'s turn');

            fclose($a);
            fclose($b);
            $server->stop();
        });
    }

    public function testTwoTabsOnOneFileShareTheChildAndBothHearIt(): void
    {
        $port = 28093;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $a = $this->wsConnect($port);
            $b = $this->wsConnect($port);

            $this->wsSend($a, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'shared.jsonl']);
            $this->wsCollect($a, static fn (array $m) => $m['type'] === 'session_bound');
            $this->wsSend($b, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'shared.jsonl']);
            $bound = $this->wsCollect($b, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertTrue(end($bound)['shared'], 'the second tab is told it joined');
            $this->assertCount(1, $server->pool()->all());

            $this->wsSend($a, ['type' => 'rpc_command', 'command' => ['type' => 'prompt', 'message' => 'both', 'id' => 'p1']]);
            $onB = $this->wsCollect($b, static fn (array $m) => ($m['event']['type'] ?? null) === 'agent_end');
            $this->assertContains('agent_start', array_map(static fn ($m) => $m['event']['type'] ?? '', $onB), 'tab B saw tab A\'s turn on the shared conversation');

            fclose($a);
            fclose($b);
            $server->stop();
        });
    }

    public function testAChildThatDiesTellsItsTabAndTheNextBindRestartsIt(): void
    {
        $port = 28094;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);

            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'dies.jsonl']);
            $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_bound');

            $this->wsSend($ws, ['type' => 'rpc_command', 'command' => ['type' => 'die', 'id' => 'x']]);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_ended');
            $this->assertSame('session_ended', end($seen)['type'], 'the tab is told, not left spinning');
            $this->assertSame([], $server->pool()->all(), 'and the child is unregistered');

            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'dies.jsonl']);
            $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertCount(1, $server->pool()->all(), 'a fresh child, on the browser\'s say-so');

            fclose($ws);
            $server->stop();
        });
    }

    public function testClosingTheSocketDetachesAndTheChildIsReapedAfterTheTtl(): void
    {
        $port = 28095;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);
            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'r.jsonl']);
            $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_bound');
            $child = $server->pool()->all()[0];

            fclose($ws);
            Async::delay(0.1);
            $this->assertTrue($child->client->isRunning(), 'not reaped at once: a reload is a disconnect and a reconnect');

            Async::delay(0.4);
            $this->assertFalse($child->client->isRunning(), 'reaped after the TTL with nobody looking');

            $server->stop();
        });
    }

    public function testAChildThatCannotStartIsAnErrorForThatTabAndNotAServerCrash(): void
    {
        $port = 28096;
        $server = new HttpServer(
            $this->cwd,
            $port,
            auth: Auth::inMemory(),
            spawn: static fn (string $cwd, ?string $file): RpcClient => new RpcClient(cwd: $cwd, binary: '/nowhere/pig'),
        );

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);

            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd]);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'error');
            $this->assertStringContainsString('Could not start the session', end($seen)['message']);
            $this->assertStringContainsString('/nowhere/pig', end($seen)['message']);

            // The server is still answering.
            $this->wsSend($ws, ['type' => 'ping']);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'pong');
            $this->assertSame('pong', end($seen)['type']);

            fclose($ws);
            $server->stop();
        });
    }

    public function testStopWindsEveryChildDownAndLeavesTheLoopClean(): void
    {
        $port = 28097;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $a = $this->wsConnect($port);
            $b = $this->wsConnect($port);
            $this->wsSend($a, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'a.jsonl']);
            $this->wsSend($b, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'b.jsonl']);
            $this->wsCollect($a, static fn (array $m) => $m['type'] === 'session_bound');
            $this->wsCollect($b, static fn (array $m) => $m['type'] === 'session_bound');
            $children = $server->pool()->all();
            $this->assertCount(2, $children);

            $server->stop();

            foreach ($children as $child) {
                $this->assertFalse($child->client->isRunning());
            }
            $this->assertFalse($server->isRunning());
        });

        $loop = new \ReflectionClass(Loop::class);
        foreach (['timers', 'readers', 'writers'] as $kind) {
            $this->assertSame([], $loop->getProperty($kind)->getValue(Loop::get()), "no {$kind} left behind");
        }
    }

    public function testWebModeHoldsNoSessionOfItsOwn(): void
    {
        $mode = new WebMode($this->cwd, 28098, auth: Auth::inMemory(), openBrowser: false);
        $this->assertSame($this->cwd, $mode->cwd);
        $this->assertFalse(property_exists($mode, 'session'), 'the conversations are in the children');
    }

    public function testSingleWebSocketMultiplexesMultipleTabsWithTabIdTagging(): void
    {
        $port = 28099;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            // A single physical WebSocket connection for the browser
            $ws = $this->wsConnect($port);

            // Subscribe tab-1
            $this->wsSend($ws, ['type' => 'start_session', 'tabId' => 'tab-1', 'cwd' => $this->cwd, 'sessionFile' => 'tab1.jsonl']);
            $bound1 = $this->wsCollect($ws, static fn (array $m) => ($m['type'] ?? '') === 'session_bound' && ($m['tabId'] ?? '') === 'tab-1');
            $this->assertSame('tab-1', $bound1[0]['tabId']);

            // Subscribe tab-2 over the same connection
            $this->wsSend($ws, ['type' => 'start_session', 'tabId' => 'tab-2', 'cwd' => $this->cwd, 'sessionFile' => 'tab2.jsonl']);
            $bound2 = $this->wsCollect($ws, static fn (array $m) => ($m['type'] ?? '') === 'session_bound' && ($m['tabId'] ?? '') === 'tab-2');
            $this->assertSame('tab-2', $bound2[0]['tabId']);

            $this->assertCount(2, $server->pool()->all(), 'two tabs on one socket are two independent rpc children');

            // Send prompt to tab-1
            $this->wsSend($ws, ['type' => 'rpc_command', 'tabId' => 'tab-1', 'command' => ['type' => 'prompt', 'message' => 'hi tab1', 'id' => 'p1']]);
            $events = $this->wsCollect($ws, static fn (array $m) => ($m['tabId'] ?? '') === 'tab-1' && ($m['event']['type'] ?? '') === 'agent_end');

            $types = array_map(static fn (array $m) => $m['event']['type'], array_filter($events, static fn ($m) => isset($m['event']['type'])));
            $this->assertContains('agent_start', $types);
            $this->assertContains('agent_end', $types);

            foreach ($events as $msg) {
                $this->assertSame('tab-1', $msg['tabId'], 'every event over the shared socket carried tab-1');
            }

            fclose($ws);
            $server->stop();
        });
    }

    public function testAccountsUsageEndpointDoesNotCrashOnMissingAsyncImport(): void
    {
        $port = 28100;
        $auth = Auth::inMemory();
        $server = $this->server($port, $auth);

        Async::run(function () use ($server, $port, $auth) {
            $server->start();
            $http = new HttpClient();

            // When no antigravity credential is saved, returns 404 cleanly without throwing
            $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/accounts/usage"));
            $this->assertSame(404, $res->status);
            $body = json_decode($res->body->all(), true);
            $this->assertFalse($body['ok']);
            $this->assertStringContainsString('No Antigravity account', $body['error']);

            // With credentials present, hits the `Async::spawn` branch. Must not crash with
            // `Class "Pig\CodingAgent\Web\Async" not found`.
            $auth->setCredentials(
                \Pig\Ai\Utils\Oauth\Provider::Antigravity,
                new \Pig\Ai\Utils\Oauth\Credentials(refresh: 'r', access: 'a', expires: (time() + 3600) * 1000, projectId: 'proj-1'),
            );
            // QuotaClient safely runs through Async::spawn, returning 200 with usage and error diagnostics
            $res2 = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/accounts/usage"));
            $this->assertSame(200, $res2->status);
            $body2 = json_decode($res2->body->all(), true);
            $this->assertTrue($body2['ok']);

            $server->stop();
        });
    }

    public function testRenameAndDeleteSessionEndpoints(): void
    {
        $port = 28101;
        $server = $this->server($port);
        $session = \Pig\CodingAgent\Session\SessionManager::create($this->cwd);
        $session->append(new \Pig\Ai\UserMessage([new \Pig\Ai\TextContent('hello')]));
        $session->append(new \Pig\Ai\AssistantMessage(
            [new \Pig\Ai\TextContent('world')],
            \Pig\Ai\Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new \Pig\Ai\Usage(),
            \Pig\Ai\StopReason::Stop,
        ));

        $this->assertFileExists($session->path);

        Async::run(function () use ($server, $port, $session) {
            $server->start();
            $http = new HttpClient();

            // 1. Rename session
            $renameRes = $http->send(new Request(
                'POST',
                "http://127.0.0.1:{$port}/api/sessions/rename",
                ['Content-Type' => 'application/json'],
                json_encode(['path' => $session->path, 'name' => 'Renamed Test Session']),
            ));
            $this->assertSame(200, $renameRes->status);
            $renameBody = json_decode($renameRes->body->all(), true);
            $this->assertTrue($renameBody['success']);
            $this->assertSame('Renamed Test Session', $renameBody['name']);

            // Verify rename persisted on disk
            $opened = \Pig\CodingAgent\Session\SessionManager::open($session->path);
            $this->assertSame('Renamed Test Session', $opened->getSessionName());

            // 2. Reject unsafe deletion path outside session roots
            $badRes = $http->send(new Request(
                'POST',
                "http://127.0.0.1:{$port}/api/sessions/delete",
                ['Content-Type' => 'application/json'],
                json_encode(['path' => '/etc/passwd']),
            ));
            $this->assertSame(400, $badRes->status);

            // 3. Delete session successfully
            $delRes = $http->send(new Request(
                'POST',
                "http://127.0.0.1:{$port}/api/sessions/delete",
                ['Content-Type' => 'application/json'],
                json_encode(['path' => $session->path]),
            ));
            $this->assertSame(200, $delRes->status);
            $delBody = json_decode($delRes->body->all(), true);
            $this->assertTrue($delBody['success']);
            $this->assertFileDoesNotExist($session->path);

            $server->stop();
        });
    }

    public function testFaultIsolationBoundaryPreventsServerCrashOnErrors(): void
    {
        $port = 28102;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $http = new HttpClient();

            // 1. Send an invalid/malformed request body to an endpoint that expects valid JSON
            $res = $http->send(new Request(
                'POST',
                "http://127.0.0.1:{$port}/api/sessions/delete",
                ['Content-Type' => 'application/json'],
                '{"malformed_json: missing_value',
            ));
            // Should return 400 Bad Request or 500 without crashing the server
            $this->assertTrue(in_array($res->status, [400, 500], true));

            // 2. Verify server is still alive and responds normally to subsequent requests
            $res2 = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/models"));
            $this->assertSame(200, $res2->status);
            $this->assertTrue($server->isRunning());

            $server->stop();
        });
    }

    /**
     * Every JS module the page loads must parse. A stray brace from a botched edit shipped once
     * and left the whole UI stuck on "Loading session…" — nothing in the PHP suite could see it,
     * because the server happily serves a file whether or not a browser can execute it.
     */
    public function testEveryServedJsAssetParses(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to parse the assets');
        }

        $root = realpath(__DIR__ . '/../src/Web/assets/js');
        $this->assertNotFalse($root);

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($files);

        foreach ($files as $path) {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, basename($path) . " does not parse:\n" . implode("\n", $out));
        }
    }
}
