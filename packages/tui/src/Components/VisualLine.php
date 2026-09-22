<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

/**
 * Where one drawn row came from in the buffer.
 *
 * Up and down move by drawn rows, not by logical lines: on a wrapped paragraph, pressing
 * Up should go to the row above, which is usually the same logical line. That needs a map
 * from rows back to offsets, and this is one entry of it.
 *
 * @internal
 */
final readonly class VisualLine
{
    public function __construct(
        public int $logicalLine,
        public int $startCol,
        public int $length,
    ) {
    }
}
