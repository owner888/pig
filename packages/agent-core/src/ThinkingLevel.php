<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\ReasoningEffort;

/**
 * How hard the agent should think, as a UI setting.
 *
 * One level apart from `ReasoningEffort`: this is what a person toggles, that is what a
 * provider is told. They almost line up, and the place they do not is deliberate —
 * upstream sends `minimal` as `low`, because no provider treats "barely think" usefully.
 */
enum ThinkingLevel: string
{
    case Off = 'off';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';

    public function toReasoning(): ?ReasoningEffort
    {
        return match ($this) {
            self::Off => null,
            self::Minimal => ReasoningEffort::Low,
            self::Low => ReasoningEffort::Low,
            self::Medium => ReasoningEffort::Medium,
            self::High => ReasoningEffort::High,
            self::Xhigh => ReasoningEffort::Xhigh,
        };
    }
}
