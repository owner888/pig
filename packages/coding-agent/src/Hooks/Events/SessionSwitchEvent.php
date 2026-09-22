<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** The switch happened. The previous file is named so a hook can go back and read it. */
final readonly class SessionSwitchEvent implements HookEvent
{
    /** @param 'new'|'resume' $reason */
    public function __construct(
        public string $reason,
        public ?string $previousSessionFile = null,
    ) {
    }

    public function type(): string
    {
        return 'session_switch';
    }
}
