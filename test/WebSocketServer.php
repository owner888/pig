<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback server that speaks WebSocket to an upgrade request and plain HTTP to anything else —
 * so a test can watch a client try the one and fall back to the other on the same address.
 *
 * Each text message a client sends goes to `$onMessage` with a `send` (a string, or an array sent
 * as JSON), a `close` (code, reason), and `raw` for bytes written as they are (frames built with
 * `frame()`). `$upgrade` decides each handshake: `accept`, `refuse`
 * (a 404) or `ignore` (no answer, for a timeout).
 */
final class WebSocketServer
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var list<array<string, string>> each upgrade request's lowercased headers */
    public array $upgrades = [];

    /** @var list<array{connection: int, message: mixed}> each message received, JSON-decoded, and which connection it came on */
    public array $messages = [];

    /** @var list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public array $httpRequests = [];

    /** @var 'accept'|'refuse'|'ignore' */
    public string $upgrade = 'accept';

    /** @var list<resource> */
    private array $open = [];

    /** @var list<string> */
    private array $watchers = [];

    private int $connections = 0;

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->watchers = [];

        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->open = [];
    }

    /**
     * @param Closure(mixed $message, Closure(string|array<mixed>): void $send, Closure(int, string): void $close, int $connection, Closure(string): void $raw): void $onMessage
     * @param (Closure(array{method: string, path: string, headers: array<string, string>, body: string}): array{0: int, 1: array<string, string>, 2: string})|null $http
     * @return string base URL (`http://`), no trailing slash
     */
    public function start(Closure $onMessage, ?Closure $http = null): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening) use ($onMessage, $http): void {
            $peer = stream_socket_accept($listening, 0);

            if ($peer === false) {
                return;
            }

            stream_set_blocking($peer, false);
            $this->open[] = $peer;
            $id = $this->connections++;
            $buffer = '';
            $upgraded = false;
            $reader = null;
            $send = static function (string|array $message) use ($peer): void {
                if (is_resource($peer)) {
                    fwrite($peer, self::frame(0x1, is_string($message) ? $message : (string) json_encode($message)));
                }
            };
            $close = static function (int $code, string $reason) use ($peer, &$reader): void {
                if ($reader !== null) {
                    Loop::get()->cancel((string) $reader);
                }

                if (is_resource($peer)) {
                    fwrite($peer, self::frame(0x8, pack('n', $code) . $reason));
                    fclose($peer);
                }
            };

            $reader = Loop::get()->onReadable($peer, function ($peer) use (&$buffer, &$upgraded, &$reader, $id, $onMessage, $http, $send, $close): void {
                $data = is_resource($peer) ? fread($peer, 65536) : false;

                if ($data === false || $data === '') {
                    if (!is_resource($peer) || feof($peer)) {
                        Loop::get()->cancel((string) $reader);
                    }

                    return;
                }

                $buffer .= $data;

                if (!$upgraded) {
                    $split = strpos($buffer, "\r\n\r\n");

                    if ($split === false) {
                        return;
                    }

                    $lines = explode("\r\n", substr($buffer, 0, $split));
                    $headers = [];

                    foreach (array_slice($lines, 1) as $line) {
                        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                        $headers[strtolower($name)] = $value;
                    }

                    if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
                        $body = substr($buffer, $split + 4);

                        if (strlen($body) < (int) ($headers['content-length'] ?? 0)) {
                            return;
                        }

                        Loop::get()->cancel((string) $reader);
                        [$method, $target] = explode(' ', $lines[0]);
                        $request = ['method' => $method, 'path' => (string) parse_url($target, PHP_URL_PATH), 'headers' => $headers, 'body' => $body];
                        $this->httpRequests[] = $request;
                        [$status, $replyHeaders, $replyBody] = $http !== null ? $http($request) : [404, [], ''];
                        $out = "HTTP/1.1 {$status} " . ($status < 400 ? 'OK' : 'Error') . "\r\n";

                        foreach ($replyHeaders as $name => $value) {
                            $out .= "{$name}: {$value}\r\n";
                        }

                        fwrite($peer, $out . 'content-length: ' . strlen($replyBody) . "\r\nconnection: close\r\n\r\n" . $replyBody);
                        fclose($peer);

                        return;
                    }

                    $this->upgrades[] = $headers;
                    $buffer = substr($buffer, $split + 4);

                    if ($this->upgrade === 'ignore') {
                        return;
                    }

                    if ($this->upgrade === 'refuse') {
                        fwrite($peer, "HTTP/1.1 404 Not Found\r\ncontent-length: 0\r\nconnection: close\r\n\r\n");
                        fclose($peer);
                        Loop::get()->cancel((string) $reader);

                        return;
                    }

                    $accept = base64_encode(sha1(($headers['sec-websocket-key'] ?? '') . self::GUID, true));
                    fwrite($peer, "HTTP/1.1 101 Switching Protocols\r\nupgrade: websocket\r\nconnection: Upgrade\r\nsec-websocket-accept: {$accept}\r\n\r\n");
                    $upgraded = true;
                }

                while (($frame = self::takeFrame($buffer)) !== null) {
                    [$opcode, $payload] = $frame;

                    if ($opcode === 0x8) {
                        Loop::get()->cancel((string) $reader);

                        if (is_resource($peer)) {
                            fclose($peer);
                        }

                        return;
                    }

                    if ($opcode === 0x1) {
                        $message = json_decode($payload, true);
                        $this->messages[] = ['connection' => $id, 'message' => $message];
                        $onMessage($message, $send, $close, $id, static function (string $bytes) use ($peer): void {
                            if (is_resource($peer)) {
                                fwrite($peer, $bytes);
                            }
                        });
                    }
                }
            });
            $this->watchers[] = (string) $reader;
        });

        return 'http://' . (string) stream_socket_get_name($server, false);
    }

    /** @return list<mixed> the messages' bodies */
    public function bodies(): array
    {
        return array_column($this->messages, 'message');
    }

    /** @return array{0: int, 1: string}|null opcode and unmasked payload */
    private static function takeFrame(string &$buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $length = ord($buffer[1]) & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }

            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }

            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if (strlen($buffer) < $offset + 4 + $length) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $length);
        $opcode = ord($buffer[0]) & 0x0F;
        $buffer = substr($buffer, $offset + 4 + $length);
        $unmasked = '';

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }

        return [$opcode, $unmasked];
    }

    /** A server frame: final and unmasked. */
    public static function frame(int $opcode, string $payload, bool $fin = true): string
    {
        $length = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | $opcode);

        if ($length < 126) {
            $head .= chr($length);
        } elseif ($length < 65536) {
            $head .= chr(126) . pack('n', $length);
        } else {
            $head .= chr(127) . pack('J', $length);
        }

        return $head . $payload;
    }
}
