<?php

declare(strict_types=1);

namespace Pig\Tui;

use Pig\Tui\Components\ScrollView;

/** One laid-out, painted frame — upstream's `LayoutFrame`. */
final readonly class LayoutFrame
{
    /** @param list<string> $lines exactly `$height` rows */
    public function __construct(
        public LayoutBox $root,
        public int $width,
        public int $height,
        public array $lines,
        public ?ScrollView $primaryScrollView = null,
    ) {
    }
}
