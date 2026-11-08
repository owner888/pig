<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/**
 * The conversation, on its way to the model, before it becomes a request.
 *
 * **The conversation only**: upstream's handlers do not see the system messages — "the system
 * messages belong to Pi" — and whatever a handler returns gets the prompt and the tool state put
 * back in front of it (`ExtensionRunner::emitContext()`). `context_with_system` is the event that sees
 * them.
 *
 * The one event whose handlers are chained: each is given what the last one returned, so
 * two hooks can both edit the context without either having to know about the other.
 * What a handler returns is sent instead of what it was given, and nothing here changes
 * the conversation that is kept — only the copy this turn is built from.
 */
final readonly class ContextEvent implements ExtensionEvent
{
    /** @param list<mixed> $messages */
    public function __construct(public array $messages)
    {
    }

    public function type(): string
    {
        return 'context';
    }
}
