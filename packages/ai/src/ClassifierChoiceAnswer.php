<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierChoiceAnswer`. */
final readonly class ClassifierChoiceAnswer
{
    /** @param array<string, float> $probabilities answer key => probability */
    public function __construct(
        public string $choice,
        public array $probabilities,
        public float $confidence,
    ) {
    }
}
