<?php

declare(strict_types=1);

namespace Pig\Agent;

/** A long-running tool reported progress. Only tools that call `$onUpdate` emit this. */
final readonly class ToolExecutionUpdateEvent implements AgentEvent
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public AgentToolResult $partialResult,
    ) {
    }
}
