<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

/** Upstream's `SystemThemeColors` in `system-theme.ts`. */
final readonly class SystemThemeColors
{
    /**
     * @param array<string, string|int> $colors Hex colors, ANSI palette indices, or "" for the terminal default, by theme token.
     * @param list<string> $dim Foreground tokens rendered faint (SGR 2), for terminals that did not report colors.
     * @param 'dark'|'light'|null $appearance
     */
    public function __construct(
        public array $colors,
        public array $dim,
        public ?string $appearance,
    ) {
    }
}
