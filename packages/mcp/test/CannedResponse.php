<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A `Response` with the given status and body, for a `Closure(Request): Response` that answers
 * from a table.
 *
 * A `Response` reads its body from a real `Socket`, so the bytes are served through a loopback
 * socket on the loop and fetched with pig's own `HttpClient` — which also means the status line and
 * headers go through the same parser a live answer would.
 */
final class CannedResponse
{
    /** @var list<resource> listening sockets, closed at the end of the run */
    private static array $servers = [];

    /** @var list<string> */
    private static array $watchers = [];

    public static function make(int $status, string $body, array $headers = []): Response
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Cannot open canned server: {$errstr}");
        }

        stream_set_blocking($socket, false);
        $lines = "HTTP/1.1 {$status} " . ($status === 200 ? 'OK' : 'Status') . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n";

        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }

        $payload = $lines . "\r\n" . $body;
        $watcher = Loop::get()->onReadable($socket, static function (mixed $listening) use ($payload, &$watcher): void {
            $peer = stream_socket_accept($listening, 0);

            if ($peer === false) {
                return;
            }

            // Read the request head, so the client is not writing into a closed socket, then answer.
            stream_set_blocking($peer, true);
            stream_set_timeout($peer, 2);
            $head = '';

            while (!str_contains($head, "\r\n\r\n") && ($chunk = fread($peer, 8192)) !== false && $chunk !== '') {
                $head .= $chunk;
            }

            fwrite($peer, $payload);
            fclose($peer);
            Loop::get()->cancel((string) $watcher);
        });
        self::$servers[] = $socket;
        self::$watchers[] = $watcher;

        $client = new HttpClient(timeout: 5.0);

        return $client->send(new Request('GET', 'http://' . stream_socket_get_name($socket, false) . '/'));
    }

    /** Let the sockets go; `Loop::reset()` in `setUp()` drops the watchers already. */
    public static function cleanup(): void
    {
        foreach (self::$servers as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        self::$servers = [];
        self::$watchers = [];
    }
}
