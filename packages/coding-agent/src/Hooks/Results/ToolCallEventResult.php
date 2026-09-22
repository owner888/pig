<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * What a `tool_call` handler may say about a call it has seen.
 *
 * `block` stops the tool and `reason` is what the model is told in its place. The
 * default reason is deliberately plain: a hook that blocks without saying why leaves the
 * model guessing, and a model that guesses tries the same thing again.
 */
final readonly class ToolCallEventResult
{
    public function __construct(
        public bool $block = false,
        public ?string $reason = null,
    ) {
    }

    /** What the model is shown when this blocks. */
    public function message(): string
    {
        return $this->reason ?? 'Tool execution was blocked by a hook';
    }
}
