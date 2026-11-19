<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\ChildLines;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\ViewportAware;
use WeakMap;

/**
 * A container that, at a new width, re-draws the children in view and leaves the rest for later.
 *
 * pig's own, not upstream's, and the developer's choice — "A resize re-wraps what is on screen
 * first" in `docs/dev/decisions.md`. A width change makes every child wrap its text again, so a
 * frame cost as much as the whole conversation is long: 300ms for 1,700 tool calls here, and 800ms
 * in upstream pi on the same session, per frame, while the window was being dragged. The screen
 * shows fifty rows of it.
 *
 * `render()` is `Container`'s: every child at the width asked for. `renderWindow()` is what the
 * fullscreen layout calls instead, and it draws only as many children as fill the rows in view;
 * each of the others answers the lines it drew last, which are the wrong text at the wrong width
 * but occupy the place the child had, so the scroll position holds. `refine()` then draws those,
 * a few milliseconds at a time.
 */
class WindowedContainer extends Container implements ViewportAware
{
    /** @var WeakMap<Component, array{width: int, height: int, lines: list<string>}> what each child drew last */
    private WeakMap $drawn;

    /** The width of the last frame; `refine()` draws at it. */
    private int $width = 0;

    /** Children of the last frame that answered lines from an earlier width. */
    private int $stale = 0;

    public function __construct()
    {
        $this->drawn = new WeakMap();
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->renderWindow($width, 0, PHP_INT_MAX);
    }

    #[\Override]
    public function renderWindow(int $width, ?int $top, int $rows): array
    {
        $children = $this->children;
        $count = count($children);
        /** @var array<int, list<string>> $fresh children drawn at `$width` in this call */
        $fresh = [];

        if ($rows === PHP_INT_MAX) {
            // Everything, in order: `render()`, and a nested container inside one.
            foreach ($children as $index => $child) {
                $fresh[$index] = $this->draw($child, $width, 0, PHP_INT_MAX);
            }
        } elseif ($rows > 0 && $count > 0) {
            if ($top !== null) {
                // The first child that reaches `$top` where it was last laid out.
                $first = null;
                $y = 0;

                foreach ($children as $index => $child) {
                    $height = $this->drawn[$child]['height'] ?? 0;

                    if ($y + $height > $top) {
                        $first = $index;
                        break;
                    }

                    $y += $height;
                }

                if ($first === null) {
                    // Past the end, or nothing drawn yet: the end is what will be in view.
                    $top = null;
                } else {
                    // Downwards until the rows are filled with what was drawn now, not with what
                    // the children measured before: at a wider width they are shorter.
                    $inside = $top - $y;
                    $need = $rows + $inside;

                    for ($index = $first; $index < $count && $need > 0; $index++) {
                        $fresh[$index] = $this->draw($children[$index], $width, $index === $first ? $inside : 0, $need);
                        $need -= count($fresh[$index]);
                    }
                }
            }

            if ($top === null) {
                $need = $rows;

                for ($index = $count - 1; $index >= 0 && $need > 0; $index--) {
                    $fresh[$index] = $this->draw($children[$index], $width, null, $need);
                    $need -= count($fresh[$index]);
                }
            }
        }

        $parts = [];
        $stale = 0;

        foreach ($children as $index => $child) {
            if (isset($fresh[$index])) {
                $childLines = $fresh[$index];
            } else {
                $entry = $this->drawn[$child] ?? null;

                if ($child instanceof ViewportAware) {
                    // It keeps its own account of what is stale.
                    $childLines = $this->draw($child, $width, 0, 0);
                } elseif ($entry === null || $entry['width'] === $width) {
                    // Never drawn, so there is nothing to stand in for it; or drawn at this
                    // width, where asking again is the child's own cache and picks up a change.
                    $childLines = $this->draw($child, $width, 0, 0);
                } else {
                    $childLines = $entry['lines'];
                    $stale++;
                }
            }

            $parts[] = $childLines;
        }

        $this->width = $width;
        $this->stale = $stale;
        // The same rows as last frame are handed back as the same array (upstream #11968's
        // `renderChildLines()`): a transcript of thousands of children is not copied again for a
        // frame where none of them changed.
        $this->childLines = ChildLines::join($children, $parts, $width, $this->childLines);

        return $this->childLines->lines;
    }

    #[\Override]
    public function staleCount(): int
    {
        $stale = $this->stale;

        foreach ($this->children as $child) {
            if ($child instanceof ViewportAware) {
                $stale += $child->staleCount();
            }
        }

        return $stale;
    }

    #[\Override]
    public function refine(float $seconds, ?int $top): int
    {
        $until = microtime(true) + $seconds;
        $children = $this->children;

        // Where each child starts, by what it measured last.
        $tops = [];
        $y = 0;

        foreach ($children as $index => $child) {
            $tops[$index] = $y;
            $y += $this->drawn[$child]['height'] ?? 0;
        }

        $above = 0;

        for ($index = count($children) - 1; $index >= 0; $index--) {
            $child = $children[$index];
            $entry = $this->drawn[$child] ?? null;

            if ($entry === null) {
                continue;
            }

            if ($child instanceof ViewportAware) {
                if ($child->staleCount() === 0) {
                    continue;
                }

                $before = $entry['height'];
                $grew = $child->refine(max(0.0, $until - microtime(true)), $top === null ? null : $top - $tops[$index]);
                // Its height is only known for certain when it is next drawn; what moved above
                // the view is what the caller needs, and the rest shows up in the next frame.
                $this->drawn[$child] = ['width' => $entry['width'], 'height' => $before + $grew, 'lines' => []];
                $above += $grew;
            } elseif ($entry['width'] !== $this->width) {
                $lines = $child->render($this->width);
                $grew = count($lines) - $entry['height'];
                $this->drawn[$child] = ['width' => $this->width, 'height' => count($lines), 'lines' => $lines];
                $this->stale = max(0, $this->stale - 1);

                if ($top !== null && $tops[$index] + $entry['height'] <= $top) {
                    $above += $grew;
                }
            } else {
                continue;
            }

            if (microtime(true) >= $until) {
                break;
            }
        }

        return $above;
    }

    /**
     * Draw `$child` at `$width` and remember what it drew.
     *
     * @return list<string>
     */
    private function draw(Component $child, int $width, ?int $top, int $rows): array
    {
        $lines = $child instanceof ViewportAware ? $child->renderWindow($width, $top, $rows) : $child->render($width);
        // A nested container's lines are never used in its place — it is always asked — so they
        // are not kept twice.
        $this->drawn[$child] = ['width' => $width, 'height' => count($lines), 'lines' => $child instanceof ViewportAware ? [] : $lines];

        return $lines;
    }
}
