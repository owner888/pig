<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/** How many cells an image is drawn across and down — upstream's `ImageCellSize`. */
final readonly class ImageCellSize
{
    public function __construct(public int $columns, public int $rows)
    {
    }
}
