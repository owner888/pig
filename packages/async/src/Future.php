<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;
use Fiber;

/**
 * A value that will exist later. The read side of a Deferred.
 *
 * Stands in for the Promises upstream pi awaits. await() suspends the current
 * coroutine instead of returning a promise, so ported code keeps the shape of the
 * TypeScript it came from: `$x = f()->await();` reads like `const x = await f();`.
 *
 * @template T
 */
final class Future
{
    /** @internal Construct via Deferred::future(). */
    public function __construct(private readonly FutureState $state)
    {
    }

    /** A Future that is already complete. */
    public static function complete(mixed $value = null): self
    {
        $deferred = new Deferred();
        $deferred->complete($value);

        return $deferred->future;
    }

    public function isComplete(): bool
    {
        return $this->state->isComplete();
    }

    /**
     * Suspend this coroutine until the value arrives, then return it.
     *
     * @return T
     * @throws AsyncError when called outside a coroutine — use Async::run() or Async::spawn()
     */
    public function await(): mixed
    {
        $this->state->markObserved();

        if ($this->state->isComplete()) {
            if ($this->state->error() !== null) {
                throw $this->state->error();
            }

            return $this->state->result();
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new AsyncError('Future::await() must be called inside a coroutine; wrap the entry point in Async::run()');
        }

        $this->state->onComplete(static function (?\Throwable $error, mixed $result) use ($fiber): void {
            if ($error !== null) {
                $fiber->throw($error);

                return;
            }

            $fiber->resume($result);
        });

        return Fiber::suspend();
    }

    /** @param Closure(?\Throwable, T): void $callback */
    public function onComplete(Closure $callback): void
    {
        $this->state->onComplete($callback);
    }

    /**
     * The error nobody ever looked at, if there is one.
     *
     * @internal Async::run() uses this so a spawned coroutine cannot fail in silence.
     */
    public function unobservedError(): ?\Throwable
    {
        if ($this->state->isObserved()) {
            return null;
        }

        return $this->state->error();
    }
}
