<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Protocols;

use Pig\CodingAgent\Web\TcpConnection;

/**
 * Server-sent events, the server's side: one `text/event-stream` response, written one event at
 * a time until the server closes it.
 *
 * Not a second framing protocol in the way `Websocket` is. The client's half of an SSE
 * connection is an ordinary HTTP request — `Http` reads it — and what is "SSE" is only the
 * response: a head with no `Content-Length`, then `event:`/`data:` frames for as long as the
 * server has something to say. So `open()` writes the head and switches the connection here,
 * `encode()` frames each event, and `input()` swallows anything the client sends afterwards,
 * because a client has nothing to say on a stream it is reading.
 *
 * The body is ended by closing the connection (`Connection: close`) rather than by chunked
 * encoding: HTTP/1.1 allows it, every SSE client handles it, and it is one fewer framing layer
 * for the reader on the other end — `Ai\Http\SseParser` — to be wrong about.
 */
final class Sse implements ProtocolInterface
{
    /**
     * Write the response head and switch the connection to this protocol.
     *
     * @param array<string, string> $headers anything to add beside the three SSE needs
     */
    public static function open(TcpConnection $connection, array $headers = []): void
    {
        $head = "HTTP/1.1 200 OK\r\n";

        foreach ([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'close',
            ...$headers,
        ] as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        $connection->sendRaw($head . "\r\n");
        $connection->setProtocol(self::class);
    }

    /** Whatever the client sends on an open stream is read and dropped. */
    public static function input(string $buffer, TcpConnection $connection): int|false
    {
        return strlen($buffer);
    }

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return null;
    }

    /**
     * One event.
     *
     * A string is a bare `data:` event. An array names its fields: `event`, `id`, `data` (a
     * string, or anything else JSON-encoded), `retry`, and `comment` for a `: keep-alive` line
     * with no data at all. A newline inside the data is a second `data:` line, as the format
     * requires, and the reader joins them back.
     *
     * @param string|array{event?: string, id?: string, data?: mixed, retry?: int, comment?: string} $data
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        if (is_string($data)) {
            $data = ['data' => $data];
        }

        if (!is_array($data)) {
            return '';
        }

        $frame = '';

        if (isset($data['comment'])) {
            $frame .= ': ' . str_replace(["\r\n", "\r", "\n"], ' ', (string) $data['comment']) . "\n";
        }

        foreach (['event', 'id', 'retry'] as $field) {
            if (isset($data[$field])) {
                $frame .= "{$field}: " . str_replace(["\r\n", "\r", "\n"], ' ', (string) $data[$field]) . "\n";
            }
        }

        if (array_key_exists('data', $data)) {
            $payload = is_string($data['data'])
                ? $data['data']
                : (string) json_encode($data['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            foreach (explode("\n", str_replace("\r\n", "\n", $payload)) as $line) {
                $frame .= "data: {$line}\n";
            }
        }

        return $frame === '' ? '' : $frame . "\n";
    }
}
