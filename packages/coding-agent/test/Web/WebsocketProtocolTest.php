<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Protocols\Websocket;
use Pig\CodingAgent\Web\TcpConnection;

final class WebsocketProtocolTest extends TestCase
{
    public function testHandshakeGeneratesValidRfc6455AcceptHeader(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        $conn = new TcpConnection(
            $serverSock,
            onMessage: static fn () => null,
            onClose: static fn () => null,
        );

        $request = [
            'method' => 'GET',
            'uri' => '/ws',
            'path' => '/ws',
            'query' => [],
            'headers' => [
                'upgrade' => 'websocket',
                'connection' => 'Upgrade',
                'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            ],
            'body' => '',
        ];

        $ok = Websocket::handshake($request, $conn);
        $this->assertTrue($ok);
        $this->assertSame(Websocket::class, $conn->getProtocol());

        // Read handshake response from client socket
        $response = fread($clientSock, 4096);
        $this->assertIsString($response);
        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);

        $conn->close();
        fclose($clientSock);
    }

    public function testInputDetectsCompleteAndIncompleteFrames(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        // Incomplete: only 1 byte
        $this->assertSame(0, Websocket::input("\x81", $conn));

        // Masked client frame: "hi" (2 bytes payload + 4 bytes mask + 2 bytes header = 8 bytes)
        $mask = "\x11\x22\x33\x44";
        $maskedPayload = ('h' ^ "\x11") . ('i' ^ "\x22");
        $frame = "\x81\x82" . $mask . $maskedPayload;

        $this->assertSame(8, Websocket::input($frame, $conn));

        // Truncated: 7 bytes instead of 8
        $this->assertSame(0, Websocket::input(substr($frame, 0, 7), $conn));

        $conn->close();
        fclose($clientSock);
    }

    public function testDecodeUnmasksPayloadAndHandlesPing(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        // 1. Text frame: "hello" masked
        $text = 'hello';
        $mask = "\xaa\xbb\xcc\xdd";
        $masked = '';
        for ($i = 0; $i < strlen($text); $i++) {
            $masked .= $text[$i] ^ $mask[$i % 4];
        }
        $frame = "\x81\x85" . $mask . $masked;

        $decoded = Websocket::decode($frame, $conn);
        $this->assertSame('hello', $decoded);

        // 2. Ping frame auto responds Pong
        $pingFrame = "\x89\x84" . "\x01\x02\x03\x04" . ("ping" ^ "\x01\x02\x03\x04");
        $decodedPing = Websocket::decode($pingFrame, $conn);
        $this->assertNull($decodedPing);

        $pongResponse = fread($clientSock, 1024);
        $this->assertIsString($pongResponse);
        $this->assertSame(0x8A, ord($pongResponse[0])); // FIN + Pong opcode 0xA

        $conn->close();
        fclose($clientSock);
    }

    public function testEncodeGeneratesValidUnmaskedServerFrames(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        // Small text frame
        $frame = Websocket::encode('test', $conn);
        $this->assertSame(0x81, ord($frame[0]));
        $this->assertSame(4, ord($frame[1])); // no mask, len 4
        $this->assertSame('test', substr($frame, 2));

        // Medium frame (126 <= len <= 65535)
        $mediumPayload = str_repeat('a', 300);
        $medFrame = Websocket::encode($mediumPayload, $conn);
        $this->assertSame(0x81, ord($medFrame[0]));
        $this->assertSame(126, ord($medFrame[1]));
        $len = unpack('n', substr($medFrame, 2, 2))[1];
        $this->assertSame(300, $len);
        $this->assertSame($mediumPayload, substr($medFrame, 4));

        $conn->close();
        fclose($clientSock);
    }

    public function testPingGeneratesValidRfc6455PingFrame(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        Websocket::ping($conn, 'heartbeat');

        $pingBytes = fread($clientSock, 1024);
        $this->assertIsString($pingBytes);
        $this->assertSame(0x89, ord($pingBytes[0])); // FIN + Ping opcode 0x9
        $this->assertSame(9, ord($pingBytes[1]));    // Payload len = 9 ("heartbeat")
        $this->assertSame('heartbeat', substr($pingBytes, 2));

        $conn->close();
        fclose($clientSock);
    }

    public function testDecodeHandlesPongFrameQuietly(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        // Masked Pong frame from client: opcode 0xA
        $mask = "\x55\x66\x77\x88";
        $pongPayload = "heartbeat";
        $masked = '';
        for ($i = 0; $i < strlen($pongPayload); $i++) {
            $masked .= $pongPayload[$i] ^ $mask[$i % 4];
        }
        $pongFrame = "\x8A\x89" . $mask . $masked;

        $decoded = Websocket::decode($pongFrame, $conn);
        $this->assertNull($decoded, 'Pong frame should be consumed quietly without triggering onMessage');

        $conn->close();
        fclose($clientSock);
    }

    /**
     * Chrome sends a large message as a first frame without FIN and then continuations — a
     * 150 KB prompt with a screenshot arrived as three frames, and each was handed over as a
     * message of its own, so the server read broken JSON and the page waited 30 s for an answer.
     */
    public function testAMessageSplitAcrossFramesArrivesWholeAndOnce(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $received = [];
        $conn = new TcpConnection($serverSock, static function ($c, $data) use (&$received): void { $received[] = $data; }, static fn () => null, Websocket::class);

        $message = json_encode(['type' => 'prompt', 'images' => [base64_encode(random_bytes(3000))]]);
        $first = substr($message, 0, 2000);
        $middle = substr($message, 2000, 500);
        $last = substr($message, 2500);

        $bytes = self::clientFrame(Websocket::OPCODE_TEXT, $first, final: false)
            . self::clientFrame(Websocket::OPCODE_PING, 'x')
            . self::clientFrame(Websocket::OPCODE_CONTINUATION, $middle, final: false)
            . self::clientFrame(Websocket::OPCODE_CONTINUATION, $last);
        fwrite($clientSock, $bytes);

        while ($received === [] && !$conn->isClosed()) {
            $conn->onReadable();
        }

        // Compared by length and hash: a failure should not print 150 KB of base64.
        $this->assertSame([[strlen($message), md5($message)]], array_map(static fn (string $m): array => [strlen($m), md5($m)], $received));
        $this->assertFalse($conn->isClosed());
        $this->assertNull($conn->websocketDataBuffer);

        // The next message starts afresh.
        fwrite($clientSock, self::clientFrame(Websocket::OPCODE_TEXT, 'next'));
        $conn->onReadable();
        $this->assertCount(2, $received);
        $this->assertSame('next', $received[1]);

        $conn->close();
        fclose($clientSock);
    }

    public function testAContinuationWithNothingToContinueClosesTheConnection(): void
    {
        [$clientSock, $serverSock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($serverSock, static fn () => null, static fn () => null);

        $this->assertNull(Websocket::decode(self::clientFrame(Websocket::OPCODE_CONTINUATION, 'stray'), $conn));
        $this->assertTrue($conn->isClosed());

        fclose($clientSock);
    }

    /** A frame as a browser sends it: masked, with the length in whichever form fits. */
    private static function clientFrame(int $opcode, string $payload, bool $final = true): string
    {
        $mask = random_bytes(4);
        $length = strlen($payload);
        $head = chr(($final ? 0x80 : 0) | $opcode);
        $head .= match (true) {
            $length <= 125 => chr(0x80 | $length),
            $length <= 65535 => chr(0x80 | 126) . pack('n', $length),
            default => chr(0x80 | 127) . pack('J', $length),
        };
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        return $head . $mask . $masked;
    }
}
