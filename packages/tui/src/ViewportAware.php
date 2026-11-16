<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Scrolling content that can be asked for less than all of itself at a new width.
 *
 * pig's own, not upstream's — see "A resize re-wraps what is on screen first" in
 * `docs/dev/decisions.md`. The fullscreen layout asks a scroll view's content for the rows the
 * viewport shows; the rest may come back as it was last drawn, at the old width, and is brought
 * up to date afterwards with `refine()`.
 */
interface ViewportAware extends Component
{
    /**
     * Every row, as `render()` answers them, with only some of them guaranteed to be drawn at
     * `$width`: the `$rows` rows from `$top` down as the content was last laid out, or the last
     * `$rows` rows when `$top` is null. Rows outside that may be the ones drawn at an earlier
     * width — the right number of them to scroll over, the wrong text to show.
     *
     * @return list<string>
     */
    public function renderWindow(int $width, ?int $top, int $rows): array;

    /** How many parts are still as they were drawn at an earlier width. */
    public function staleCount(): int;

    /**
     * Draw parts left at an earlier width, for about `$seconds`, the latest first.
     *
     * @param ?int $top the first row in view, null when the view follows the end
     * @return int by how many rows what lies wholly above `$top` grew (negative: shrank), which
     *             is how far the view has to move to keep showing the same rows
     */
    public function refine(float $seconds, ?int $top): int;
}
