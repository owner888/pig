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
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
    ) {
    }
}
