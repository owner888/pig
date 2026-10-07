<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Web\HttpServer;

final class WebPtyHttpTest extends TestCase
{
    protected function tearDown(): void
    {
        Loop::reset();
        parent::tearDown();
    }

    public function testTerminalMessagesOverWebSocket(): void
    {
        $port = 19890;
        $server = new HttpServer(sys_get_temp_dir(), $port, '127.0.0.1', Auth::inMemory());
        $server->start();

        $outputCollected = '';
        $listReceived = false;

        Async::run(function () use ($port, $server, &$outputCollected, &$listReceived): void {
            // Connect to server
            $socket = Socket::connect('127.0.0.1', $port);

            // Upgrade to WebSocket
            $key = base64_encode(random_bytes(16));
            $req = "GET /ws HTTP/1.1\r\n"
                . "Host: 127.0.0.1:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            $socket->write($req);

            // Read upgrade response
            $resp = $socket->read(1024);
            $this->assertStringContainsString('101 Switching Protocols', $resp);

            // Send terminal_create frame
            $createMsg = json_encode([
                'type' => 'terminal_create',
                'terminalId' => 'term-ws-1',
                'cwd' => sys_get_temp_dir(),
                'cols' => 80,
                'rows' => 24,
            ]);
            $this->sendWsFrame($socket, (string) $createMsg);

            // Send terminal_resize
            $resizeMsg = json_encode([
                'type' => 'terminal_resize',
                'terminalId' => 'term-ws-1',
                'cols' => 100,
                'rows' => 30,
            ]);
            $this->sendWsFrame($socket, (string) $resizeMsg);

            // Send terminal_input: echo PTY_WS_TEST_OK
            Loop::get()->delay(0.08, function () use ($socket): void {
                $inputMsg = json_encode([
                    'type' => 'terminal_input',
                    'terminalId' => 'term-ws-1',
                    'data' => "echo PTY_WS_TEST_OK\n",
                ]);
                $this->sendWsFrame($socket, (string) $inputMsg);
            });

            // Read frames until output received
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $frame = $this->readWsFrame($socket);
                if ($frame !== null) {
                    $data = json_decode($frame, true);
                    if (is_array($data)) {
                        if (($data['type'] ?? '') === 'terminal_list') {
                            $listReceived = true;
                        }
                        if (($data['type'] ?? '') === 'terminal_output') {
                            $outputCollected .= (string) ($data['data'] ?? '');
                            if (str_contains($outputCollected, 'PTY_WS_TEST_OK')) {
                                break;
                            }
                        }
                    }
                }
            }

            $this->assertTrue($listReceived);
            $this->assertStringContainsString('PTY_WS_TEST_OK', $outputCollected);

            // Cleanup
            $killMsg = json_encode([
                'type' => 'terminal_kill',
                'terminalId' => 'term-ws-1',
            ]);
            $this->sendWsFrame($socket, (string) $killMsg);

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
