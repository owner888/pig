<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;

/**
 * The cell an AbortSignal reads and an AbortController writes.
 *
 * Same split as FutureState: holding a signal must not confer the power to abort it.
 *
 * @internal
 */
final class AbortState
{
    private bool $aborted = false;

    private string $reason = '';

    private int $nextId = 0;

    /** @var array<string, Closure(string): void> */
    private array $listeners = [];

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @param Closure(string): void $listener */
    public function listen(Closure $listener): string
    {
        $id = 'a' . $this->nextId++;

        if ($this->aborted) {
            // Late subscriber: fire it anyway, so a listener attached after the fact
            // cannot silently miss the abort it exists to handle.
            Loop::get()->defer(fn () => $listener($this->reason));

            return $id;
        }

        $this->listeners[$id] = $listener;

        return $id;
    }

    public function unlisten(string $id): void
    {
        unset($this->listeners[$id]);
    }

    public function abort(string $reason): void
    {
        if ($this->aborted) {
            return;
        }

        $this->aborted = true;
        $this->reason = $reason;

        $listeners = $this->listeners;
        $this->listeners = [];

        foreach ($listeners as $listener) {
            // Deferred for the same reason future callbacks are: a listener that resumes
            // a fiber must not run inside the aborting caller's stack.
            Loop::get()->defer(static fn () => $listener($reason));
        }
    }
}
