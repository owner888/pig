<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * What this terminal can do — upstream's `TerminalCapabilities` in `terminal-image.ts`.
 *
 * Worked out from the environment by `TerminalImage::detectCapabilities()`: asking would be
 * better and is not possible in time, because the answer arrives as input and the first frame
 * has to be drawn before it does.
 */
final readonly class TerminalCapabilities
{
    public function __construct(
        public ?ImageProtocol $images,
        public bool $trueColor,
        public bool $hyperlinks,
    ) {
    }
}
