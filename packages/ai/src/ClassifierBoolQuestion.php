<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierBoolQuestion`: yes or no, `criteria: {true, false}`. */
final readonly class ClassifierBoolQuestion
{
    /** @param array{true: string, false: string} $criteria */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {
    }
}
