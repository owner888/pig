<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * What the layout engine needs from a scrolling view — upstream's `ScrollLayoutState` in
 * `layout-node.ts`. Upstream's `primary` and `overscroll` are read off `ScrollView` itself.
 */
interface ScrollLayoutState
{
    public function scrollTop(): int;

    public function viewportHeight(): int;

    public function getContentWidth(int $width): int;

    /** @param Closure(): void $requestRender */
    public function updateLayout(int $contentHeight, int $viewportHeight, Closure $requestRender): void;
}
