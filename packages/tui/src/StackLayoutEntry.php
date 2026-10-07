<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/** One child of a stack and how it is sized — upstream's `StackLayoutEntry` in `layout-node.ts`. */
final readonly class StackLayoutEntry
{
    /**
     * @param int|'auto'|null $basis
     * @param (Closure(LayoutViewport): bool)|null $visible
     */
    public function __construct(
        public Component $component,
        public int|string|null $basis = null,
        public ?int $grow = null,
        public ?int $shrink = null,
        public ?int $minSize = null,
        public ?int $maxSize = null,
        public ?Closure $visible = null,
    ) {
    }
}
