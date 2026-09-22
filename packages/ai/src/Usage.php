<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Token counts for one request, and what they cost. */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $totalTokens = 0,
        public Cost $cost = new Cost(),
    ) {
    }
}
