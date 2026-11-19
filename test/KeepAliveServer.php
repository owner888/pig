<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback HTTP/1.1 server that keeps its connections open and answers every request on them.
 *
 * `CannedServer` answers once per connection, which is the one thing a test of connection reuse
 * cannot use. The closure gets the request (`method`, `path`, lowercased `headers`, `body`) and
 * answers the raw bytes of the response; null leaves the request unanswered.
 */
final class KeepAliveServer
{
    /** @var list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public int $connections = 0;

    /** @var list<resource> */
    private array $open = [];

    /** @var list<array{0: resource, 1: string}> the connections, each with its watcher */
    private array $peers = [];

    /** @var list<string> every watcher put on the loop, so each can come off before its stream goes */
    private array $watchers = [];

    /** @param Closure(array{method: string, path: string, headers: array<string, string>, body: string}): ?string $answer */
    public function __construct(private readonly Closure $answer)
    {
    }

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
     * @param bool $tls speak it over TLS, offering no application protocol, as a server that does not do HTTP/2 does
     * @return string the base URL, with a trailing slash
     */
    public function start(bool $tls = false): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create());

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening) use ($tls): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;

            if ($tls) {
                $this->watchers[] = TlsCertificate::handshake($connection, null, fn ($peer) => $this->serve($peer));

                return;
            }

            $this->serve($connection);
        });

        $name = (string) stream_socket_get_name($server, false);

        return ($tls ? 'https://' : 'http://') . $name . '/';
    }

    /** @param resource $connection */
    private function serve(mixed $connection): void
    {
        $this->connections++;
        $buffer = '';
        $watcher = null;

        $watcher = Loop::get()->onReadable($connection, function ($peer) use (&$buffer, &$watcher): void {
            // To the end of what is there: TLS holds the rest of a record where the loop cannot see it.
            $data = '';

            while (($piece = fread($peer, 65536)) !== false && $piece !== '') {
                $data .= $piece;
            }

            if ($data === '') {
                if (feof($peer)) {
                    Loop::get()->cancel((string) $watcher);
                }

                return;
            }

            $buffer .= $data;

            while (($split = strpos($buffer, "\r\n\r\n")) !== false) {
                $lines = explode("\r\n", substr($buffer, 0, $split));
                $headers = [];

                foreach (array_slice($lines, 1) as $line) {
                    [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                    $headers[strtolower($name)] = $value;
                }

                $length = (int) ($headers['content-length'] ?? 0);

                if (strlen($buffer) < $split + 4 + $length) {
                    return;
                }

                [$method, $path] = explode(' ', $lines[0]);
                $request = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => substr($buffer, $split + 4, $length)];
                $buffer = substr($buffer, $split + 4 + $length);
                $this->requests[] = $request;
                $reply = ($this->answer)($request);

                if ($reply !== null) {
                    fwrite($peer, $reply);
                }
            }
        });
        $this->watchers[] = $watcher;
        $this->peers[] = [$connection, (string) $watcher];
    }

    /** Close every connection that is open, as a server does with one that sat idle too long. */
    public function hangUpOnAll(): void
    {
        foreach ($this->peers as $peer) {
            Loop::get()->cancel($peer[1]);
            fclose($peer[0]);
        }

        $this->peers = [];
    }

    /** A complete response with its length given. */
    public static function response(string $body, int $status = 200, string $headers = ''): string
    {
        return "HTTP/1.1 {$status} OK\r\ncontent-length: " . strlen($body) . "\r\n{$headers}\r\n" . $body;
    }
}
