<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * The model has asked for a tool, and it has not run yet.
 *
 * The event worth having: a handler returning a `ToolCallEventResult` with `block` stops
 * the call, and its `reason` is what the model is told instead — so a refusal is
 * something the model can read and work around rather than a run that ends.
 */
final readonly class ToolCallEvent implements HookEvent
{
    /** @param array<string, mixed> $input the arguments, already validated against the schema */
    public function __construct(
        public string $toolName,
        public string $toolCallId,
        public array $input,
    ) {
    }

    public function type(): string
    {
        return 'tool_call';
    }
}
