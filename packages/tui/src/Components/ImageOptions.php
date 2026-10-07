<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

/** Upstream's `ImageOptions`, for `Image`. */
final readonly class ImageOptions
{
    /** @param int|null $imageId Kitty image ID. If provided, reuses this ID (for animations/updates). */
    public function __construct(
        public ?int $maxWidthCells = null,
        public ?int $maxHeightCells = null,
        public ?string $filename = null,
        public ?int $imageId = null,
    ) {
    }
}
