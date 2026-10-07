<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Web\HttpServer;

final class WebNodeHttpTest extends TestCase
{
    protected function tearDown(): void
    {
        Loop::reset();
        parent::tearDown();
    }

    public function testNodeWorkbenchOverWebSocket(): void
    {
        $port = 19891;
        $server = new HttpServer(sys_get_temp_dir(), $port, '127.0.0.1', Auth::inMemory());
        $server->start();

        $receivedEvents = [];

        Async::run(function () use ($port, $server, &$receivedEvents): void {
            $socket = Socket::connect('127.0.0.1', $port);

            // WebSocket handshake
            $key = base64_encode(random_bytes(16));
            $req = "GET /ws HTTP/1.1\r\n"
                . "Host: 127.0.0.1:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            $socket->write($req);
            $resp = $socket->read(1024);
            $this->assertStringContainsString('101 Switching Protocols', $resp);

            // 1. Request node state
            $stateReq = json_encode([
                'type' => 'node_request',
                'action' => 'state',
                'requestId' => 'req-state-1',
            ]);
            $this->sendWsFrame($socket, (string) $stateReq);

            // 2. Save a node
            $saveReq = json_encode([
                'type' => 'node_request',
                'action' => 'save',
                'requestId' => 'req-save-1',
                'payload' => [
                    'id' => 'test-node-ws',
                    'name' => 'WS Node',
                    'group' => 'TestGroup',
                    'host' => '10.0.0.99',
                    'port' => 22,
                    'username' => 'testuser',
                    'auth' => 'key',
                ],
            ]);
            $this->sendWsFrame($socket, (string) $saveReq);

            // 3. Trust fingerprint
            $trustReq = json_encode([
                'type' => 'node_request',
                'action' => 'trust',
                'requestId' => 'req-trust-1',
                'nodeId' => 'test-node-ws',
                'payload' => [
                    'fingerprint' => 'SHA256:dummyfingerprint123',
                ],
            ]);
            $this->sendWsFrame($socket, (string) $trustReq);

            // 4. Delete node
            $delReq = json_encode([
                'type' => 'node_request',
                'action' => 'delete',
                'requestId' => 'req-del-1',
                'nodeId' => 'test-node-ws',
            ]);
            $this->sendWsFrame($socket, (string) $delReq);

            // Read responses
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $frame = $this->readWsFrame($socket);
                if ($frame !== null) {
                    $data = json_decode($frame, true);
                    if (is_array($data) && ($data['type'] ?? '') === 'node_event') {
                        $receivedEvents[] = $data;
                        if (($data['action'] ?? '') === 'delete' && ($data['event'] ?? '') === 'result') {
                            break;
                        }
                    }
                }
            }

            $actions = array_map(static fn (array $d) => $d['action'] ?? $d['event'] ?? '', $receivedEvents);
            $this->assertContains('state', $actions);
            $this->assertContains('save', $actions);
            $this->assertContains('trust', $actions);
            $this->assertContains('delete', $actions);

            $socket->close();
            $server->stop();
        });
    }

    private function sendWsFrame(Socket $socket, string $payload): void
    {
        $len = strlen($payload);
        $mask = random_bytes(4);

        if ($len < 126) {
            $header = chr(0x81) . chr(0x80 | $len) . $mask;
        } elseif ($len < 65536) {
            $header = chr(0x81) . chr(0x80 | 126) . pack('n', $len) . $mask;
        } else {
            $header = chr(0x81) . chr(0x80 | 127) . pack('J', $len) . $mask;
        }

        $maskedPayload = '';
        for ($i = 0; $i < $len; $i++) {
            $maskedPayload .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        $socket->write($header . $maskedPayload);
    }

    private function readWsFrame(Socket $socket): ?string
    {
        try {
            $h = $socket->read(2);
            if (strlen($h) < 2) {
                return null;
            }
            $b2 = ord($h[1]);
            $hasMask = ($b2 & 0x80) !== 0;
            $len = $b2 & 0x7F;
            if ($len === 126) {
                $ext = $socket->read(2);
                $len = unpack('n', $ext)[1];
            } elseif ($len === 127) {
                $ext = $socket->read(8);
                $len = unpack('J', $ext)[1];
            }

            $mask = '';
            if ($hasMask) {
                $mask = $socket->read(4);
            }

            $payload = $len > 0 ? $socket->read($len) : '';
            if ($hasMask && strlen($mask) === 4) {
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $mask[$i % 4];
                }
                return $unmasked;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }
}
