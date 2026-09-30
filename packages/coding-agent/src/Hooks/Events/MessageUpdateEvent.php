<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** A streaming message received a chunk or delta. */
final readonly class MessageUpdateEvent implements HookEvent
{
    public function __construct(
        public mixed $message,
        public mixed $delta = null,
    ) {
    }

    public function type(): string
    {
        return 'message_update';
    }
}
