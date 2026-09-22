<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;
use Fiber;
use Throwable;

/**
 * Entry point into coroutine land.
 *
 * Nothing may await outside a coroutine, so every pig program starts with
 * Async::run(fn () => ...) and everything below that line can await freely.
 */
final class Async
{
    /** @var list<Future<mixed>> */
    private static array $spawned = [];

    /**
     * Run $main as the root coroutine and drive the loop until it returns.
     *
     * @template T
     * @param Closure(): T $main
     * @return T
     */
    public static function run(Closure $main): mixed
    {
        $loop = Loop::get();
        $spawnedBefore = self::$spawned;
        self::$spawned = [];

        // wake() on the way out: the fiber finishes inside a tick's callback phase, and
        // without it that same tick goes on to poll — blocking on watchers that only the
        // finished coroutine cared about.
        $fiber = new Fiber(static function () use ($main, $loop): mixed {
            try {
                return $main();
            } finally {
                $loop->wake();
            }
        });

        try {
            $fiber->start();

            while (!$fiber->isTerminated()) {
                if ($loop->isIdle()) {
                    throw new AsyncError(
                        'The event loop ran out of work while the root coroutine was still suspended — '
                        . 'something awaited a Future that nothing will ever complete',
                    );
                }

                $loop->tick();
            }

            // A coroutine nobody awaited may have died quietly; surface it instead of losing it.
            foreach (self::$spawned as $future) {
                $error = $future->unobservedError();

                if ($error !== null) {
                    throw $error;
                }
            }

            return $fiber->getReturn();
        } finally {
            self::$spawned = $spawnedBefore;
        }
    }

    /**
     * Start $fn as a concurrent coroutine and return its Future.
     *
     * @template T
     * @param Closure(): T $fn
     * @return Future<T>
     */
    public static function spawn(Closure $fn): Future
    {
        $deferred = new Deferred();

        $fiber = new Fiber(static function () use ($fn, $deferred): void {
            try {
                $deferred->complete($fn());
            } catch (Throwable $error) {
                $deferred->error($error);
            }
        });

        Loop::get()->defer(static fn () => $fiber->start());

        $future = $deferred->future;
        self::$spawned[] = $future;

        return $future;
    }

    /** Suspend the current coroutine for $seconds. */
    public static function delay(float $seconds): void
    {
        $deferred = new Deferred();
        Loop::get()->delay($seconds, static fn () => $deferred->complete(null));
        $deferred->future->await();
    }
}
