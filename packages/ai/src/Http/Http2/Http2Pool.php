<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

/**
 * The HTTP/2 connections left open, by origin: one is started on the first request to a host and
 * every later request, from any client in the process, goes over it while it lasts.
 *
 * Process-wide for `HttpClient::$default`'s reason — a client is built with `new HttpClient()` in a
 * dozen places and none of them is handed a pool.
 *
 * @internal
 */
final class Http2Pool
{
    /** @var array<string, list<Http2Connection>> */
    private static array $connections = [];

    /** A connection to $origin that can take another request, or null. */
    public static function acquire(string $origin): ?Http2Connection
    {
        $usable = [];
        $found = null;

        foreach (self::$connections[$origin] ?? [] as $connection) {
            if ($connection->isUsable()) {
                $usable[] = $connection;
                $found ??= $connection;
            }
        }

        // One that is not usable is already closed or is going away; what is in flight on it finishes.
        self::$connections[$origin] = $usable;

        return $found;
    }

    public static function add(string $origin, Http2Connection $connection): void
    {
        self::$connections[$origin][] = $connection;
    }

    /** Close every connection, and forget them. */
    public static function clear(): void
    {
        foreach (self::$connections as $connections) {
            foreach ($connections as $connection) {
                $connection->close();
            }
        }

        self::$connections = [];
    }
}
