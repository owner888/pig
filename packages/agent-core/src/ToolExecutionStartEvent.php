<?php

declare(strict_types=1);

namespace Pig\Agent;

/** A tool is about to run. */
final readonly class ToolExecutionStartEvent implements AgentEvent
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
    ) {
    }
}
