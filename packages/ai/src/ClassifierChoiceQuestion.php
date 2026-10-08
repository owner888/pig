<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ClassifierChoiceQuestion`: one of several named answers. */
final readonly class ClassifierChoiceQuestion
{
    /** @param array<string, string> $criteria answer key => what it means */
    public function __construct(
        public string $instructions,
        public array $criteria,
    ) {
    }
}
