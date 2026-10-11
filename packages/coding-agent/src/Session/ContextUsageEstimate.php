<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * How big a context is, and how much of that is a guess — upstream's `ContextUsageEstimate`
 * (`compaction/compaction.ts`).
 *
 * `$usageTokens` is the provider's own count from the last usable assistant message and
 * `$trailingTokens` the estimate for everything after it; with no usable count the whole of
 * `$tokens` is estimated and `$lastUsageIndex` is null.
 */
final readonly class ContextUsageEstimate
{
    public function __construct(
        public int $tokens,
        public int $usageTokens,
        public int $trailingTokens,
        public ?int $lastUsageIndex,
    ) {
    }
}
