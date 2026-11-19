<?php

declare(strict_types=1);

namespace Pig\Tui;

use Pig\Tui\Components\ScrollView;

/** One laid-out, painted frame — upstream's `LayoutFrame`. */
final readonly class LayoutFrame
{
    /**
     * @param list<string> $lines exactly `$height` rows
     * @param \SplObjectStorage<Component, array<int, list<string>>> $renderCache output of every component
     *        this frame rendered; pass it to a later frame whose content did not change
     */
    public function __construct(
        public LayoutBox $root,
        public int $width,
        public int $height,
        public array $lines,
        public ?ScrollView $primaryScrollView = null,
        public \SplObjectStorage $renderCache = new \SplObjectStorage(),
    ) {
    }
}
