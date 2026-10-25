<?php

declare(strict_types=1);

namespace Pig\Async;

use Fiber;
use Throwable;

/**
 * A host name turned into an address without holding the loop.
 *
 * `stream_socket_client()` with `STREAM_CLIENT_ASYNC_CONNECT` makes the TCP handshake
 * non-blocking but not the lookup before it: `getaddrinfo()` runs on the one thread, and a slow
 * or unreachable DNS server stopped everything — keys, Escape, the spinner — for as long as it
 * took (`PIG_STALL_LOG` caught seven seconds, and thirty at the connect timeout). PHP has no
 * asynchronous resolver, so the lookup is a child process: `php -r gethostbynamel()`, read on
 * the loop. It is the system's resolver all the same, so `/etc/hosts`, a VPN's split DNS and
 * whatever else the machine resolves through still apply.
 *
 * - **Cached** for five minutes per host, so only the first request to a host pays the child's
 *   start (some milliseconds, spent on the loop rather than in it). Two requests to the same
 *   host at once share one lookup.
 * - **An address literal**, `localhost`, and a call outside a coroutine (a script, a test) are
 *   handed back as they are, for `stream_socket_client()` to resolve as before.
 * - **A lookup that gives no IPv4 address** — an IPv6-only host, a name that does not exist — is
 *   handed back as the name too, so the connect resolves it the old way and reports what it
 *   always reported. A lookup that does not answer in time is an error, not a fallback: the
 *   fallback would be the very wait this exists to keep off the loop.
 */
final class Dns
{
    /** How long a looked-up address is used before it is asked for again, in seconds. */
    private const float TTL = 300.0;

    /** @var array<string, array{0: string, 1: float}> host => address, and when it stops being used */
    private static array $cache = [];

    /** @var array<string, Future<string>> host => the lookup in flight */
    private static array $pending = [];

    /**
     * The address to connect to for $host: an IPv4 address, or $host itself where the connect
     * should resolve it (see the class docblock).
     *
     * @throws SocketError when the lookup does not answer within $timeout
     * @throws AbortError when $signal is raised first
     */
    public static function resolve(string $host, float $timeout = 30.0, ?AbortSignal $signal = null): string
    {
        if (self::isLiteral($host) || strtolower($host) === 'localhost' || Fiber::getCurrent() === null) {
            return $host;
        }

        $key = strtolower($host);
        $cached = self::$cache[$key] ?? null;

        if ($cached !== null && $cached[1] > microtime(true)) {
            return $cached[0];
        }

        $lookup = self::$pending[$key] ??= self::start($host, $timeout);

        return self::wait($lookup, $host, $timeout, $signal);
    }

    /** For a test: forget every address looked up. */
    public static function forget(): void
    {
        self::$cache = [];
        self::$pending = [];
    }

    private static function isLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The child, started, and a future for what it prints: the first IPv4 address, or $host when
     * there is none (or no child could be started).
     *
     * @return Future<string>
     */
    private static function start(string $host, float $timeout): Future
    {
        $key = strtolower($host);
        $deferred = new Deferred();
        $script = '$a = gethostbynamel($argv[1]); echo $a === false ? "" : $a[0];';
        $process = @proc_open([PHP_BINARY, '-n', '-d', 'display_errors=0', '-r', $script, $host], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        $finish = static function (string|Throwable $answer) use ($deferred, $key): void {
            unset(self::$pending[$key]);

            if ($answer instanceof Throwable) {
                $deferred->error($answer);

                return;
            }

            if ($answer !== '' && filter_var($answer, FILTER_VALIDATE_IP) !== false) {
                self::$cache[$key] = [$answer, microtime(true) + self::TTL];
            }

            $deferred->complete($answer);
        };

        if (!is_resource($process)) {
            $finish('');

            return $deferred->future;
        }

        stream_set_blocking($pipes[1], false);
        $loop = Loop::get();
        $output = '';
        $timer = null;
        $watcher = null;

        $close = static function () use ($process, $pipes): void {
            foreach ([1, 2] as $fd) {
                if (is_resource($pipes[$fd])) {
                    fclose($pipes[$fd]);
                }
            }

            proc_close($process);
        };

        $watcher = $loop->onReadable($pipes[1], static function () use ($pipes, &$output, &$watcher, &$timer, $loop, $close, $finish): void {
            $chunk = fread($pipes[1], 4096);

            if (is_string($chunk) && $chunk !== '') {
                $output .= $chunk;
            }

            if (!feof($pipes[1])) {
                return;
            }

            $loop->cancel((string) $watcher);
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            // The child has closed its output, which it does only on its way out.
            $close();
            $finish(trim($output));
        });

        $timer = $loop->delay($timeout, static function () use ($process, &$watcher, $loop, $close, $finish, $host, $timeout): void {
            $loop->cancel((string) $watcher);
            proc_terminate($process, 9);
            $close();
            $finish(new SocketError(sprintf('Cannot connect to %s: looking up the address took longer than %.1fs', $host, $timeout)));
        });

        return $deferred->future;
    }

    /**
     * This caller's wait for the shared lookup: its own timeout and signal, so one caller giving
     * up does not end the lookup for another.
     *
     * @param Future<string> $lookup
     */
    private static function wait(Future $lookup, string $host, float $timeout, ?AbortSignal $signal): string
    {
        $signal?->throwIfAborted();

        if ($lookup->isComplete()) {
            return self::answer($lookup->await(), $host);
        }

        $loop = Loop::get();
        $mine = new Deferred();
        $timer = null;
        $listener = null;

        $settle = static function (?Throwable $error, ?string $answer = null) use ($mine, $loop, $signal, &$timer, &$listener): void {
            if ($mine->isComplete()) {
                return;
            }

            if ($timer !== null) {
                $loop->cancel($timer);
            }

            if ($listener !== null && $signal !== null) {
                $signal->removeListener($listener);
            }

            $error !== null ? $mine->error($error) : $mine->complete($answer);
        };

        $lookup->onComplete(static fn (?Throwable $error, mixed $address) => $settle($error, is_string($address) ? $address : null));

        if ($timeout > 0.0) {
            $timer = $loop->delay($timeout, static fn () => $settle(new SocketError(sprintf('Cannot connect to %s: looking up the address took longer than %.1fs', $host, $timeout))));
        }

        if ($signal !== null) {
            $listener = $signal->onAbort(static fn (string $reason) => $settle(new AbortError($reason)));
        }

        return self::answer($mine->future->await(), $host);
    }

    private static function answer(mixed $address, string $host): string
    {
        return is_string($address) && $address !== '' ? $address : $host;
    }
}
