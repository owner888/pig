<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `ScrollbarGeometry`. */
final readonly class ScrollbarGeometry
{
    public function __construct(
        public int $column,
        public int $trackTop,
        public int $trackHeight,
        public int $thumbTop,
        public int $thumbHeight,
        public int $maxScrollTop,
    ) {
    }
}
