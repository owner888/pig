<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * How an overlay is sized and placed — upstream's `OverlayOptions`. Sizes and positions are a
 * number of cells or a percentage string such as `'50%'`.
 */
final readonly class OverlayOptions
{
    /**
     * @param int|string|null $width columns, or a percentage of the terminal width
     * @param int|null $minWidth
     * @param int|string|null $maxHeight rows, or a percentage of the terminal height
     * @param 'center'|'top-left'|'top-right'|'bottom-left'|'bottom-right'|'top-center'|'bottom-center'|'left-center'|'right-center' $anchor
     * @param int|string|null $row absolute row, or a percentage from the top (the overlay stays inside)
     * @param int|string|null $col absolute column, or a percentage from the left
     * @param int|array{top?: int, right?: int, bottom?: int, left?: int}|null $margin from the terminal edges
     * @param (Closure(int, int): bool)|null $visible shown only while this answers true for the terminal size
     * @param bool $nonCapturing do not take keyboard focus when shown
     */
    public function __construct(
        public int|string|null $width = null,
        public ?int $minWidth = null,
        public int|string|null $maxHeight = null,
        public string $anchor = 'center',
        public ?int $offsetX = null,
        public ?int $offsetY = null,
        public int|string|null $row = null,
        public int|string|null $col = null,
        public int|array|null $margin = null,
        public ?Closure $visible = null,
        public bool $nonCapturing = false,
    ) {
    }
}
