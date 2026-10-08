<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What a model charges, in dollars per million tokens.
 *
 * Kept apart from Cost, which is dollars actually spent. Upstream gives both the same
 * inline shape and tells them apart by where they sit; two names is cheaper than that.
 */
final readonly class Pricing
{
    /**
     * @param list<PricingTier> $tiers upstream's `ModelCost.tiers`: "Request-wide pricing tiers. The
     *        highest matching input threshold applies to the full request." Empty for a flat price.
     */
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public array $tiers = [],
    ) {
    }

    /**
     * The rates one request is billed at — the first half of upstream's `calculateCost()`.
     *
     * The input is everything the request read (`input + cacheRead + cacheWrite`), and the tier
     * whose `inputTokensAbove` is the highest one that input **exceeds** prices the whole request;
     * at or below every threshold, the base rates do. A tier is a `PricingTier`, the base is this,
     * and both carry the same four rates.
     */
    public function ratesFor(int $inputTokens): self|PricingTier
    {
        $rates = $this;
        $matchedThreshold = -1;

        foreach ($this->tiers as $tier) {
            if ($inputTokens > $tier->inputTokensAbove && $tier->inputTokensAbove > $matchedThreshold) {
                $rates = $tier;
                $matchedThreshold = $tier->inputTokensAbove;
            }
        }

        return $rates;
    }
}
