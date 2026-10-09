<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Agent\AgentToolResult;
use Pig\CodingAgent\Hooks\HookEvent;

/** A running tool reported partial output — `bash` does, as its command prints. Upstream's `tool_execution_update`. */
final readonly class ToolExecutionUpdateEvent implements HookEvent
{
    /** @param array<string, mixed> $args */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public AgentToolResult $partialResult,
        public ?string $parentToolCallId = null,
    ) {
    }

    public function type(): string
    {
        return 'tool_execution_update';
    }
}
