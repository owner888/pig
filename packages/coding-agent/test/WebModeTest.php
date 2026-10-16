<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\Schedule\ScheduleClient;
use Pig\CodingAgent\Web\Schedule\ScheduleManager;
use Pig\CodingAgent\Web\Schedule\ScheduleTool;
use Pig\CodingAgent\Web\WebLogin;
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

    /** @var list<string|null> the theme each child was started in, in order */
    private array $spawnedThemes = [];
    private string $home;

    private string|false $previousHome = false;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-web-test-' . bin2hex(random_bytes(6));
        $this->home = $this->cwd . '/home';
        mkdir($this->home, 0700, true);
        $this->forgetProviderKeys();
        // The shell loads the extensions on `start()`, from `~/.pig/agent/extensions` among other
        // places — and the machine this runs on has real ones there. An empty home instead.
        $this->previousHome = getenv('PIG_CODING_AGENT_DIR');
        putenv('PIG_CODING_AGENT_DIR=' . $this->home);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->previousHome === false ? 'PIG_CODING_AGENT_DIR' : 'PIG_CODING_AGENT_DIR=' . $this->previousHome);
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

    /**
     * A server whose children are the fake agent, and whose idle reaping is quick — with no login
     * unless one is given: the login has tests of its own below.
     */
    private function server(int $port, ?Auth $auth = null, ?WebLogin $login = null): HttpServer
    {
        return new HttpServer(
            $this->cwd,
            $port,
            auth: $auth ?? Auth::inMemory(),
            spawn: function (string $cwd, ?string $file, ?string $theme = null): RpcClient {
                $this->spawnedThemes[] = $theme;

                return new RpcClient(
                    cwd: $cwd,
                    binary: self::FAKE,
                    arguments: $file !== null ? ['--session', $file] : [],
                    timeout: 5.0,
                );
            },
            idleTtl: 0.2,
            login: $login ?? WebLogin::off(),
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

    public function testTheKeyPanelStoresAndRemovesAKeyAndTheModelsFollow(): void
    {
        $port = 28143;
        $file = $this->home . '/auth.json';
        $server = $this->server($port, Auth::discover($file));

        Async::run(function () use ($server, $port, $file) {
            $server->start();
            $http = new HttpClient(2.0);
            $get = fn (string $path): array => json_decode($http->follow(new Request('GET', "http://127.0.0.1:{$port}{$path}"))->body->all(), true);
            $post = fn (string $path, array $body) => $http->follow(new Request('POST', "http://127.0.0.1:{$port}{$path}", ['content-type' => 'application/json'], (string) json_encode($body)));
            $row = static fn (array $providers, string $id): ?array => array_values(array_filter($providers, static fn (array $p): bool => $p['id'] === $id))[0] ?? null;

            // Nothing anywhere: no model, which is what makes the page open the panel by itself.
            $this->assertSame([], $get('/api/models'));
            $providers = $get('/api/keys')['providers'];
            $this->assertSame(['id' => 'openai', 'env' => ['OPENAI_API_KEY'], 'state' => 'none'], $row($providers, 'openai'));
            $this->assertNull($row($providers, 'amazon-bedrock'), 'AWS credentials are not a key to type in');

            $stored = $post('/api/keys/set', ['provider' => 'openai', 'key' => '  sk-test-1  ']);
            $this->assertSame(200, $stored->status);
            $answer = $stored->body->all();
            $this->assertStringNotContainsString('sk-test-1', $answer, 'the key is never sent back');
            $this->assertSame('stored', $row(json_decode($answer, true)['providers'], 'openai')['state']);
            $this->assertSame(['type' => 'api_key', 'key' => 'sk-test-1'], json_decode((string) file_get_contents($file), true)['openai']);
            $this->assertContains('openai', array_column($get('/api/models'), 'provider'));

            $this->assertSame(400, $post('/api/keys/set', ['provider' => 'openai', 'key' => ' '])->status);
            $this->assertSame(400, $post('/api/keys/set', ['provider' => 'nobody', 'key' => 'k'])->status);
            $this->assertSame(405, $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/keys/set"))->status);

            $this->assertSame(200, $post('/api/keys/remove', ['provider' => 'openai'])->status);
            $this->assertSame([], $get('/api/models'));

            // A subscription signed in from the terminal — written by another process, so read
            // again — is neither overwritten nor removed from here.
            file_put_contents($file, (string) json_encode(['anthropic' => ['type' => 'oauth', 'refresh' => 'r', 'access' => 'a', 'expires' => 0]]));
            $this->assertSame('oauth', $row($get('/api/keys')['providers'], 'anthropic')['state']);
            $this->assertSame(400, $post('/api/keys/set', ['provider' => 'anthropic', 'key' => 'k'])->status);
            $this->assertSame(400, $post('/api/keys/remove', ['provider' => 'anthropic'])->status);
            $this->assertSame('oauth', json_decode((string) file_get_contents($file), true)['anthropic']['type']);

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

    public function testAPageThatReconnectsFindsItsUnsavedConversationAgain(): void
    {
        $port = 28197;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $pageId = str_repeat('ab', 16);
            $first = $this->wsConnect($port);
            $this->wsSend($first, ['type' => 'start_session', 'cwd' => $this->cwd, 'tabId' => 'tab-1', 'pageId' => $pageId]);
            $this->wsCollect($first, static fn (array $m) => $m['type'] === 'session_bound');
            fclose($first);

            // The same page on a new connection: the conversation nothing has saved yet is the one
            // it had, not a new one — so a prompt sent again under its `requestId` lands there.
            $again = $this->wsConnect($port);
            $this->wsSend($again, ['type' => 'start_session', 'cwd' => $this->cwd, 'tabId' => 'tab-1', 'pageId' => $pageId]);
            $this->wsCollect($again, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertCount(1, $server->pool()->all());

            // Another page is another conversation.
            $other = $this->wsConnect($port);
            $this->wsSend($other, ['type' => 'start_session', 'cwd' => $this->cwd, 'tabId' => 'tab-1', 'pageId' => str_repeat('cd', 16)]);
            $this->wsCollect($other, static fn (array $m) => $m['type'] === 'session_bound');
            $this->assertCount(2, $server->pool()->all());

            fclose($again);
            fclose($other);
            $server->stop();
        });
    }

    public function testATabThatJoinsLaterIsShownWhatTheExtensionsLeftOnScreen(): void
    {
        $port = 28094;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $a = $this->wsConnect($port);
            $this->wsSend($a, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'shown.jsonl']);
            $this->wsCollect($a, static fn (array $m) => $m['type'] === 'session_bound');
            $this->wsSend($a, ['type' => 'rpc_command', 'command' => ['type' => 'prompt', 'message' => 'show: it', 'id' => 'p1']]);
            $this->wsCollect($a, static fn (array $m) => ($m['event']['type'] ?? null) === 'agent_end');

            // A reload, or a second window: the widget, the statuses and the title as they stand
            // now — the latest of each, a clear included — and not the notification, which was said once.
            $b = $this->wsConnect($port);
            $this->wsSend($b, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'shown.jsonl']);
            $seen = $this->wsCollect($b, static fn (array $m) => ($m['event']['method'] ?? null) === 'setTitle');
            $replayed = array_values(array_filter(array_map(static fn (array $m) => $m['event'] ?? null, $seen), static fn ($e) => ($e['type'] ?? null) === 'extension_ui_request'));

            $this->assertSame(['t1', 't2', 't4', 't5'], array_column($replayed, 'id'));
            $this->assertSame(['latest'], $replayed[0]['widgetLines']);
            $this->assertArrayNotHasKey('statusText', $replayed[2], 'the cleared status is cleared on the new page too');

            fclose($a);
            fclose($b);
            $server->stop();
        });
    }

    public function testAChildIsStartedInThePagesThemeAndAJoiningPageDoesNotRestartIt(): void
    {
        $port = 28104;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $a = $this->wsConnect($port);
            $this->wsSend($a, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'themed.jsonl', 'theme' => 'light']);
            $this->wsCollect($a, static fn (array $m) => $m['type'] === 'session_bound');

            // Another window in another theme joins the same child; it tells it with `set_theme`.
            $b = $this->wsConnect($port);
            $this->wsSend($b, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'themed.jsonl', 'theme' => 'dark']);
            $this->wsCollect($b, static fn (array $m) => $m['type'] === 'session_bound');

            $c = $this->wsConnect($port);
            $this->wsSend($c, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => 'plain.jsonl']);
            $this->wsCollect($c, static fn (array $m) => $m['type'] === 'session_bound');

            $this->assertSame(['light', null], $this->spawnedThemes);

            fclose($a);
            fclose($b);
            fclose($c);
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
            $collected = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'session_bound');
            $all = $server->pool()->all();
            $this->assertNotEmpty($all, 'child session is bound in pool: ' . json_encode($collected));
            $child = $all[0];

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
            login: WebLogin::off(),
        );

        Async::run(function () use ($server, $port) {
            $server->start();
            $ws = $this->wsConnect($port);

            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd]);
            $seen = $this->wsCollect($ws, static fn (array $m) => $m['type'] === 'error');
            $this->assertStringContainsString('Could not start the session', end($seen)['message']);
            $this->assertStringContainsString('/nowhere/pig', end($seen)['message']);

            // The server is still answering.
            $this->wsSend($ws, ['type' => 'are_you_there']);
            $seen = $this->wsCollect($ws, static fn (array $m) => str_contains($m['message'] ?? '', 'are_you_there'));
            $this->assertStringContainsString("unknown message type 'are_you_there'", end($seen)['message']);

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

    public function testAnExtensionsHttpRouteIsAnsweredFromAFiber(): void
    {
        // `ExtensionApi::registerHttpRoute()`: what the Antigravity accounts panel goes through
        // now, instead of endpoints written into `HttpServer`. A route that is not registered is
        // a 404; one that is runs in a fiber (it may make a network call) and its answer is the
        // body; one that throws is a 500 with the message rather than a dead connection.
        $port = 28100;
        $server = $this->server($port);

        $api = new ExtensionApi($this->cwd, 'probe.php', 'probe');
        $api->registerHttpRoute('/api/probe', static function (string $path, array $req): ?array {
            return match ($path) {
                '/api/probe' => ['status' => 200, 'body' => ['ok' => true, 'method' => $req['method']]],
                '/api/probe/slow' => (static function (): array {
                    \Pig\Async\Async::delay(0.02);

                    return ['status' => 200, 'body' => ['ok' => true, 'slow' => true]];
                })(),
                '/api/probe/boom' => throw new \RuntimeException('boom'),
                default => null,
            };
        });

        try {
            Async::run(function () use ($server, $port): void {
                $server->start();
                $http = new HttpClient();

                $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/probe"));
                $this->assertSame(200, $res->status);
                $this->assertSame(['ok' => true, 'method' => 'GET'], json_decode($res->body->all(), true));

                $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/probe/slow"));
                $this->assertSame(200, $res->status);
                $this->assertTrue(json_decode($res->body->all(), true)['slow']);

                $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/probe/boom"));
                $this->assertSame(500, $res->status);
                $this->assertStringContainsString('boom', json_decode($res->body->all(), true)['error']);

                $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/probe/nothing"));
                $this->assertSame(404, $res->status);

                $res = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/accounts/usage"));
                $this->assertSame(404, $res->status, 'with no extension loaded there is no such endpoint');

                $server->stop();
            });
        } finally {
            ExtensionApi::forgetHttpRoutes();
        }
    }

    public function testAnExtensionsPanelIsServedFromAFiberInThePagesLanguage(): void
    {
        // `ExtensionApi::registerWebPanel()` through the server: the list the sidebar draws, the
        // panel's blocks (from a fiber — a lazy section may make a network call), and an action
        // followed by the panel drawn again.
        $port = 28105;
        $server = $this->server($port);
        $done = [];

        (new ExtensionApi($this->cwd, 'probe.php', 'probe'))->registerWebPanel(
            'probe.panel',
            ['en' => 'Probe', 'zh-CN' => '探针'],
            static function (array $request): array {
                if ($request['section'] === 'slow') {
                    \Pig\Async\Async::delay(0.02);
                }

                return [['type' => 'text', 'text' => "{$request['locale']}:" . ($request['section'] ?? 'main')]];
            },
            static function (string $action, array $args) use (&$done): ?string {
                $done[] = [$action, $args];

                return $action === 'go' ? null : 'no';
            },
            commands: ['probe'],
        );

        try {
            Async::run(function () use ($server, $port, &$done): void {
                $server->start();
                $http = new HttpClient();
                $get = fn (string $path): array => json_decode($http->send(new Request('GET', "http://127.0.0.1:{$port}{$path}"))->body->all(), true);

                $this->assertSame([['id' => 'probe.panel', 'title' => '探针', 'label' => '探针', 'icon' => '', 'commands' => ['probe'], 'placement' => 'sidebar']], $get('/api/panels?locale=zh-CN')['panels']);
                $this->assertSame([['type' => 'text', 'text' => 'zh-CN:main']], $get('/api/panels/probe.panel?locale=zh-CN')['blocks']);
                $this->assertSame([['type' => 'text', 'text' => 'en:slow']], $get('/api/panels/probe.panel?locale=en&section=slow')['blocks']);

                $res = $http->send(new Request('POST', "http://127.0.0.1:{$port}/api/panels/probe.panel", ['Content-Type' => 'application/json'], json_encode(['action' => 'go', 'args' => ['n' => 1], 'locale' => 'en'])));
                $this->assertSame(200, $res->status);
                $this->assertSame(['ok' => true, 'blocks' => [['type' => 'text', 'text' => 'en:main']]], json_decode($res->body->all(), true));
                $this->assertSame([['go', ['n' => 1]]], $done);

                $this->assertSame(404, $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/panels/nobody"))->status);

                $server->stop();
            });
        } finally {
            ExtensionApi::forgetWebPanels();
        }
    }

    public function testAPanelsActionAsksThePageThatPressedTheButton(): void
    {
        // An action's `$ui` is `$ctx->ui` for a panel: what it asks goes to the page that pressed
        // the button as `panel_ui`, the answer comes back as `panel_ui_response`, a `notify()` is
        // told on the way, and the outcome is `panel_result` with the panel drawn again. A page that
        // closes while it is being asked is a cancel, and the action still finishes.
        $port = 28106;
        $server = $this->server($port);
        $heard = [];

        (new ExtensionApi($this->cwd, 'probe.php', 'probe'))->registerWebPanel(
            'probe.ask',
            'Ask',
            static fn (array $request): array => [['type' => 'text', 'text' => 'drawn']],
            static function (string $action, array $args, array $request, \Pig\CodingAgent\Hooks\HookUi $ui) use (&$heard): ?string {
                $name = $ui->input('Name?', 'a name');
                $heard[] = $name;
                if ($name === null) {
                    return 'nobody answered';
                }
                $ui->notify("hello {$name}", 'info');

                return $ui->confirm('Sure?', "Call it {$name}?") ? null : 'not sure';
            },
        );

        try {
            Async::run(function () use ($server, $port, &$heard): void {
                $server->start();
                $ws = $this->wsConnect($port);
                $this->wsSend($ws, ['type' => 'panel_action', 'requestId' => 'r1', 'panel' => 'probe.ask', 'action' => 'go', 'args' => [], 'locale' => 'en']);

                $asked = $this->wsCollect($ws, static fn (array $m) => ($m['type'] ?? null) === 'panel_ui');
                $input = end($asked);
                $this->assertSame('r1', $input['requestId']);
                $this->assertSame(['extension_ui_request', 'input', 'Name?', 'a name'], [$input['request']['type'], $input['request']['method'], $input['request']['title'], $input['request']['placeholder']]);

                $this->wsSend($ws, ['type' => 'panel_ui_response', 'requestId' => 'r1', 'reply' => ['id' => $input['request']['id'], 'value' => 'pig']]);
                $next = $this->wsCollect($ws, static fn (array $m) => ($m['request']['method'] ?? null) === 'confirm');
                $methods = array_map(static fn (array $m) => $m['request']['method'] ?? null, array_filter($next, static fn (array $m) => ($m['type'] ?? null) === 'panel_ui'));
                $this->assertSame(['notify', 'confirm'], array_values($methods));
                $this->assertSame('hello pig', array_values(array_filter($next, static fn (array $m) => ($m['request']['method'] ?? null) === 'notify'))[0]['request']['message']);

                $confirm = end($next);
                $this->wsSend($ws, ['type' => 'panel_ui_response', 'requestId' => 'r1', 'reply' => ['id' => $confirm['request']['id'], 'confirmed' => true]]);
                $done = $this->wsCollect($ws, static fn (array $m) => ($m['type'] ?? null) === 'panel_result');
                $this->assertSame(['type' => 'panel_result', 'requestId' => 'r1', 'ok' => true, 'blocks' => [['type' => 'text', 'text' => 'drawn']]], end($done));

                // Asked again, and the page goes away mid-question.
                $this->wsSend($ws, ['type' => 'panel_action', 'requestId' => 'r2', 'panel' => 'probe.ask', 'action' => 'go']);
                $this->wsCollect($ws, static fn (array $m) => ($m['type'] ?? null) === 'panel_ui');
                fclose($ws);
                \Pig\Async\Async::delay(0.2);
                $this->assertSame(['pig', null], $heard, 'the page closed: a cancel, and the action finished');

                $server->stop();
            });
        } finally {
            ExtensionApi::forgetWebPanels();
        }
    }

    /** The status line of a WebSocket handshake sent with `$headers`. */
    private function wsStatus(int $port, array $headers = []): string
    {
        $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
        $this->assertIsResource($client);
        stream_set_blocking($client, false);
        $extra = implode('', array_map(static fn (string $k, string $v): string => "{$k}: {$v}\r\n", array_keys($headers), $headers));
        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n{$extra}\r\n");

        $resp = '';
        for ($i = 0; $i < 50 && !str_contains($resp, "\r\n"); $i++) {
            Async::delay(0.01);
            $resp .= (string) fread($client, 4096);
        }
        fclose($client);

        return strtok($resp, "\r\n") ?: '';
    }

    public function testNothingButTheLoginPageWithoutLoggingIn(): void
    {
        // `WebLogin` behind `LoginMiddleware` and `SameOriginMiddleware`, through the server: the
        // page sends you to /login, the API and the WebSocket say 401, the first password needs the
        // setup code, and the session cookie it sets opens everything — but not to another site.
        $port = 28107;
        $home = $this->cwd . '/login-home';
        $login = WebLogin::at($home);
        $server = $this->server($port, login: $login);
        $code = (string) $login->setupCode();

        Async::run(function () use ($server, $port, $code): void {
            $server->start();
            $http = new HttpClient();
            $url = "http://127.0.0.1:{$port}";
            $post = fn (string $path, array $body, array $headers = []): \Pig\Ai\Http\Response => $http->send(new Request('POST', $url . $path, ['Content-Type' => 'application/json', ...$headers], json_encode($body)));

            $page = $http->send(new Request('GET', "{$url}/"));
            $this->assertSame([302, '/login'], [$page->status, $page->header('location')]);
            $this->assertSame(200, $http->send(new Request('GET', "{$url}/login"))->status);
            $this->assertSame(200, $http->send(new Request('GET', "{$url}/assets/js/login.js"))->status, 'what the login page needs');
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/assets/js/app.js"))->status, 'but not the rest');
            // An extension that rewrites Origin, port gone, on a stylesheet Chrome marks same-origin.
            $this->assertSame(200, $http->send(new Request('GET', "{$url}/theme.css", ['Origin' => 'http://127.0.0.1', 'Sec-Fetch-Site' => 'same-origin']))->status);
            $this->assertSame(403, $http->send(new Request('GET', "{$url}/theme.css", ['Origin' => "http://127.0.0.1:{$port}", 'Sec-Fetch-Site' => 'cross-site']))->status);
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/api/models"))->status);
            $this->assertSame(['loggedIn' => false, 'setup' => true], json_decode($http->send(new Request('GET', "{$url}/api/login/state"))->body->all(), true));
            $this->assertStringContainsString('401', $this->wsStatus($port), 'no conversation, no terminal');

            $this->assertSame(401, $post('/api/login/setup', ['code' => 'WRON-GCOD', 'password' => 'long enough'])->status);
            $set = $post('/api/login/setup', ['code' => $code, 'password' => 'long enough']);
            $this->assertSame(200, $set->status);
            $cookie = (string) $set->header('set-cookie');
            $this->assertStringContainsString('HttpOnly; SameSite=Strict', $cookie);
            $session = ['Cookie' => strtok($cookie, ';')];

            $this->assertSame(200, $http->send(new Request('GET', "{$url}/api/models", $session))->status);
            $this->assertSame(200, $http->send(new Request('GET', "{$url}/", $session))->status);
            $this->assertStringContainsString('101', $this->wsStatus($port, $session));
            $this->assertNull($http->send(new Request('GET', "{$url}/api/models", $session))->header('access-control-allow-origin'), 'no other site may read it');

            // Another site's page, with the cookie the browser would attach: refused at the door.
            $this->assertSame(403, $post('/api/terminal/exec', ['command' => 'id'], [...$session, 'Origin' => 'http://evil.example'])->status);
            $this->assertStringContainsString('403', $this->wsStatus($port, [...$session, 'Origin' => 'http://evil.example']));
            $this->assertStringContainsString('101', $this->wsStatus($port, [...$session, 'Origin' => "http://127.0.0.1:{$port}"]));

            $this->assertSame(401, $post('/api/login', ['password' => 'wrong one'])->status);
            $again = $post('/api/login', ['password' => 'long enough']);
            $this->assertSame(200, $again->status);

            $this->assertSame(200, $post('/api/logout', [], $session)->status);
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/api/models", $session))->status, 'logged out');

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

    // ---- scheduled tasks ---------------------------------------------------------------------

    /** @return array{int, array<string, mixed>} */
    private function post(HttpClient $http, int $port, string $path, array $body): array
    {
        $response = $http->send(new Request('POST', "http://127.0.0.1:{$port}{$path}", ['Content-Type' => 'application/json'], json_encode($body)));

        return [$response->status, json_decode($response->body->all(), true)];
    }

    /** @return array<string, mixed> */
    private function get(HttpClient $http, int $port, string $path): array
    {
        return json_decode($http->follow(new Request('GET', "http://127.0.0.1:{$port}{$path}"))->body->all(), true);
    }

    public function testAScheduledTaskIsSavedRunAndToldToEveryPage(): void
    {
        $port = 28140;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $server->schedules()->start();
            $http = new HttpClient(2.0);
            $ws = $this->wsConnect($port);

            [$status, $refused] = $this->post($http, $port, '/api/scheduled/save', ['name' => 'Brief', 'prompt' => 'hi', 'cwd' => $this->cwd, 'schedule' => 'cron', 'cron' => '0 9 * *', 'timezone' => 'UTC']);
            $this->assertSame(400, $status);
            $this->assertStringContainsString('five fields', $refused['error'], 'the reason, for the form to show');

            [$status, $saved] = $this->post($http, $port, '/api/scheduled/save', ['name' => 'Brief', 'prompt' => 'hi', 'cwd' => $this->cwd, 'schedule' => 'manual', 'timezone' => 'UTC']);
            $this->assertSame(200, $status);
            $id = $saved['task']['id'];
            $this->assertNotEmpty($this->wsCollect($ws, static fn (array $m) => $m['type'] === 'scheduled_update'), 'every page hears about it');

            $list = $this->get($http, $port, '/api/scheduled');
            $this->assertTrue($list['owner']);
            $this->assertSame('Brief', $list['tasks'][0]['name']);
            $this->assertNull($list['tasks'][0]['nextRunAt'], 'a manual task runs when it is asked to');

            [$status] = $this->post($http, $port, '/api/scheduled/run', ['id' => $id]);
            $this->assertSame(200, $status);

            $deadline = microtime(true) + 3.0;
            do {
                Async::delay(0.02);
                $runs = $this->get($http, $port, '/api/scheduled/runs?id=' . $id)['runs'];
            } while (($runs[0]['status'] ?? null) !== 'done' && microtime(true) < $deadline);
            $this->assertSame('done', $runs[0]['status'] ?? null);

            $this->assertSame(405, $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/scheduled/run"))->status);

            fclose($ws);
            $server->stop();
        });
    }

    public function testAQuestionAScheduledRunAskedIsAskedAgainOfWhoeverOpensIt(): void
    {
        $port = 28141;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $server->schedules()->start();
            $http = new HttpClient(2.0);

            [, $saved] = $this->post($http, $port, '/api/scheduled/save', ['name' => 'Ask', 'prompt' => 'ask: may I?', 'cwd' => $this->cwd, 'schedule' => 'manual', 'timezone' => 'UTC']);
            $id = $saved['task']['id'];
            $this->post($http, $port, '/api/scheduled/run', ['id' => $id]);

            $deadline = microtime(true) + 3.0;
            do {
                Async::delay(0.02);
                $run = $this->get($http, $port, '/api/scheduled/runs?id=' . $id)['runs'][0] ?? [];
            } while (($run['status'] ?? null) !== 'needs_input' && microtime(true) < $deadline);
            $this->assertSame('needs_input', $run['status'] ?? null);

            // Nobody was looking when it asked. Opening the conversation asks again.
            $ws = $this->wsConnect($port);
            $this->wsSend($ws, ['type' => 'start_session', 'cwd' => $this->cwd, 'sessionFile' => basename($run['sessionFile'])]);
            $seen = $this->wsCollect($ws, static fn (array $m) => ($m['event']['type'] ?? null) === 'extension_ui_request');
            $this->assertSame('q1', end($seen)['event']['id'] ?? null);
            $this->assertCount(1, $server->pool()->all(), 'the run’s own child, not a second one');

            $this->wsSend($ws, ['type' => 'rpc_command', 'command' => ['type' => 'extension_ui_response', 'id' => 'q1', 'confirmed' => true]]);
            $this->wsCollect($ws, static fn (array $m) => ($m['event']['type'] ?? null) === 'message_update');
            $this->assertSame([], $server->pool()->all()[0]->pendingUi, 'answered, so a tab opened now is not asked it again');
            $this->wsCollect($ws, static fn (array $m) => ($m['event']['type'] ?? null) === 'agent_settled');

            $this->assertSame('done', $this->get($http, $port, '/api/scheduled/runs?id=' . $id)['runs'][0]['status']);
            $this->assertSame([], $server->pool()->all()[0]->pendingUi);

            fclose($ws);
            $server->stop();
        });
    }

    public function testARunOfAnAutoTaskIsNotStoppedByAConfirmation(): void
    {
        // The Claude app's "auto" permission: the run works on without waiting for approval.
        $port = 28198;
        $server = $this->server($port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $server->schedules()->start();
            $http = new HttpClient(2.0);

            [, $saved] = $this->post($http, $port, '/api/scheduled/save', ['name' => 'Auto', 'prompt' => 'ask: may I?', 'cwd' => $this->cwd, 'triggers' => [], 'timezone' => 'UTC', 'permissionMode' => 'auto']);
            $id = $saved['task']['id'];
            $this->assertSame('auto', $saved['task']['permissionMode']);
            $this->post($http, $port, '/api/scheduled/run', ['id' => $id]);

            $deadline = microtime(true) + 3.0;
            $statuses = [];
            do {
                Async::delay(0.02);
                $run = $this->get($http, $port, '/api/scheduled/runs?id=' . $id)['runs'][0] ?? [];
                $statuses[] = $run['status'] ?? null;
            } while (($run['status'] ?? null) !== 'done' && microtime(true) < $deadline);

            $this->assertSame('done', $run['status'] ?? null);
            $this->assertNotContains('needs_input', $statuses);

            $server->stop();
        });
    }

    public function testTheScheduleToolAsksTheServerThatRunsTheScheduleWithTheLocalToken(): void
    {
        // The `schedule` tool (`builtin:schedule`) from a conversation that is not the server: it
        // finds the server in the lock, gets in with the local token — which opens the scheduled
        // tasks and nothing else — and changes nothing the person has not said yes to.
        $port = 28142;
        $login = WebLogin::at($this->home);
        $login->setPassword('long enough');
        $server = $this->server($port, login: $login);
        $folder = $this->cwd . '/watched';
        mkdir($folder);
        $folder = realpath($folder);

        try {
            ScheduleClient::find($this->home);
            $this->fail('found a server before there was one');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pig web start -d', $e->getMessage());
        }

        Async::run(function () use ($server, $port, $login, $folder): void {
            $server->start();
            $server->schedules()->start();
            $url = "http://127.0.0.1:{$port}";
            $this->assertSame($url, ScheduleManager::owner($this->home)['url'] ?? null);

            $http = new HttpClient(2.0);
            $bearer = static fn (string $token): array => ['Authorization' => "Bearer {$token}"];
            $token = $login->localToken();
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/api/scheduled"))->status);
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/api/scheduled", $bearer(str_repeat('0', 64))))->status);
            $this->assertSame(200, $http->send(new Request('GET', "{$url}/api/scheduled", $bearer($token)))->status);
            $this->assertSame(401, $http->send(new Request('GET', "{$url}/api/models", $bearer($token)))->status, 'the scheduled tasks and nothing else');
            $this->assertStringContainsString('401', $this->wsStatus($port, $bearer($token)), 'no conversation, no terminal');

            $asked = [];
            $answer = true;
            $ui = $this->createStub(HookUi::class);
            $ui->method('confirm')->willReturnCallback(static function (string $title, string $message) use (&$asked, &$answer): bool {
                $asked[] = [$title, $message];

                return $answer;
            });
            $ctx = new HookContext(cwd: $folder, ui: $ui, hasUi: true);
            $tool = new ScheduleTool(timezone: static fn (): string => 'Asia/Singapore');
            $text = static fn (\Pig\Agent\AgentToolResult $r): string => $r->content[0]->text;

            $created = $text($tool->execute(['action' => 'create', 'name' => 'Upstream', 'prompt' => 'git fetch and report', 'cron' => '0 9 * * *'], $ctx));
            $this->assertStringContainsString('Created scheduled task', $created);
            $this->assertSame('Create a scheduled task?', $asked[0][0]);
            $this->assertStringContainsString("cron `0 9 * * *` (Asia/Singapore) — next ", $asked[0][1]);
            $this->assertStringContainsString("Folder: {$folder}", $asked[0][1], 'the conversation\'s folder when none is given');
            $this->assertStringContainsString("Prompt:\ngit fetch and report", $asked[0][1]);

            $tasks = $server->schedules()->list();
            $this->assertCount(1, $tasks);
            $this->assertSame(['Upstream', 'cron', '0 9 * * *', $folder], [$tasks[0]['name'], $tasks[0]['schedule'], $tasks[0]['cron'], $tasks[0]['cwd']]);
            $id = $tasks[0]['id'];
            $this->assertStringContainsString("{$id} \"Upstream\": cron `0 9 * * *`", $text($tool->execute(['action' => 'list'], $ctx)));

            $answer = false;
            $this->assertStringContainsString('declined', $text($tool->execute(['action' => 'create', 'name' => 'No', 'prompt' => 'x'], $ctx)));
            $this->assertStringContainsString('declined', $text($tool->execute(['action' => 'delete', 'id' => $id], $ctx)));
            $this->assertCount(1, $server->schedules()->list(), 'a no is nothing saved and nothing deleted');

            $answer = true;
            $text($tool->execute(['action' => 'update', 'id' => $id, 'at' => '2099-01-02 08:30'], $ctx));
            $updated = $server->schedules()->list()[0];
            $this->assertSame(['Upstream', 'once', 'git fetch and report'], [$updated['name'], $updated['schedule'], $updated['prompt']], 'what is not given stays');
            $this->assertSame((new \DateTimeImmutable('2099-01-02 08:30', new \DateTimeZone('Asia/Singapore')))->getTimestamp() * 1000, $updated['runAt']);

            $text($tool->execute(['action' => 'disable', 'id' => $id], $ctx));
            $this->assertFalse($server->schedules()->list()[0]['enabled']);

            foreach ([
                [['action' => 'create', 'name' => 'Both', 'prompt' => 'x', 'cron' => '0 9 * * *', 'at' => '2099-01-01 09:00'], 'one of cron, at or manual'],
                [['action' => 'create', 'name' => 'Bad', 'prompt' => 'x', 'cron' => '0 9 * *'], 'five fields'],
                [['action' => 'delete', 'id' => 'tnope'], "no scheduled task 'tnope'"],
            ] as [$params, $why]) {
                try {
                    $tool->execute($params, $ctx);
                    $this->fail('accepted: ' . json_encode($params));
                } catch (\Pig\Agent\AgentError $e) {
                    $this->assertStringContainsString($why, $e->getMessage());
                }
            }

            try {
                $tool->execute(['action' => 'delete', 'id' => $id], new HookContext(cwd: $folder));
                $this->fail('changed with nobody to confirm');
            } catch (\Pig\Agent\AgentError $e) {
                $this->assertStringContainsString('nobody is here to confirm', $e->getMessage());
            }
            $this->assertStringContainsString('Upstream', $text($tool->execute(['action' => 'list'], new HookContext(cwd: $folder))), 'but it can still read');

            $text($tool->execute(['action' => 'delete', 'id' => $id], $ctx));
            $this->assertSame([], $server->schedules()->list());

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

    public function testThemeEndpointsServeCssAndThemeList(): void
    {
        $port = 28103;
        $server = $this->server($port);

        Async::run(function () use ($server, $port): void {
            $server->start();
            $http = new HttpClient();

            $css = $http->send(new Request('GET', "http://127.0.0.1:{$port}/theme.css"));
            $this->assertSame(200, $css->status);
            $this->assertSame('text/css; charset=utf-8', $css->headers['content-type'] ?? null);
            $this->assertStringContainsString(':root[data-theme="dark"]', $css->body->all());

            $themes = $http->send(new Request('GET', "http://127.0.0.1:{$port}/api/themes"));
            $this->assertSame(200, $themes->status);
            $this->assertSame('application/json', $themes->headers['content-type'] ?? null);
            $list = json_decode($themes->body->all(), true);
            $this->assertSame('dark', $list['default'] ?? null);
            $this->assertNotEmpty($list['themes'] ?? []);

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

    /**
     * `node --check` parses; it does not resolve names. `Number(limit)` for `Number(args.limit)`
     * parsed fine and threw `limit is not defined` the moment a session with a ranged `read`
     * was replayed — every tab opening that conversation showed "Cannot reach pig". So the
     * components are *run*, against a minimal DOM, over the argument shapes a real session
     * replays: `read` with and without a range, every tool the card knows, a bash card.
     */
    public function testToolCardRendersEveryToolItKnowsWithoutThrowing(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the components');
        }

        $root = realpath(__DIR__ . '/../src/Web/assets/js');
        $script = <<<'JS'
        // Just enough DOM for the components to construct and render their HTML.
        const els = [];
        const mk = () => ({
          className: "", id: "", dataset: {}, style: {}, innerHTML: "", textContent: "", title: "", open: false,
          classList: { add() {}, remove() {}, toggle() { return false; }, contains() { return false; } },
          children: [], appendChild(c) { this.children.push(c); return c; },
          querySelector() { return mk(); }, querySelectorAll() { return []; },
          addEventListener() {}, remove() {}, insertBefore(c) { this.children.unshift(c); return c; },
          closest() { return null; },
        });
        globalThis.document = { createElement: () => { const e = mk(); els.push(e); return e; }, body: mk(), getElementById: () => mk() };
        globalThis.window = globalThis; globalThis.localStorage = { getItem: () => null, setItem() {} };
        Object.defineProperty(globalThis, "navigator", { value: { clipboard: { writeText: async () => {} } }, configurable: true });
        globalThis.requestAnimationFrame = (f) => f();

        const { ToolCard } = await import(process.argv[2] + "/components/ToolCard.js");
        const { ThinkingBlock } = await import(process.argv[2] + "/components/ThinkingBlock.js");

        const cases = [
          ["read", { path: "a.php" }],
          ["read", { path: "a.php", offset: 10 }],
          ["read", { path: "a.php", limit: 30 }],
          ["read", { path: "a.php", offset: 10, limit: 30 }],
          ["bash", { command: "ls -la", timeout: 5 }],
          ["edit", { path: "a.php", oldText: "a", newText: "b" }],
          ["write", { path: "a.php", content: "x\ny" }],
          ["grep", { pattern: "foo", path: "src" }],
          ["find", { pattern: "*.php", path: "." }],
          ["ls", { path: "." }],
          ["web_search", { query: "pig" }],
          ["fetch_web_page", { url: "https://x" }],
          ["browse_web_page", { url: "https://x" }],
          ["computer", { action: "click", coordinate: [1, 2], text: "hi" }],
          ["generate_image", { prompt: "a pig" }],
          ["some_unknown_tool", { a: 1 }],
          ["read", {}],
        ];
        for (const [toolName, args] of cases) {
          const c = new ToolCard({ toolCallId: "t", toolName, arguments: args });
          c.updateProgress("partial");
          c.finish({ result: { content: [{ type: "text", text: "done" }], details: { exitCode: 0 } }, isError: false });
          c.finish({ result: "plain", isError: true });
        }
        const tb = new ThinkingBlock("x"); tb.appendDelta("y"); tb.finish();
        console.log("rendered", cases.length, "tool cards");
        JS;

        $file = tempnam(sys_get_temp_dir(), 'pig-tc-') . '.mjs';
        file_put_contents($file, $script);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, "components threw while rendering:\n" . implode("\n", $out));
            $this->assertStringContainsString('rendered 17 tool cards', implode("\n", $out));
        } finally {
            unlink($file);
        }
    }

    /** The tool cards say what the TUI says, which is what upstream says: titles and warning lines. */
    public function testToolCardTitlesAndWarningsFollowUpstream(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the components');
        }

        $root = realpath(__DIR__ . '/../src/Web/assets/js');
        $script = <<<'JS'
        import assert from "node:assert/strict";
        const mk = () => {
          const parts = {};
          return {
            className: "", id: "", dataset: {}, style: {}, innerHTML: "", textContent: "", hidden: false,
            classList: { add() {}, remove() {}, toggle() { return false; }, contains() { return false; } },
            appendChild(c) { return c; }, addEventListener() {}, closest() { return null; },
            querySelector(sel) { return (parts[sel] ??= mk()); },
          };
        };
        globalThis.document = { createElement: () => mk(), body: mk(), getElementById: () => mk() };
        globalThis.window = globalThis; globalThis.localStorage = { getItem: () => "en", setItem() {} };
        Object.defineProperty(globalThis, "navigator", { value: { language: "en" }, configurable: true });
        const { ToolCard } = await import(process.argv[2] + "/components/ToolCard.js");
        const title = (toolName, args) => new ToolCard({ toolCallId: "t", toolName, arguments: args }).getDisplayTitle();

        assert.equal(title("read", { path: "/x/skills/deploy/SKILL.md" }), "[skill] deploy");
        assert.equal(title("read", { path: "AGENTS.md" }), "read resource AGENTS.md");
        assert.equal(title("read", { path: "a.php", offset: 5, limit: 25 }), "read a.php:5-29");
        assert.equal(title("grep", { pattern: "todo", limit: 5 }), "grep /todo/ in . limit 5");
        assert.equal(title("find", { pattern: "*.php", limit: 9 }), "find *.php in . (limit 9)");
        assert.equal(title("ls", { limit: 3 }), "ls . (limit 3)");
        assert.equal(title("weather", { city: "Singapore", days: 3 }), 'weather city="Singapore" days=3');
        assert.equal(title("bash", { command: "make", timeout: 30 }), "$ make (timeout 30s)");

        const notes = (toolName, details, text = "x") => {
          const c = new ToolCard({ toolCallId: "t", toolName, arguments: {} });
          c.finish({ result: { content: [{ type: "text", text }], details }, isError: false });
          return c.element.querySelector(".tool-details-footer").textContent;
        };
        assert.equal(notes("bash", { exitCode: 2 }), "", "the output already says the exit code");
        assert.equal(
          notes("bash", { fullOutputPath: "/tmp/o.log", truncation: { truncated: true, truncatedBy: "lines", outputLines: 2000, totalLines: 9000 } }),
          "[Full output: /tmp/o.log. Truncated: showing 2000 of 9000 lines]",
        );
        assert.equal(notes("grep", { matchLimitReached: 5, linesTruncated: true }), "[Truncated: 5 matches limit, some lines truncated]");
        assert.equal(notes("ls", { entryLimitReached: 200 }), "[Truncated: 200 entries limit]");
        console.log("titles ok");
        JS;

        $file = tempnam(sys_get_temp_dir(), 'pig-tt-') . '.mjs';
        file_put_contents($file, $script);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $this->assertStringContainsString('titles ok', implode("\n", $out));
        } finally {
            unlink($file);
        }
    }

    /**
     * The copy buttons over plain http — a phone on the LAN, a Tailscale address — where
     * `navigator.clipboard` does not exist and calling it threw, so a click did nothing. And
     * over https, where the clipboard API is still the one used.
     */
    public function testTheCopyButtonsCopyWhereThereIsNoClipboardApi(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the components');
        }

        $root = realpath(__DIR__ . '/../src/Web/assets/js');
        $script = <<<'JS'
        import assert from "node:assert/strict";
        const mk = (tag = "div") => ({
          tagName: tag, className: "", dataset: {}, style: {}, innerHTML: "", innerText: "", textContent: "", value: "",
          classList: { add() {}, remove() {}, toggle() { return false; }, contains() { return false; } },
          children: [], appendChild(c) { this.children.push(c); return c; }, removeChild(c) { this.children = this.children.filter((x) => x !== c); },
          querySelector() { return mk(); }, querySelectorAll() { return []; }, addEventListener() {}, closest() { return null; },
          setAttribute() {}, select() {}, setSelectionRange() {}, focus() {},
        });
        let copied = null;
        globalThis.document = {
          createElement: (t) => mk(t), body: mk("body"), getElementById: () => mk(), activeElement: null,
          execCommand(cmd) { copied = cmd === "copy" ? document.body.children.at(-1)?.value : null; return cmd === "copy"; },
        };
        globalThis.window = globalThis; globalThis.localStorage = { getItem: () => null, setItem() {} };
        globalThis.HTMLElement = class {};
        globalThis.isSecureContext = false;
        Object.defineProperty(globalThis, "navigator", { value: {}, configurable: true });

        await import(process.argv[2] + "/components/ToolCard.js");
        const terminal = mk(); terminal.textContent = "==> Downloading tailscale";
        const card = { querySelector: (sel) => (sel === ".tool-terminal" ? terminal : null) };
        const btn = mk("button"); btn.closest = () => card; btn.innerText = "📋";

        window.copyToolOutput(btn);
        await new Promise((r) => setTimeout(r, 10));
        assert.equal(copied, "==> Downloading tailscale", "plain http copies through the selection");
        assert.equal(btn.innerText, "✓");

        globalThis.isSecureContext = true;
        let written = null;
        Object.defineProperty(globalThis, "navigator", { value: { clipboard: { writeText: async (t) => { written = t; } } }, configurable: true });
        copied = null;
        const { copyText } = await import(process.argv[2] + "/utils.js");
        await copyText("over https");
        assert.equal(written, "over https");
        assert.equal(copied, null, "the clipboard API where there is one");

        globalThis.isSecureContext = false;
        document.execCommand = () => false;
        await assert.rejects(copyText("refused"), /refused to copy/);
        console.log("copied");
        JS;

        $file = tempnam(sys_get_temp_dir(), 'pig-copy-') . '.mjs';
        file_put_contents($file, $script);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $this->assertStringContainsString('copied', implode("\n", $out));
        } finally {
            unlink($file);
        }
    }

    /** The theme button says the theme's short name in the page's language, not its id. */
    public function testTheThemeButtonIsNamedInThePagesLanguage(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the components');
        }

        $root = realpath(__DIR__ . '/../src/Web/assets/js');
        $file = tempnam(sys_get_temp_dir(), 'pig-theme-') . '.mjs';
        file_put_contents($file, <<<'JS'
        const els = {};
        const mk = () => ({ textContent: "", title: "", style: {}, dataset: {}, classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } }, setAttribute() {}, addEventListener() {}, querySelector() { return mk(); }, querySelectorAll() { return []; }, appendChild(c) { return c; } });
        globalThis.document = { documentElement: mk(), getElementById: (id) => (els[id] ??= mk()), createElement: mk, body: mk(), querySelectorAll: () => [], addEventListener() {} };
        globalThis.window = globalThis;
        const store = { pig_web_locale: process.argv[3] };
        globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v; } };
        Object.defineProperty(globalThis, "navigator", { value: { language: "en" }, configurable: true });
        const { applyTheme } = await import(process.argv[2] + "/theme.js");
        applyTheme("dark"); console.log(els["theme-btn"].textContent);
        applyTheme("light"); console.log(els["theme-btn"].textContent);
        applyTheme("solarized"); console.log(els["theme-btn"].textContent);
        JS);

        try {
            foreach (['zh-CN' => ['🎨 暗黑', '🎨 浅色'], 'en' => ['🎨 Dark', '🎨 Light']] as $locale => [$dark, $light]) {
                $out = [];
                exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' ' . $locale . ' 2>&1', $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
                // A theme i18n has no name for keeps its own.
                $this->assertSame([$dark, $light, '🎨 solarized'], $out, $locale);
            }
        } finally {
            unlink($file);
        }
    }
}
