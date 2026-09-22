<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * How much thinking to ask a reasoning model for.
 *
 * Xhigh is OpenAI-only; providers without it clamp to High.
 */
enum ReasoningEffort: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';

    public function clampToHigh(): self
    {
        return $this === self::Xhigh ? self::High : $this;
    }
}
