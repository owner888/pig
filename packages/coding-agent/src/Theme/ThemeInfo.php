<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

/** Upstream's `ThemeInfo` in `theme.ts`: a theme's name and its file, null for the generated system theme. */
final readonly class ThemeInfo
{
    public function __construct(
        public string $name,
        public ?string $path,
    ) {
    }
}
