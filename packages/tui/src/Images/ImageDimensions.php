<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/** An image's size in pixels — upstream's `ImageDimensions`. */
final readonly class ImageDimensions
{
    public function __construct(public int $widthPx, public int $heightPx)
    {
    }
}
