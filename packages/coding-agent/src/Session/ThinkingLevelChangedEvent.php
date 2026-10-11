<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;
use Pig\Agent\ThinkingLevel;

/**
 * The thinking level moved — upstream's `thinking_level_changed`, from `setThinkingLevel()`.
 *
 * Only when it actually changed, as upstream announces it beside the `thinking_level_change` entry
 * it writes: setting the level it already was is not news.
 */
final readonly class ThinkingLevelChangedEvent implements AgentEvent
{
    public function __construct(
        public ThinkingLevel $level,
    ) {
    }
}
