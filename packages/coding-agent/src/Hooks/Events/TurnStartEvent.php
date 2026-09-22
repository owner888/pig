<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** One round trip to the model is beginning. */
final readonly class TurnStartEvent implements HookEvent
{
    public function __construct(public int $turnIndex = 0)
    {
    }

    public function type(): string
    {
        return 'turn_start';
    }
}
