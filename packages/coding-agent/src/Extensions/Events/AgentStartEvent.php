<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** A run has begun. One per prompt, however many turns it takes. */
final readonly class AgentStartEvent implements ExtensionEvent
{
    public function type(): string
    {
        return 'agent_start';
    }
}
