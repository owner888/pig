<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Upstream's `RgbColor` in `terminal-colors.ts`: 0–255 per channel.
 *
 * `int|float` because upstream's channels are `number`: the terminal reports whole numbers, but
 * `Colors::colorToRgb()` hands back an sRGB mix's channels unrounded, as upstream does.
 */
final readonly class RgbColor
{
    public function __construct(
        public int|float $r,
        public int|float $g,
        public int|float $b,
    ) {
    }
}
