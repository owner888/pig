<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Upstream's `OkhslChannels` in `colors.ts`: hue in degrees, saturation and lightness 0-1. Saturation
 * is relative to the most the sRGB gamut allows at that hue and lightness, so every value is in gamut.
 */
final readonly class OkhslChannels
{
    public function __construct(
        public float $h,
        public float $s,
        public float $l,
    ) {
    }
}
