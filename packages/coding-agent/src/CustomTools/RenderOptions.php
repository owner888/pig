<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/**
 * How a result is being shown, for a tool drawing it itself.
 *
 * Upstream's `RenderResultOptions`. Both matter: `$partial` means the tool is still running
 * and this is progress rather than an answer, and `$expanded` is ctrl+o — the difference
 * between the five lines that fit and everything there is.
 */
final readonly class RenderOptions
{
    public function __construct(
        public bool $expanded = false,
        public bool $partial = false,
    ) {
    }
}
