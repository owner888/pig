<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * How much thinking to ask a reasoning model for.
 *
 * Upstream's `ThinkingLevel` without `off`. `xhigh` and `max` exist only on the models whose
 * `thinkingLevelMap` names them; a provider that thinks on a token budget spends `high`'s on both.
 */
enum ReasoningEffort: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
    case Max = 'max';

    /** Upstream's `clampReasoning()`: `xhigh` and `max` are `high`. */
    public function clampToHigh(): self
    {
        return $this === self::Xhigh || $this === self::Max ? self::High : $this;
    }
}
