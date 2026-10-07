<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * One row's part of a transcript search match — upstream's `AltScreenSearchSegment`. Columns are
 * cells in the scroll view's content; `endCol` grows while a match is assembled.
 */
final class AltScreenSearchSegment
{
    public function __construct(
        public readonly int $row,
        public readonly int $startCol,
        public int $endCol,
    ) {
    }
}
