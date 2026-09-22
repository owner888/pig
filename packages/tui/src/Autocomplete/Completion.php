<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

/** The buffer after a suggestion was taken, and where the cursor ended up. */
final readonly class Completion
{
    /**
     * @param list<string> $lines
     * @param int          $cursorCol byte offset into the cursor's line
     */
    public function __construct(
        public array $lines,
        public int $cursorLine,
        public int $cursorCol,
    ) {
    }
}
