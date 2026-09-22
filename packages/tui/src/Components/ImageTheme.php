<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Style;

/** How the text that stands in for an undrawable image is painted. */
final readonly class ImageTheme
{
    /** @param Closure(string): string $fallback */
    public function __construct(public Closure $fallback)
    {
    }

    public static function default(): self
    {
        return new self(Style::dim(...));
    }
}
