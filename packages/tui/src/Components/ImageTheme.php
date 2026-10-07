<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;

/** Upstream's `ImageTheme`: how the text that stands in for an undrawable image is painted. */
final readonly class ImageTheme
{
    /** @param Closure(string): string $fallbackColor */
    public function __construct(public Closure $fallbackColor)
    {
    }
}
