<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * The whole transcript on its way to the model, system messages included — upstream's
 * `context_with_system`.
 *
 * Fired after every `context` handler has run. A `context` handler sees the conversation only and
 * pig puts the prompt and the tool state back after it; one of these sees everything, and what it
 * returns is sent as returned. Dropping the leading system message is reported, because the
 * request then has no prompt and no initial tool declarations, but honoured.
 */
final readonly class ContextWithSystemEvent implements HookEvent
{
    /** @param list<mixed> $messages */
    public function __construct(public array $messages)
    {
    }

    public function type(): string
    {
        return 'context_with_system';
    }
}
