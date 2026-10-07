<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `OklchChannels` in `colors.ts`. */
final readonly class OklchChannels
{
    public function __construct(
        public float $l,
        public float $c,
        public float $h,
    ) {
    }
}
