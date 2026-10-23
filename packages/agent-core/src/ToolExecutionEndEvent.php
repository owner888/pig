<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * A tool finished. `isError` covers a tool that threw and one that was skipped.
 * `parentToolCallId` as on `ToolExecutionStartEvent`.
 */
final readonly class ToolExecutionEndEvent implements AgentEvent
{
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public AgentToolResult $result,
        public bool $isError,
        /** Milliseconds `execute()` took, measured with a monotonic clock; null when the tool did not run. */
        public ?int $durationMs = null,
        public ?string $parentToolCallId = null,
    ) {
    }
}
