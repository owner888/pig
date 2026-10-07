<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\LayoutViewport;

/** A stack child with its sizing options — upstream's `StackEntry` in `components/stack.ts`. */
final readonly class StackEntry
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
