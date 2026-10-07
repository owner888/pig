<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\LayoutComponent;
use Pig\Tui\LayoutViewport;
use Pig\Tui\StackLayoutEntry;
use Pig\Tui\StackLayoutNode;

/**
 * Children laid out along one axis, each sized by basis, grow, shrink and min/max — upstream's
 * `Stack` in `components/stack.ts`. The layout engine reads `layoutNode()`; `render()` alone (a
 * stack not under a fullscreen renderer) gives every child its natural size.
 */
abstract class Stack extends Container implements LayoutComponent
{
    /** @var list<StackLayoutEntry> */
    protected array $entries = [];

    protected readonly int $gap;

    /** @var 'stretch'|'start'|'center'|'end' */
    protected readonly string $align;

    /** @return 'vstack'|'hstack' */
    abstract protected function layoutType(): string;

    /**
     * @param list<Component|StackEntry> $children
     * @param 'stretch'|'start'|'center'|'end' $align
     */
    public function __construct(array $children = [], ?int $gap = null, string $align = 'stretch')
    {
        $this->gap = self::normalizeSize($gap, 0);
        $this->align = $align;

        foreach ($children as $child) {
            if ($child instanceof StackEntry) {
                $this->addChild($child->component, $child->basis, $child->grow, $child->shrink, $child->minSize, $child->maxSize, $child->visible);
            } else {
                $this->addChild($child);
            }
        }
    }

    /**
     * @param int|'auto'|null $basis
     * @param (Closure(LayoutViewport): bool)|null $visible
     */
    #[\Override]
    public function addChild(
        Component $child,
        int|string|null $basis = null,
        ?int $grow = null,
        ?int $shrink = null,
        ?int $minSize = null,
        ?int $maxSize = null,
        ?Closure $visible = null,
    ): void {
        parent::addChild($child);
        $this->entries[] = new StackLayoutEntry(
            $child,
            $basis,
            $grow === null ? null : self::normalizeSize($grow, 0),
            $shrink === null ? null : self::normalizeSize($shrink, 1),
            $minSize === null ? null : self::normalizeSize($minSize, 0),
            $maxSize === null ? null : self::normalizeSize($maxSize, PHP_INT_MAX),
            $visible,
        );
    }

    #[\Override]
    public function removeChild(Component $child): void
    {
        parent::removeChild($child);
        foreach ($this->entries as $index => $entry) {
            if ($entry->component === $child) {
                array_splice($this->entries, $index, 1);
                break;
            }
        }
    }

    #[\Override]
    public function clear(): void
    {
        parent::clear();
        $this->entries = [];
    }

    #[\Override]
    public function layoutNode(): StackLayoutNode
    {
        return new StackLayoutNode($this->layoutType(), $this->entries, $this->gap, $this->align);
    }

    /**
     * @param list<StackLayoutEntry> $entries
     * @return list<StackLayoutEntry>
     */
    public static function visibleStackEntries(array $entries, LayoutViewport $viewport): array
    {
        return array_values(array_filter(
            $entries,
            static fn (StackLayoutEntry $entry): bool => $entry->visible === null || ($entry->visible)($viewport),
        ));
    }

    /**
     * Upstream's `allocateStackSizes()`: natural sizes, then grown or shrunk to fill `$availableSize`.
     *
     * @param list<StackLayoutEntry> $entries
     * @param list<int> $intrinsicSizes
     * @return list<int>
     */
    public static function allocateStackSizes(array $entries, array $intrinsicSizes, ?int $availableSize, int $gap): array
    {
        $sizes = [];
        foreach ($entries as $index => $entry) {
            $basis = $entry->basis === null || $entry->basis === 'auto' ? ($intrinsicSizes[$index] ?? 0) : (int) $entry->basis;
            $sizes[] = self::clampSize($basis, $entry);
        }

        if ($availableSize === null) {
            return $sizes;
        }

        $contentSize = max(0, $availableSize - max(0, count($entries) - 1) * $gap);
        $total = array_sum($sizes);
        if ($total < $contentSize) {
            self::distribute($sizes, $entries, $contentSize - $total, 'grow');
        } elseif ($total > $contentSize) {
            self::distribute($sizes, $entries, $total - $contentSize, 'shrink');
        }

        return $sizes;
    }

    private static function normalizeSize(?int $value, int $fallback): int
    {
        return $value === null ? $fallback : max(0, $value);
    }

    private static function clampSize(int $size, StackLayoutEntry $entry): int
    {
        $min = max(0, $entry->minSize ?? 0);
        $max = max($min, $entry->maxSize ?? PHP_INT_MAX);

        return max($min, min($max, max(0, $size)));
    }

    /**
     * @param list<int> $sizes
     * @param list<StackLayoutEntry> $entries
     * @param 'grow'|'shrink' $mode
     */
    private static function distribute(array &$sizes, array $entries, int $amount, string $mode): void
    {
        $remaining = $amount;
        while ($remaining > 0) {
            $candidates = [];
            foreach ($entries as $index => $entry) {
                $eligible = $mode === 'grow'
                    ? ($entry->grow ?? 0) > 0 && $sizes[$index] < ($entry->maxSize ?? PHP_INT_MAX)
                    : ($entry->shrink ?? 1) > 0 && $sizes[$index] > ($entry->minSize ?? 0);
                if ($eligible) {
                    $candidates[] = $index;
                }
            }
            if ($candidates === []) {
                return;
            }

            $weightOf = static fn (int $index): int => $mode === 'grow'
                ? ($entries[$index]->grow ?? 0)
                : ($entries[$index]->shrink ?? 1) * max(1, $sizes[$index]);
            $totalWeight = 0;
            foreach ($candidates as $index) {
                $totalWeight += $weightOf($index);
            }

            $distributed = 0;
            foreach ($candidates as $index) {
                if ($remaining <= 0) {
                    break;
                }
                $proposed = max(1, intdiv($remaining * $weightOf($index), $totalWeight));
                $capacity = $mode === 'grow'
                    ? ($entries[$index]->maxSize ?? PHP_INT_MAX) - $sizes[$index]
                    : $sizes[$index] - ($entries[$index]->minSize ?? 0);
                $delta = min($remaining, $proposed, $capacity);
                if ($delta <= 0) {
                    continue;
                }
                $sizes[$index] += $mode === 'grow' ? $delta : -$delta;
                $remaining -= $delta;
                $distributed += $delta;
            }
            if ($distributed === 0) {
                return;
            }
        }
    }
}
