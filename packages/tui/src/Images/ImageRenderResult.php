<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/** What `TerminalImage::renderImage()` returns: upstream's `{ sequence, columns, rows, imageId? }`. */
final readonly class ImageRenderResult
{
    public function __construct(
        public string $sequence,
        public int $columns,
        public int $rows,
        public ?int $imageId = null,
    ) {
    }
}
