<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * About to leave this conversation for another one — `/new` or `/resume`.
 *
 * Cancellable: a handler returning a `SessionBeforeSwitchResult` with `cancel` stops it,
 * which is how a hook refuses to throw away work that has not been written down.
 */
final readonly class SessionBeforeSwitchEvent implements HookEvent
{
    /** @param 'new'|'resume' $reason */
    public function __construct(
        public string $reason,
        public ?string $targetSessionFile = null,
    ) {
    }

    public function type(): string
    {
        return 'session_before_switch';
    }
}
