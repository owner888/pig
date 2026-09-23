<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

/**
 * Whether a query matched, and how well.
 *
 * **Lower is better**, which is upstream's convention and is worth saying out loud every
 * time this type is mentioned: the score is a pile of penalties with a few rewards
 * subtracted, so the best match in a list is its minimum. Sorting descending gives the
 * worst match first, which looks like a working search until you read the results.
 *
 * A non-match carries a score of 0 rather than nothing, because upstream's does; it means
 * nothing and must not be compared against a real one — `matches` is the only field worth
 * reading when it is false.
 */
final readonly class FuzzyMatch
{
    public function __construct(
        public bool $matches,
        public float $score,
    ) {
    }
}
