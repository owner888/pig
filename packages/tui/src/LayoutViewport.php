<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `LayoutViewport` in `layout-node.ts`. */
final readonly class LayoutViewport
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
