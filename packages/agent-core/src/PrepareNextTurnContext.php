<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\AssistantMessage;
use Pig\Ai\ToolResultMessage;

/**
 * What `AgentLoopConfig::$prepareNextTurn` is told about the turn that just completed — upstream's
 * `PrepareNextTurnContext` (its `AgentTurnContext`).
 */
final readonly class PrepareNextTurnContext
{
    /**
     * @param AssistantMessage        $message     the assistant message that completed the turn
     * @param list<ToolResultMessage> $toolResults tool result messages emitted for the turn
     * @param AgentContext            $context     the agent context after the turn's assistant
     *        message and tool results were appended
     * @param list<mixed>             $newMessages messages this loop invocation would return if it
     *        exited now
     */
    public function __construct(
        public AssistantMessage $message,
        public array $toolResults,
        public AgentContext $context,
        public array $newMessages,
    ) {
    }
}
