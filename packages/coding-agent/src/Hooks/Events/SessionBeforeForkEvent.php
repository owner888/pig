<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * About to fork a branch of this conversation into a session file of its own — `/fork` or
 * `/clone`.
 *
 * `$entryId` is the point that was chosen, and `$position` says what the fork ends on:
 * `before` for `/fork`, where the chosen message is somebody's own and goes back into the
 * prompt to be asked differently, `at` for `/clone`, where the fork ends on the point itself.
 * Cancellable: a handler returning a `SessionBeforeForkResult` with `cancel` stops it.
 */
final readonly class SessionBeforeForkEvent implements HookEvent
{
    /** @param 'before'|'at' $position */
    public function __construct(
        public string $entryId,
        public string $position = 'before',
    ) {
    }

    public function type(): string
    {
        return 'session_before_fork';
    }
}
