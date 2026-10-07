<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Pig\Tui\RgbColor;

/** Upstream's `SystemThemeInput` in `system-theme.ts`. */
final readonly class SystemThemeInput
{
    /**
     * @param list<RgbColor>|null $palette ANSI colors 0-15.
     * @param float|null $saturation Saturation multiplier from 0 (grayscale) to 1. The first frame renders in grayscale until colors arrive.
     * @param 'dark'|'light'|null $appearanceHint Appearance when the terminal did not report its background, e.g. from its light/dark report or COLORFGBG.
     */
    public function __construct(
        public ?RgbColor $foreground = null,
        public ?RgbColor $background = null,
        public ?array $palette = null,
        public ?float $saturation = null,
        public ?string $appearanceHint = null,
    ) {
    }
}
