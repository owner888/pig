<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;
use Throwable;

/**
 * The mutable cell a Future reads and a Deferred writes.
 *
 * Split out so Future stays read-only to its consumer: handing someone a Future
 * must not hand them the ability to complete it.
 *
 * State is private with readers on top. PHP 8.4 would express this as
 * `public private(set)` and drop the readers, but this package targets 8.3.
 *
 * @internal
 */
final class FutureState
{
    private bool $complete = false;

    private mixed $result = null;

    private ?Throwable $error = null;

    private bool $observed = false;

    /** @var list<Closure(?Throwable, mixed): void> */
    private array $callbacks = [];

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    /** True once anyone has awaited this or attached a callback — see Async::run(). */
    public function isObserved(): bool
    {
        return $this->observed;
    }

    public function complete(mixed $result): void
    {
        $this->assertPending();
        $this->complete = true;
        $this->result = $result;
        $this->invoke();
    }

    /** Named fail() rather than error() so it does not collide with the reader above. */
    public function fail(Throwable $error): void
    {
        $this->assertPending();
        $this->complete = true;
        $this->error = $error;
        $this->invoke();
    }

    public function markObserved(): void
    {
        $this->observed = true;
    }

    /** @param Closure(?Throwable, mixed): void $callback */
    public function onComplete(Closure $callback): void
    {
        $this->observed = true;

        if ($this->complete) {
            Loop::get()->defer(fn () => $callback($this->error, $this->result));

            return;
        }

        $this->callbacks[] = $callback;
    }

    private function assertPending(): void
    {
        if ($this->complete) {
            throw new AsyncError('Future is already complete');
        }
    }

    private function invoke(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        foreach ($callbacks as $callback) {
            // Never synchronously: a callback that resumes a Fiber must run after the
            // fiber has suspended, and a callback must not run inside the completer's stack.
            Loop::get()->defer(fn () => $callback($this->error, $this->result));
        }
    }
}
