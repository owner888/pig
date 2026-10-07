<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `TuiStopOptions`. */
final readonly class TuiStopOptions
{
    public function __construct(
        /** Leave renderer output in place for another TUI taking over the same terminal. */
        public bool $preserveScreen = false,
    ) {
    }
}
