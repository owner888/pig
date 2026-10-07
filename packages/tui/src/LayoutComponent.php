<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component the layout engine sizes itself rather than asking for lines — upstream's
 * `LayoutComponent`, whose `[LAYOUT_NODE]()` is `layoutNode()` here.
 */
interface LayoutComponent extends Component
{
    public function layoutNode(): StackLayoutNode|ScrollLayoutNode;
}
