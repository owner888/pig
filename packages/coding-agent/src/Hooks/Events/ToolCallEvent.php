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
 *
 * **`$input` is writable, and the tool runs with whatever the hooks left in it.** That is
 * upstream's rule — *"To modify arguments, mutate `event.input` in place"* — and the whole of
 * how its permission gate turns `rm -rf build` into a move to the trash without blocking
 * anything. It was `readonly` here, so a hook that rewrote the command rewrote a copy, and
 * `rm` ran as typed.
 */
final class ToolCallEvent implements HookEvent
{
    /** @param array<string, mixed> $input the arguments, already validated against the schema */
    public function __construct(
        public readonly string $toolName,
        public readonly string $toolCallId,
        public array $input,
    ) {
    }

    public function type(): string
    {
        return 'tool_call';
    }
}
