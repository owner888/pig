<?php

declare(strict_types=1);

namespace Pig\Tui;

use Pig\Tui\Components\ScrollView;

/** Where one component landed in a frame — upstream's `LayoutBox`. */
final class LayoutBox
{
    /**
     * @param list<LayoutBox> $children
     * @param list<string>|null $lines a leaf's rendered lines
     * @param list<string>|null $scrollContentLines a scroll view's whole content
     */
    public function __construct(
        public Component $component,
        public LayoutRect $rect,
        public LayoutRect $clip,
        public array $children = [],
        public ?LayoutBox $parent = null,
        public ?array $lines = null,
        public int $lineOffset = 0,
        public ?ScrollView $scrollView = null,
        public ?array $scrollContentLines = null,
        public int $layer = 0,
    ) {
    }
}
