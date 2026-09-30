<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** An assistant or system message started streaming. */
final readonly class MessageStartEvent implements HookEvent
{
    public function __construct(public mixed $message)
    {
    }

    public function type(): string
    {
        return 'message_start';
    }
}
