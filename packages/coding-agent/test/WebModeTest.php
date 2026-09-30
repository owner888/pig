<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Web\HttpServer;
use Pig\CodingAgent\Web\WebMode;

final class WebModeTest extends TestCase
{
    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-web-test-' . bin2hex(random_bytes(6));
        mkdir($this->cwd, 0755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
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

    private function session(): AgentSession
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));

        return new AgentSession($agent, $this->cwd);
    }

    public function testServesIndexHtmlAndApiEndpoints(): void
    {
        $port = 28088;
        $session = $this->session();
        $server = new HttpServer($session, $port);

        Async::run(function () use ($server, $port) {
            $server->start();
            $this->assertTrue($server->isRunning());

            $http = new HttpClient(2.0);

            // 1. GET /
            $respIndex = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/"));
            $this->assertSame(200, $respIndex->status);
            $this->assertStringContainsString('<!DOCTYPE html>', $respIndex->body->all());

            // 2. GET /api/state
            $respState = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/state"));
            $this->assertSame(200, $respState->status);
            $stateData = json_decode($respState->body->all(), true);
            $this->assertIsArray($stateData);
            $this->assertArrayHasKey('tokensIn', $stateData);

            // 3. GET /api/messages
            $respMsgs = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/messages"));
            $this->assertSame(200, $respMsgs->status);
            $this->assertSame('[]', trim($respMsgs->body->all()));

            // 4. GET /api/folders
            $respFolders = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/folders"));
            $this->assertSame(200, $respFolders->status);
            $folderData = json_decode($respFolders->body->all(), true);
            $this->assertIsArray($folderData);
            $this->assertArrayHasKey('currentCwd', $folderData);
            $this->assertArrayHasKey('workspaces', $folderData);

            // 5. GET /api/sessions
            $respSessions = $http->follow(new Request('GET', "http://127.0.0.1:{$port}/api/sessions"));
            $this->assertSame(200, $respSessions->status);
            $this->assertSame('[]', trim($respSessions->body->all()));

            // 6. POST /api/abort
            $respAbort = $http->follow(new Request('POST', "http://127.0.0.1:{$port}/api/abort"));
            $this->assertSame(200, $respAbort->status);

            // Stop server
            $server->stop();
            $this->assertFalse($server->isRunning());
        });
    }

    public function testWebSocketUpgradeAndDuplexRpc(): void
    {
        $port = 28090;
        $session = $this->session();
        $server = new HttpServer($session, $port);

        Async::run(function () use ($server, $port) {
            $server->start();

            $errno = 0;
            $errstr = '';
            $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
            $this->assertIsResource($client);
            stream_set_blocking($client, false);

            // 1. Send WebSocket handshake
            $key = base64_encode('test-nonce-12345');
            $handshakeReq = "GET /ws HTTP/1.1\r\n"
                . "Host: 127.0.0.1:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";

            fwrite($client, $handshakeReq);

            // Wait for 101 response
            $resp = '';
            for ($i = 0; $i < 20; $i++) {
                Async::delay(0.01);
                $chunk = fread($client, 4096);
                if ($chunk !== false) {
                    $resp .= $chunk;
                }
                if (str_contains($resp, "\r\n\r\n")) {
                    break;
                }
            }

            $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $resp);
            $this->assertStringContainsString('Upgrade: websocket', $resp);

            // 2. Send WebSocket client masked frame: {"type":"get_state","id":42}
            $payload = json_encode(['type' => 'get_state', 'id' => 42]);
            $mask = "\x12\x34\x56\x78";
            $maskedPayload = '';
            for ($j = 0; $j < strlen($payload); $j++) {
                $maskedPayload .= $payload[$j] ^ $mask[$j % 4];
            }
            $frame = "\x81" . chr(0x80 | strlen($payload)) . $mask . $maskedPayload;
            fwrite($client, $frame);

            // 3. Receive WebSocket server unmasked frame
            $wsResp = '';
            for ($i = 0; $i < 20; $i++) {
                Async::delay(0.01);
                $chunk = fread($client, 4096);
                if ($chunk !== false) {
                    $wsResp .= $chunk;
                }
                if (strlen($wsResp) >= 2) {
                    break;
                }
            }

            $this->assertGreaterThanOrEqual(2, strlen($wsResp));
            $this->assertSame(0x81, ord($wsResp[0])); // text frame

            fclose($client);
            $server->stop();
        });
    }

    public function testWebModeInstantiatesAndExposesStop(): void
    {
        $session = $this->session();
        $mode = new WebMode($session, 28089);
        $this->assertSame(28089, $mode->port);
        $mode->stop();
    }
}
