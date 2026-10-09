<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * A tool is about to run.
 *
 * `parentToolCallId` is set for a call another tool made while it ran (a codemode script's
 * `$tools->read()`), upstream's nested `tool_execution_*` events: the call's own id is then
 * `<parent>/<n>`, and a UI that draws one row per model-issued call leaves these out.
 */
final readonly class ToolExecutionStartEvent implements AgentEvent
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public ?string $parentToolCallId = null,
    ) {
    }
}
