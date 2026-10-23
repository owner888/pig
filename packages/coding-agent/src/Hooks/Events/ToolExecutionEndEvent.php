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
        /** Milliseconds `execute()` took, measured with a monotonic clock; null when the tool did not run. */
        public ?int $durationMs = null,
        /** Set when another tool (for example a codemode script) made this call. */
        public ?string $parentToolCallId = null,
    ) {
    }

    public function type(): string
    {
        return 'tool_execution_end';
    }
}
