<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;

/**
 * The read side of an AbortController: "has this been called off, and why".
 *
 * Named after the Web API upstream passes around, because it appears in nearly every
 * signature pi has — `agentLoop(prompts, context, config, signal)` — and keeping the
 * name keeps those signatures diffable. `Cancellation` would read more like PHP.
 *
 * Aborting is one-way and idempotent: once aborted a signal never goes back, and a
 * listener attached afterwards still fires.
 */
final class AbortSignal
{
    /** @internal Construct via AbortController. */
    public function __construct(private readonly AbortState $state)
    {
    }

    /** A signal that will never abort — for calls that cannot be cancelled. */
    public static function never(): self
    {
        return new self(new AbortState());
    }

    public function aborted(): bool
    {
        return $this->state->isAborted();
    }

    public function reason(): string
    {
        return $this->state->reason();
    }

    /**
     * Stop here if the work has been called off.
     *
     * Call it at every point where continuing would waste effort — before a request,
     * between tool calls, around a loop.
     */
    public function throwIfAborted(): void
    {
        if ($this->state->isAborted()) {
            throw new AbortError($this->state->reason());
        }
    }

    /**
     * Run $listener when the abort comes, or on the next tick if it already has.
     *
     * @param Closure(string): void $listener receives the reason
     * @return string id for removeListener()
     */
    public function onAbort(Closure $listener): string
    {
        return $this->state->listen($listener);
    }

    public function removeListener(string $id): void
    {
        $this->state->unlisten($id);
    }
}
