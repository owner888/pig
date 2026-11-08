<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/** One round trip to the model is beginning. */
final readonly class TurnStartEvent implements ExtensionEvent
{
    public function __construct(public int $turnIndex = 0)
    {
    }

    public function type(): string
    {
        return 'turn_start';
    }
}
