<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Async\Socket;

/**
 * The HTTP/1.1 connections left open after a response was read to its end, by origin, for the next
 * request to the same one — what undici's pool does for Node's `fetch`.
 *
 * Nothing watches an idle connection, so one the server closed meanwhile is found out when it is
 * taken (`acquire()` reads what is on it) and not before. A connection is kept for as long as the
 * server said it would keep it (`Keep-Alive: timeout=`), or undici's four seconds.
 *
 * Process-wide for `HttpClient::$default`'s reason.
 *
 * @internal
 */
final class Http1Pool
{
    /** undici's `keepAliveTimeout`, in seconds, when the server names none. */
    public const float DEFAULT_IDLE = 4.0;

    /** How many idle connections one origin keeps; the oldest goes first. */
    private const int MAX_IDLE = 8;

    /** @var array<string, list<array{0: Socket, 1: float}>> origin => [connection, the time it stops being kept], oldest first */
    private static array $idle = [];

    /** A connection to $origin that is still open and has nothing waiting on it, or null. */
    public static function acquire(string $origin): ?Socket
    {
        self::prune();

        while (isset(self::$idle[$origin]) && ($entry = array_pop(self::$idle[$origin])) !== null) {
            $socket = $entry[0];

            // Nothing is expected, so anything — the end of the stream, or a byte — means it is no good.
            if ($socket->readAvailable() === '') {
                return $socket;
            }

            $socket->close();
        }

        return null;
    }

    /** Keep $socket for $seconds. */
    public static function release(string $origin, Socket $socket, float $seconds): void
    {
        self::prune();
        self::$idle[$origin][] = [$socket, microtime(true) + $seconds];

        while (count(self::$idle[$origin]) > self::MAX_IDLE) {
            array_shift(self::$idle[$origin])[0]->close();
        }
    }

    /** Close every connection, and forget them. */
    public static function clear(): void
    {
        foreach (self::$idle as $entries) {
            foreach ($entries as [$socket]) {
                $socket->close();
            }
        }

        self::$idle = [];
    }

    private static function prune(): void
    {
        $now = microtime(true);

        foreach (self::$idle as $origin => $entries) {
            $kept = [];

            foreach ($entries as $entry) {
                if ($entry[1] > $now && !$entry[0]->isClosed()) {
                    $kept[] = $entry;
                } else {
                    $entry[0]->close();
                }
            }

            self::$idle[$origin] = $kept;
        }
    }
}
