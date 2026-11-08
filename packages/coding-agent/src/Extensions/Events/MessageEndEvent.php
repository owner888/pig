<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** A streaming message finished and is complete. */
final readonly class MessageEndEvent implements ExtensionEvent
{
    public function __construct(public mixed $message)
    {
    }

    public function type(): string
    {
        return 'message_end';
    }
}
