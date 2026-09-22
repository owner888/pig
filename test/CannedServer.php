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

    private string $received = '';

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

        Loop::get()->onReadable($server, function ($listening) use ($pieces, $closeAfter): void {
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
                    fwrite($peer, $piece);
                });
            }

            if ($closeAfter) {
                Loop::get()->delay(0.01 * (count($pieces) + 1), static function () use ($peer): void {
                    fclose($peer);
                });
            }
        });
    }
}
