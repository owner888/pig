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

    /**
     * @param Closure(): void $requestRender
     * @param SplObjectStorage<Component, array<int, list<string>>>|null $reusableOutput an earlier
     *        frame's `renderCache` with the same content; its entries are copied into `renderCache` when used
     */
    public function __construct(
        public readonly LayoutViewport $viewport,
        public readonly Closure $requestRender,
        public readonly ?SplObjectStorage $reusableOutput = null,
    ) {
        $this->renderCache = new SplObjectStorage();
    }
}
