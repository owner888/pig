<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** The agent finished all operations, retries, and settled to idle. */
final readonly class AgentSettledEvent implements HookEvent
{
    /** @param list<mixed> $messages */
    public function __construct(
        public array $messages = [],
        /** Whether the run ended because it was aborted, for example with Escape. */
        public bool $aborted = false,
    ) {
    }

    public function type(): string
    {
        return 'agent_settled';
    }
}
