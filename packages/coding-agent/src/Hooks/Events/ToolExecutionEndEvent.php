<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Agent\AgentToolResult;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A tool call finished. Upstream's `tool_execution_end`. Unlike `tool_result` this cannot
 * change anything — it reports what the model is about to be shown, edits included.
 */
final readonly class ToolExecutionEndEvent implements HookEvent
{
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public AgentToolResult $result,
        public bool $isError,
    ) {
    }

    public function type(): string
    {
        return 'tool_execution_end';
    }
}
