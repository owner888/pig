<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback HTTP server that answers each request from a closure — upstream's tests hand the API a
 * `fetch` that routes by path and records what it was sent; pig's providers take no `fetch`, so the
 * same routing sits behind a socket.
 *
 * The closure gets the request (`method`, `path`, lowercased `headers`, raw `body`) and answers
 * `[status, headers, body]`, or null to leave the connection hanging (for a timeout).
 */
final class ScriptedServer
{
    /** @var list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<resource> */
    private array $open = [];

    /** @var list<string> */
    private array $watchers = [];

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
     * @param Closure(array{method: string, path: string, headers: array<string, string>, body: string}): (array{0: int, 1: array<string, string>, 2: string}|null) $answer
     * @return string base URL, no trailing slash
     */
    public function start(Closure $answer): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening) use ($answer): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $buffer = '';
            $reader = null;
            $reader = Loop::get()->onReadable($connection, function ($peer) use ($answer, &$buffer, &$reader): void {
                $data = fread($peer, 65536);

                if ($data === false || $data === '') {
                    if (feof($peer)) {
                        Loop::get()->cancel((string) $reader);
                    }

                    return;
                }

                $buffer .= $data;
                $split = strpos($buffer, "\r\n\r\n");

                if ($split === false) {
                    return;
                }

                $head = substr($buffer, 0, $split);
                $lines = explode("\r\n", $head);
                $headers = [];

                foreach (array_slice($lines, 1) as $line) {
                    [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                    $headers[strtolower($name)] = $value;
                }

                $body = substr($buffer, $split + 4);

                if (strlen($body) < (int) ($headers['content-length'] ?? 0)) {
                    return;
                }

                Loop::get()->cancel((string) $reader);
                [$method, $target] = explode(' ', $lines[0]);
                $request = ['method' => $method, 'path' => (string) parse_url($target, PHP_URL_PATH), 'headers' => $headers, 'body' => $body];
                $this->requests[] = $request;
                $reply = $answer($request);

                if ($reply === null) {
                    return;
                }

                [$status, $replyHeaders, $replyBody] = $reply;
                $out = "HTTP/1.1 {$status} " . ($status < 400 ? 'OK' : 'Error') . "\r\n";

                foreach ($replyHeaders + ['content-type' => 'application/json'] as $name => $value) {
                    $out .= "{$name}: {$value}\r\n";
                }

                fwrite($peer, $out . 'content-length: ' . strlen($replyBody) . "\r\nconnection: close\r\n\r\n" . $replyBody);
                fclose($peer);
            });
            $this->watchers[] = (string) $reader;
        });

        $name = (string) stream_socket_get_name($server, false);

        return 'http://' . $name;
    }

    /** @return list<array<string, mixed>> the requests' bodies, decoded */
    public function bodies(): array
    {
        return array_map(static fn (array $request): array => (array) json_decode($request['body'], true), $this->requests);
    }
}
