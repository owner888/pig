<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierScoreAnswer`. */
final readonly class ClassifierScoreAnswer
{
    public function __construct(
        public float $score,
        public float $confidence,
    ) {
    }
}
