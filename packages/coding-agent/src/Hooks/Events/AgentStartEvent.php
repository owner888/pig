<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** A run has begun. One per prompt, however many turns it takes. */
final readonly class AgentStartEvent implements HookEvent
{
    public function type(): string
    {
        return 'agent_start';
    }
}
