<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `OklchColorValue` in `colors.ts`. Made by `Colors::oklchColor()`, which normalizes the hue. */
final readonly class OklchColorValue implements Color
{
    /** @var 'oklch' */
    public string $kind;

    public function __construct(
        public float $l,
        public float $c,
        public float $h,
    ) {
        $this->kind = 'oklch';
    }
}
