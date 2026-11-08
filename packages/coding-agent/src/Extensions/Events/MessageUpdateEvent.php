<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** A streaming message received a chunk or delta. */
final readonly class MessageUpdateEvent implements ExtensionEvent
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
