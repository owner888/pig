<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/**
 * How a result is being shown, for a tool drawing it itself.
 *
 * Upstream's `RenderResultOptions`. Both matter: `$partial` means the tool is still running
 * and this is progress rather than an answer, and `$expanded` is ctrl+o — the difference
 * between the five lines that fit and everything there is.
 *
 * `$isError` and `$timer` are upstream's `context.isError` and `toolTimerText()`: whether the
 * call failed, and `Elapsed 1.2s` / `Took 1.2s` for a tool that shows how long it ran — null
 * when nothing timed it. `renderCall` is given one too, as its third argument, for
 * upstream's `context.expanded`.
 */
final readonly class RenderOptions
{
    public function __construct(
        public bool $expanded = false,
        public bool $partial = false,
        public bool $isError = false,
        public ?string $timer = null,
    ) {
    }
}
