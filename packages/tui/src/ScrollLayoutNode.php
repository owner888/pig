<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `ScrollLayoutNode` in `layout-node.ts`. */
final readonly class ScrollLayoutNode
{
    public function __construct(
        public Component $component,
        public ScrollLayoutState $state,
    ) {
    }
}
