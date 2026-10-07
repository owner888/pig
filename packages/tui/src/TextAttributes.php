<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Upstream's `TextAttributes` in `colors.ts`. Upstream's optional booleans are `false` when not
 * given; build one with named arguments: `new TextAttributes(bold: true)`.
 *
 * Not final: `TextStyle` (and coding-agent's `ThemeStyle`) extend it, as upstream's interfaces do.
 */
readonly class TextAttributes
{
    public function __construct(
        public bool $bold = false,
        public bool $dim = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $inverse = false,
        public bool $strikethrough = false,
    ) {
    }
}
