<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;

/**
 * A model and how hard to think, as one answer.
 *
 * The two travel together because `--model sonnet:high` says both, and separating them
 * again at the call site is how one of them gets dropped.
 *
 * Upstream: `ScopedModel` plus the warning from `ParsedModelResult`, which it keeps apart
 * because a scope can hold several models. There is one here.
 */
final readonly class ModelChoice
{
    /** @param string|null $warning what was understood differently from what was typed */
    public function __construct(
        public Model $model,
        public ThinkingLevel $thinking = ThinkingLevel::Off,
        public ?string $warning = null,
    ) {
    }
}
