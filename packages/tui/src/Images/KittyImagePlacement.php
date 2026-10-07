<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * A placement-only command for an image line that carries a transmission — upstream's
 * `KittyImagePlacement`, from `TerminalImage::getKittyImagePlacement()`.
 *
 * `replacementLine` is the line with the transmission swapped for `sequence`, which is what the
 * alternate screen writes once the terminal already holds that image's data.
 */
final readonly class KittyImagePlacement
{
    public function __construct(
        public int $imageId,
        public int $transmissionGeneration,
        public int $transmissionBytes,
        public int $estimatedDecodedBytes,
        public int $rows,
        public string $sequence,
        public string $replacementLine,
    ) {
    }
}
