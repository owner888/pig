<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/** Upstream's `ImageRenderOptions`, for `TerminalImage::renderImage()`. */
final readonly class ImageRenderOptions
{
    /**
     * @param int|null $imageId    Kitty image ID. If provided, reuses/replaces existing image with this ID.
     * @param bool|null $moveCursor Whether Kitty should apply its default cursor movement after placement.
     */
    public function __construct(
        public ?int $maxWidthCells = null,
        public ?int $maxHeightCells = null,
        public ?bool $preserveAspectRatio = null,
        public ?int $imageId = null,
        public ?bool $moveCursor = null,
    ) {
    }
}
