<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\Agent\ThinkingLevel;
use Pig\CodingAgent\Extensions\ExtensionEvent;

/** The thinking level changed. Upstream's `thinking_level_select`. */
final readonly class ThinkingLevelSelectEvent implements ExtensionEvent
{
    public function __construct(public ThinkingLevel $level, public ?ThinkingLevel $previous = null)
    {
    }

    public function type(): string
    {
        return 'thinking_level_select';
    }
}
