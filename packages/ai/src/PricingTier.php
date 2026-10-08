<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * One request-wide price tier — upstream's `ModelCostTier`: the four rates, used for a whole
 * request whose input exceeds `$inputTokensAbove`.
 */
final readonly class PricingTier
{
    public function __construct(
        public int $inputTokensAbove,
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
    ) {
    }
}
