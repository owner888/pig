<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierBoolAnswer`: the probability of `true`. */
final readonly class ClassifierBoolAnswer
{
    public function __construct(
        public float $probability,
    ) {
    }
}
