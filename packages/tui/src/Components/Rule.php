<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Style;

/**
 * A horizontal line, as wide as the terminal is when it is drawn.
 *
 * Upstream's `DynamicBorder`, renamed: "dynamic" there means "asks the width at render time",
 * which is what every component in `pig/tui` does — a `Component` is handed the width and
 * nothing else, so there is no static kind for this to be the opposite of. What it *is* is a
 * rule, so it is called one.
 *
 * The colour is a closure taken at construction and never a global. Upstream's own note says
 * why in the hardest way available: its `theme` is a module-level global, and a hook loaded
 * through jiti gets a second module cache in which that global is `undefined` — so a border
 * drawn from inside a hook crashed on a theme that was there in every other context. pig has
 * no module caches and `Style` is not a global, but the shape is right for a second reason:
 * `/theme` replaces the palette, and a component holding a closure it was given cannot hold a
 * stale colour table.
 */
final class Rule implements Component
{
    /** @var Closure(string): string */
    private readonly Closure $style;

    /** @param Closure(string): string|null $style dim when not given */
    public function __construct(?Closure $style = null)
    {
        // Not a default parameter: a closure is not a constant expression.
        $this->style = $style ?? Style::dim(...);
    }

    #[\Override]
    public function invalidate(): void
    {
        // One line built from one `str_repeat`; there is nothing worth caching.
    }

    #[\Override]
    public function render(int $width): array
    {
        // At least one, because a zero-width terminal still has to produce a line — an empty
        // array here would make the component silently occupy no row, and the frame above it
        // would count rows the renderer does not draw.
        return [($this->style)(str_repeat('─', max(1, $width)))];
    }
}
