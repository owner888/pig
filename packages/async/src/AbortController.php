<?php

declare(strict_types=1);

namespace Pig\Async;

/**
 * The write side: whoever holds the controller decides when to call the work off.
 *
 * The agent holds one per run; Esc in the TUI calls abort() on it, and the in-flight
 * request, the tool being executed and the loop itself all see it through the signal
 * they were handed.
 */
final class AbortController
{
    public readonly AbortSignal $signal;

    private readonly AbortState $state;

    public function __construct()
    {
        $this->state = new AbortState();
        $this->signal = new AbortSignal($this->state);
    }

    /** Idempotent: the first reason is the one that sticks. */
    public function abort(string $reason = 'Aborted'): void
    {
        $this->state->abort($reason);
    }
}
