<?php

declare(strict_types=1);

namespace Pig\Ai;

/** What one request cost, in dollars, broken out the same way tokens are. */
final readonly class Cost
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public float $total = 0.0,
    ) {
    }
}
