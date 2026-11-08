<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** An assistant or system message started streaming. */
final readonly class MessageStartEvent implements ExtensionEvent
{
    public function __construct(public mixed $message)
    {
    }

    public function type(): string
    {
        return 'message_start';
    }
}
