<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** The run is over, with everything it added to the conversation. */
final readonly class AgentEndEvent implements HookEvent
{
    /** @param list<mixed> $messages what this run appended, not the whole conversation */
    public function __construct(public array $messages = [])
    {
    }

    public function type(): string
    {
        return 'agent_end';
    }
}
