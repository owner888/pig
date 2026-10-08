<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierScoreQuestion`: a level on a scale, the levels in order from 0. */
final readonly class ClassifierScoreQuestion
{
    /** @param list<string> $criteria what each level means, lowest first */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {
    }
}
