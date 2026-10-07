<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `AltScreenSearchResult`: the matches, and whether they differ from the last search. */
final readonly class AltScreenSearchResult
{
    /** @param list<AltScreenSearchMatch> $matches */
    public function __construct(
        public array $matches,
        public bool $changed,
    ) {
    }
}
