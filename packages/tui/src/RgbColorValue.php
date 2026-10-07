<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Upstream's `RgbColorValue` in `colors.ts`: sRGB channels 0-255, not necessarily whole numbers
 * (an sRGB mix is not rounded). Made by `Colors::rgbColor()`.
 */
final readonly class RgbColorValue implements Color
{
    /** @var 'rgb' */
    public string $kind;

    public function __construct(
        public int|float $r,
        public int|float $g,
        public int|float $b,
    ) {
        $this->kind = 'rgb';
    }
}
