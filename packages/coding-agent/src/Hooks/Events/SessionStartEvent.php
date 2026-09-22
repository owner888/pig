<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** The session is up and the UI is about to take over. Fired once. */
final readonly class SessionStartEvent implements HookEvent
{
    public function type(): string
    {
        return 'session_start';
    }
}
