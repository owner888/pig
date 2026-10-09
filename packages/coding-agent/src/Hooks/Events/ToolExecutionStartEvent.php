<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * A tool call is about to run — after `tool_call` let it through, before it has produced
 * anything. Upstream's `tool_execution_start`; a hook that only watches (a progress line, a
 * timer per tool) wants this rather than `tool_call`, which is a question and not a report.
 *
 * `parentToolCallId` is set for a call another tool made while it ran (a codemode script's
 * `$tools->read()`); the call's own id is then `<parent>/<n>`. The same is true of
 * `ToolExecutionUpdateEvent` and `ToolExecutionEndEvent`.
 *
 * @param array<string, mixed> $args
 */
final readonly class ToolExecutionStartEvent implements HookEvent
{
    /** @param array<string, mixed> $args */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public ?string $parentToolCallId = null,
    ) {
    }

    public function type(): string
    {
        return 'tool_execution_start';
    }
}
