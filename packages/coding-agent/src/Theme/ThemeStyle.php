<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Pig\Tui\Color;
use Pig\Tui\TextAttributes;

/**
 * Upstream's `ThemeStyle` in `theme.ts`: text attributes plus a foreground and background, each a
 * theme token (`ThemeColor` / `ThemeBg` name) or a concrete `Color`.
 *
 * Tokens are only accepted in their own slot, because "" (terminal default) means the default foreground
 * or background depending on the slot. Use `Theme::colors()[$token]` to use a token's color in the other slot.
 */
final readonly class ThemeStyle extends TextAttributes
{
    public function __construct(
        public string|Color|null $fg = null,
        public string|Color|null $bg = null,
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
