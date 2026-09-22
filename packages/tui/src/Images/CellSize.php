<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * How many pixels one character cell covers.
 *
 * Needed to turn an image's pixel height into a number of rows, which is the only unit
 * the renderer can reason about. `TerminalImage` holds the current value; the terminal is
 * asked for the real figure at startup and, until it answers, the default is what a 9×18
 * font would give — close enough that a picture is roughly the right height.
 */
final readonly class CellSize
{
    public function __construct(public int $widthPx, public int $heightPx)
    {
    }
}
