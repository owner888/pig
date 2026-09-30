<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Protocols;

use Pig\CodingAgent\Web\TcpConnection;

/**
 * Pure PHP RFC 6455 WebSocket protocol implementation.
 *
 * Implements handshake, framing, masking/unmasking, and ping/pong keep-alive.
 */
final class Websocket implements ProtocolInterface
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Perform RFC 6455 WebSocket opening handshake from an HTTP upgrade request.
     *
     * @param array{headers: array<string, string>} $request
     */
    public static function handshake(array $request, TcpConnection $connection): bool
    {
        $headers = $request['headers'];
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connectionHeader = strtolower($headers['connection'] ?? '');
        $key = $headers['sec-websocket-key'] ?? null;

        if ($upgrade !== 'websocket' || !str_contains($connectionHeader, 'upgrade') || $key === null) {
            return false;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));

        $handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        // Raw send handshake bytes, then switch connection protocol to Websocket
        $connection->sendRaw($handshakeResponse);
        $connection->setProtocol(self::class);

        return true;
    }

    /**
     * Check whether the WebSocket frame in buffer is complete.
     */
    public static function input(string $buffer, TcpConnection $connection): int|false
    {
        $len = strlen($buffer);

        if ($len < 2) {
            return 0; // Need at least 2 bytes for header
        }

        $byte2 = ord($buffer[1]);
        $hasMask = ($byte2 & 0x80) !== 0;
        $payloadLen = $byte2 & 0x7F;

        $headLen = 2;

        if ($payloadLen === 126) {
            $headLen += 2;
            if ($len < $headLen) {
                return 0;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
        } elseif ($payloadLen === 127) {
            $headLen += 8;
            if ($len < $headLen) {
                return 0;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
        }

        if ($hasMask) {
            $headLen += 4;
        }

        $totalLen = $headLen + $payloadLen;

        if ($len < $totalLen) {
            return 0; // Incomplete frame, wait for more data
        }

        return $totalLen;
    }

    /**
     * Decode a single complete WebSocket frame into unmasked payload data.
     * Automatically replies to PING with PONG and handles CLOSE frames.
     */
    public static function decode(string $buffer, TcpConnection $connection): ?string
    {
        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);

        $opcode = $byte1 & 0x0F;
        $hasMask = ($byte2 & 0x80) !== 0;
        $payloadLen = $byte2 & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset += 8;
        }

        $mask = '';
        if ($hasMask) {
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        if ($hasMask && $mask !== '') {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        // Automatic ping/pong keep-alive handling
        if ($opcode === self::OPCODE_PING) {
            $connection->sendRaw(self::createFrame($payload, self::OPCODE_PONG));

            return null;
        }

        // Client requested connection close
        if ($opcode === self::OPCODE_CLOSE) {
            $connection->sendRaw(self::createFrame('', self::OPCODE_CLOSE));
            $connection->close();

            return null;
        }

        return $payload;
    }

    /**
     * Encode outgoing data into an RFC 6455 unmasked text frame.
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::createFrame((string) $payload, self::OPCODE_TEXT);
    }

    /**
     * Create an unmasked WebSocket frame from server to client.
     */
    public static function createFrame(string $payload, int $opcode): string
    {
        $firstByte = 0x80 | ($opcode & 0x0F); // FIN = 1
        $len = strlen($payload);

        if ($len <= 125) {
            $header = chr($firstByte) . chr($len);
        } elseif ($len <= 65535) {
            $header = chr($firstByte) . chr(126) . pack('n', $len);
        } else {
            $header = chr($firstByte) . chr(127) . pack('J', $len);
        }

        return $header . $payload;
    }
}
