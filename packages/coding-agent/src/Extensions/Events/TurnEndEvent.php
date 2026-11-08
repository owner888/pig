<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\Ai\AssistantMessage;
use Pig\Ai\ToolResultMessage;
use Pig\CodingAgent\Extensions\ExtensionEvent;

/** The model answered, and its tools have run. */
final readonly class TurnEndEvent implements ExtensionEvent
{
    /** @param list<ToolResultMessage> $toolResults */
    public function __construct(
        public AssistantMessage $message,
        public array $toolResults = [],
        public int $turnIndex = 0,
    ) {
    }

    public function type(): string
    {
        return 'turn_end';
    }
}
