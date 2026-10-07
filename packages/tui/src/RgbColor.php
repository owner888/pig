<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `RgbColor` in `terminal-colors.ts`: 0–255 per channel. */
final readonly class RgbColor
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
    ) {
    }
}
