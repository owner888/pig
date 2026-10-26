<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * A terminal whose `write()` does not wait for the terminal to take the bytes.
 *
 * pig's own. What a write leaves behind is written as the terminal reads, from the event loop, so a
 * frame of several megabytes that a slow terminal takes seconds to parse no longer holds the loop —
 * keys are read, Escape stops a turn and the spinner turns while it drains. The renderer asks
 * `hasPendingOutput()` before drawing and waits for `whenDrained()` rather than piling frames up
 * behind it: a frame is a diff against the one before it, so none can be dropped, and drawing
 * once the terminal has caught up draws everything that changed meanwhile in one frame.
 */
interface BufferedTerminal
{
    /** Whether bytes written are still waiting for the terminal to take them. */
    public function hasPendingOutput(): bool;

    /** Call $callback once everything written so far has been taken — on the loop, never inside the caller. */
    public function whenDrained(Closure $callback): void;

    /**
     * Write out whatever is waiting, waiting for the terminal as long as it takes — for the moments
     * that need it on the screen now: stopping, suspending, handing the terminal to another program.
     *
     * @throws TuiError when the terminal stops taking bytes altogether
     */
    public function flush(): void;
}
