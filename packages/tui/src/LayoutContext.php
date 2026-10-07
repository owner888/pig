<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Tui\Components\ScrollView;
use SplObjectStorage;

/**
 * One frame's layout pass — upstream's `LayoutContext` in `layout.ts`.
 *
 * @internal
 */
final class LayoutContext
{
    /** @var SplObjectStorage<Component, array<int, list<string>>> renders by width, so a component measured is not rendered again */
    public SplObjectStorage $renderCache;

    public ?ScrollView $primaryScrollView = null;

    /** @param Closure(): void $requestRender */
    public function __construct(
        public readonly LayoutViewport $viewport,
        public readonly Closure $requestRender,
        public readonly ?Component $focused,
    ) {
        $this->renderCache = new SplObjectStorage();
    }
}
