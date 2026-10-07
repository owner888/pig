<?php

declare(strict_types=1);

namespace Pig\Tui;

/** The last rendered, terminal-relative rectangle of an overlay — upstream's `OverlayBounds`. */
final readonly class OverlayBounds
{
    public function __construct(
        public int $row,
        public int $col,
        public int $width,
        public int $height,
    ) {
    }
}
