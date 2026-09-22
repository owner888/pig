<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/** An image's size in pixels. */
final readonly class ImageSize
{
    public function __construct(public int $widthPx, public int $heightPx)
    {
    }
}
