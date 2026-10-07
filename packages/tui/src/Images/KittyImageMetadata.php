<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * What `TerminalImage::renderImage()` registered about a Kitty image with an id — upstream's
 * `KittyImageMetadata` (an `ImageCellSize` plus the id and the source pixel size), read back to
 * crop a placement that is partly scrolled out of view.
 */
final readonly class KittyImageMetadata
{
    public function __construct(
        public int $imageId,
        public int $columns,
        public int $rows,
        public int $widthPx,
        public int $heightPx,
    ) {
    }
}
