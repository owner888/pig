<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** A streaming message finished and is complete. */
final readonly class MessageEndEvent implements HookEvent
{
    public function __construct(public mixed $message)
    {
    }

    public function type(): string
    {
        return 'message_end';
    }
}
