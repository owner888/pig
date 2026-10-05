<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Agent\AgentEvent;
use Pig\CodingAgent\Hooks\HookEvent;

/** The session name / display title changed. */
final readonly class SessionInfoChangedEvent implements HookEvent, AgentEvent
{
    public function __construct(public string $name = '')
    {
    }

    public function type(): string
    {
        return 'session_info_changed';
    }
}
