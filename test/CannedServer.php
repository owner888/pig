<?php

declare(strict_types=1);

namespace Pig\Test;

use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback HTTP server that replays canned bytes, one piece every 10ms.
 *
 * Enough to exercise framing, streaming and aborts without reaching the network, and
 * the spacing is what makes "did this arrive in pieces" answerable at all.
 */
final class CannedServer
{
    /** @var list<resource> */
    private array $open = [];

    /**
     * Every watcher this server put on the loop, so each can come off before its stream goes away.
     *
     * **A stream closed while a watcher still points at it is a loop that trips on the next poll** —
     * `Reader r1 watches a closed stream` — and the listening socket used to be closed by nothing but
     * garbage collection, with its watcher still registered. So the failure landed in whichever
     * `Async::run()` came next, in a test that had done nothing wrong: `OverflowTest` builds a server
     * per call and never stops one, so the first one collected took down the following call. The rule
     * is this repository's own and is written down twice; a test helper is not exempt from it.
     *
     * @var list<string>
     */
    private array $watchers = [];

    private string $received = '';

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Take every watcher off the loop and close every socket, in that order.
     *
     * Idempotent, because `closeAfter` also closes the peer on a timer and the destructor runs
     * whether or not a test called this.
     */
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
     * @param list<string> $pieces
     * @param bool         $closeAfter false leaves the connection hanging, for abort tests
     * @return string base URL with a trailing slash
     */
    public function start(array $pieces, bool $closeAfter = true): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening) use ($pieces, $closeAfter): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $this->replyOnce($connection, $pieces, $closeAfter);
        });

        $name = (string) stream_socket_get_name($server, false);
        $separator = strrpos($name, ':');

        return 'http://' . substr($name, 0, (int) $separator) . ':' . substr($name, (int) $separator + 1) . '/';
    }

    /** Everything the client sent, head and body. */
    public function received(): string
    {
        return $this->received;
    }

    /** The request head alone, for asserting on headers. */
    public function receivedHead(): string
    {
        return (string) strstr($this->received, "\r\n\r\n", true);
    }

    /** @return array<string, mixed> the request body, decoded */
    public function receivedJson(): array
    {
        $body = strstr($this->received, "\r\n\r\n");
        $decoded = json_decode(substr((string) $body, 4), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param resource     $connection
     * @param list<string> $pieces
     */
    private function replyOnce(mixed $connection, array $pieces, bool $closeAfter): void
    {
        $reader = null;

        $reader = Loop::get()->onReadable($connection, function ($peer) use ($pieces, $closeAfter, &$reader): void {
            $data = fread($peer, 65536);

            if ($data === false || $data === '') {
                return;
            }

            $this->received .= $data;

            // Reply once the whole request head is in.
            if (!str_contains($this->received, "\r\n\r\n")) {
                return;
            }

            Loop::get()->cancel((string) $reader);

            foreach ($pieces as $index => $piece) {
                Loop::get()->delay(0.01 * ($index + 1), static function () use ($peer, $piece): void {
                    if (is_resource($peer)) {
                        fwrite($peer, $piece);
                    }
                });
            }

            if ($closeAfter) {
                Loop::get()->delay(0.01 * (count($pieces) + 1), static function () use ($peer): void {
                    if (is_resource($peer)) {
                        fclose($peer);
                    }
                });
            }
        });

        $this->watchers[] = (string) $reader;
    }
}
