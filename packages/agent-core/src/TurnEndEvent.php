<?php

declare(strict_types=1);

namespace Pig\Agent;

/** The assistant response and its tool results are all in. */
final readonly class TurnEndEvent implements AgentEvent
{
    /**
     * @param mixed                           $message     the assistant message that drove this turn
     * @param list<\Pig\Ai\ToolResultMessage> $toolResults
     */
    public function __construct(
        public mixed $message,
        public array $toolResults,
    ) {
    }
}
