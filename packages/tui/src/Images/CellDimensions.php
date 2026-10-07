<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * How many pixels one character cell covers — upstream's `CellDimensions`.
 *
 * Needed to turn an image's pixel size into cells. `TerminalImage` holds the current value; the
 * terminal is asked for the real figure at startup and, until it answers, the default is what a
 * 9×18 font would give.
 */
final readonly class CellDimensions
{
    public function __construct(public int $widthPx, public int $heightPx)
    {
    }
}
