<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `TextStyle` in `colors.ts`: text attributes plus optional colors. */
final readonly class TextStyle extends TextAttributes
{
    public function __construct(
        public ?Color $fg = null,
        public ?Color $bg = null,
        bool $bold = false,
        bool $dim = false,
        bool $italic = false,
        bool $underline = false,
        bool $inverse = false,
        bool $strikethrough = false,
    ) {
        parent::__construct($bold, $dim, $italic, $underline, $inverse, $strikethrough);
    }
}
