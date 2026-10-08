<?php

declare(strict_types=1);

namespace Pig\Ai;

use stdClass;

/**
 * What a classifier is asked about — upstream's `ClassifierContext`: the state to judge and the
 * questions, by id.
 *
 * The questions are a closed union of three arms, so they are a real union type here (see
 * "Porting the unions" in CLAUDE.md), held in an array keyed by the question's id.
 *
 * `state` is a JSON object: an array decoded with objects as arrays, or a `stdClass` where an empty
 * object has to stay `{}` on the wire — an empty PHP array is sent as `{}` at the top level.
 *
 * @phpstan-type ClassifierQuestion ClassifierChoiceQuestion|ClassifierScoreQuestion|ClassifierBoolQuestion
 */
final readonly class ClassifierContext
{
    /**
     * @param array<string, mixed>|stdClass $state
     * @param array<string, ClassifierChoiceQuestion|ClassifierScoreQuestion|ClassifierBoolQuestion> $questions
     */
    public function __construct(
        public array|stdClass $state,
        public array $questions,
    ) {
    }

    /** `state` as `JSON.stringify` sees it: an empty array is the empty object it stands for. */
    public function stateForJson(): array|stdClass
    {
        return $this->state === [] ? new stdClass() : $this->state;
    }
}
