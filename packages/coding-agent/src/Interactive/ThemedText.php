<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\Components\Text;

/**
 * Text whose content applies theme colors. Plain `Text` keeps the colors its string was built with, so
 * a theme change, or the system theme receiving the terminal's colors, would leave it stale. This
 * rebuilds the string from `build` after every invalidation, which the UI performs on theme changes.
 *
 * `build` must return the same content each time, apart from colors. Snapshot changing data before
 * creating the component, or call `invalidate()` after changing state that `build` reads.
 *
 * Upstream's `ThemedText` (`components/themed-text.ts`). One difference in `render()`: pig's
 * `Text::setText()` goes through `invalidate()` (upstream's clears the cache directly), which this
 * class overrides to mark the text stale, so the flag is cleared after `setText()` rather than before
 * it — otherwise the text just built would count as stale and be rebuilt on the next render.
 */
class ThemedText extends Text
{
    private bool $stale = true;

    /** @param Closure(): string $build */
    public function __construct(
        private readonly Closure $build,
        int $paddingX = 1,
        int $paddingY = 1,
    ) {
        parent::__construct('', $paddingX, $paddingY);
    }

    #[\Override]
    public function invalidate(): void
    {
        parent::invalidate();
        $this->stale = true;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->stale) {
            $this->setText(($this->build)());
            $this->stale = false;
        }

        return parent::render($width);
    }
}
