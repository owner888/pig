<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\Stack;

/**
 * The fullscreen layout engine — upstream's `layout.ts`.
 *
 * Gives every `LayoutComponent` (stacks, scroll views) a rectangle, renders the leaves into
 * theirs, clips, and paints one frame exactly `$height` rows tall. Where upstream finds the
 * cursor line by `CURSOR_MARKER`, pig asks the focused component's `Caret`: a leaf taller than
 * its rectangle is scrolled so the caret line stays inside it.
 */
final class Layout
{
    private const string OSC133_ZONE_PREFIX = '/^(?:\x1b\]133;[ABC](?:\x07|\x1b\\\\))+/';

    /** @param Closure(): void $requestRender */
    public static function renderLayoutFrame(Component $root, int $width, int $height, Closure $requestRender, ?Component $focused = null): LayoutFrame
    {
        $safeWidth = max(1, $width);
        $safeHeight = max(1, $height);
        $context = new LayoutContext(new LayoutViewport($safeWidth, $safeHeight), $requestRender, $focused);
        $rootBox = self::layoutComponent($context, $root, 0, 0, $safeWidth, $safeHeight, new LayoutRect(0, 0, $safeWidth, $safeHeight));
        $lines = array_fill(0, $safeHeight, '');
        self::paintBox($rootBox, $lines, $safeWidth);

        return new LayoutFrame($rootBox, $safeWidth, $safeHeight, $lines, $context->primaryScrollView);
    }

    /**
     * The visual hit path from the deepest component to the layout root.
     *
     * @return list<LayoutBox>
     */
    public static function getLayoutBoxesAt(LayoutFrame $frame, int $x, int $y): array
    {
        $result = [];
        $visit = static function (LayoutBox $box, int $depth) use (&$visit, &$result, $x, $y): void {
            if (!$box->clip->contains($x, $y)) {
                return;
            }
            $result[] = [$box, $depth];
            foreach ($box->children as $child) {
                $visit($child, $depth + 1);
            }
        };
        $visit($frame->root, 0);
        usort($result, static fn (array $a, array $b): int => $b[0]->layer - $a[0]->layer ?: $b[1] - $a[1]);

        return array_map(static fn (array $entry): LayoutBox => $entry[0], $result);
    }

    public static function getScrollViewBox(LayoutFrame $frame, ScrollView $scrollView): ?LayoutBox
    {
        $visit = static function (LayoutBox $box) use (&$visit, $scrollView): ?LayoutBox {
            if ($box->scrollView === $scrollView) {
                return $box;
            }
            foreach ($box->children as $child) {
                $match = $visit($child);
                if ($match !== null) {
                    return $match;
                }
            }

            return null;
        };

        return $visit($frame->root);
    }

    /** @return list<ScrollView> deepest first */
    public static function getScrollViewsAt(LayoutFrame $frame, int $x, int $y): array
    {
        $result = [];
        $visit = static function (LayoutBox $box, int $depth) use (&$visit, &$result, $x, $y): void {
            if (!$box->clip->contains($x, $y)) {
                return;
            }
            if ($box->scrollView !== null && $box->rect->contains($x, $y)) {
                $result[] = [$box->scrollView, $depth];
            }
            foreach ($box->children as $child) {
                $visit($child, $depth + 1);
            }
        };
        $visit($frame->root, 0);
        usort($result, static fn (array $a, array $b): int => $b[1] - $a[1]);

        return array_map(static fn (array $entry): ScrollView => $entry[0], $result);
    }

    public static function getScrollbarGeometry(LayoutBox $box, bool $includeHiddenAuto = false): ?ScrollbarGeometry
    {
        $view = $box->scrollView;
        if ($view === null || $box->rect->width <= 0 || $box->rect->height <= 0) {
            return null;
        }

        $contentHeight = isset($box->children[0]) ? $box->children[0]->rect->height : count($box->scrollContentLines ?? []);
        $trackHeight = $box->rect->height;
        $canRevealHiddenAuto = $includeHiddenAuto && $view->scrollbar() === 'auto' && $contentHeight > $trackHeight;
        if (!$view->isScrollbarVisible() && !$canRevealHiddenAuto) {
            return null;
        }

        $minThumbHeight = min(2, $trackHeight);
        $thumbHeight = max($minThumbHeight, min($trackHeight, (int) round(($trackHeight * $trackHeight) / max(1, $contentHeight))));
        $maxScrollTop = max(0, $contentHeight - $trackHeight);
        $maxThumbTop = $trackHeight - $thumbHeight;
        $thumbOffset = $maxScrollTop === 0 ? 0 : (int) round(($view->scrollTop() / $maxScrollTop) * $maxThumbTop);
        $column = $box->rect->x + $box->rect->width - 1;
        if ($column < $box->clip->x || $column >= $box->clip->x + $box->clip->width) {
            return null;
        }

        return new ScrollbarGeometry($column, $box->rect->y, $trackHeight, $box->rect->y + $thumbOffset, $thumbHeight, $maxScrollTop);
    }

    /**
     * Which row of `$component`'s own lines the focused component's caret is on, if it is in there.
     * Stands in for upstream's search for `CURSOR_MARKER` in a box's lines.
     *
     * @return array{0: int, 1: int}|null row and column within `$component`
     */
    public static function caretIn(Component $component, ?Component $focused, int $width): ?array
    {
        if (!$focused instanceof Caret) {
            return null;
        }

        $caret = $focused->caret($width);
        if ($caret === null) {
            return null;
        }

        if ($component === $focused) {
            return $caret;
        }

        $top = $component instanceof Container ? $component->rowOf($focused, $width) : null;

        return $top === null ? null : [$top + $caret[0], $caret[1]];
    }

    /** @return list<string> */
    private static function renderCached(LayoutContext $context, Component $component, int $width): array
    {
        $safeWidth = max(1, $width);
        if (!isset($context->renderCache[$component])) {
            $context->renderCache[$component] = [];
        }
        $widths = $context->renderCache[$component];
        if (!isset($widths[$safeWidth])) {
            $widths[$safeWidth] = $component->render($safeWidth);
            $context->renderCache[$component] = $widths;
        }

        return $widths[$safeWidth];
    }

    private static function measureHeight(LayoutContext $context, Component $component, int $width): int
    {
        return count(self::renderCached($context, $component, $width));
    }

    private static function measureWidth(LayoutContext $context, Component $component, int $width): int
    {
        $max = 0;
        foreach (self::renderCached($context, $component, $width) as $line) {
            $max = max($max, Width::visible($line));
        }

        return $max;
    }

    private static function translateBox(LayoutBox $box, int $deltaY): void
    {
        $box->rect->y += $deltaY;
        foreach ($box->children as $child) {
            self::translateBox($child, $deltaY);
        }
    }

    private static function updateClips(LayoutBox $box, LayoutRect $parentClip): void
    {
        $box->clip = $parentClip->intersect($box->rect);
        foreach ($box->children as $child) {
            self::updateClips($child, $box->clip);
        }
    }

    private static function layoutComponent(LayoutContext $context, Component $component, int $x, int $y, int $width, ?int $height, LayoutRect $clip): LayoutBox
    {
        $safeWidth = max(1, $width);
        $node = $component instanceof LayoutComponent ? $component->layoutNode() : null;

        if ($node === null) {
            $lines = self::renderCached($context, $component, $safeWidth);
            $allocatedHeight = $height === null ? count($lines) : max(0, $height);
            $lineOffset = 0;
            if (count($lines) > $allocatedHeight && $allocatedHeight > 0) {
                $cursorLine = self::caretIn($component, $context->focused, $safeWidth)[0] ?? -1;
                if ($cursorLine >= $allocatedHeight) {
                    $lineOffset = $cursorLine - $allocatedHeight + 1;
                }
            }
            $rect = new LayoutRect($x, $y, $safeWidth, $allocatedHeight);

            return new LayoutBox($component, $rect, $clip->intersect($rect), lines: $lines, lineOffset: $lineOffset);
        }

        if ($node instanceof ScrollLayoutNode) {
            $state = $node->state;
            $previousScrollTop = $state->scrollTop();
            $contentWidth = $state->getContentWidth($safeWidth);
            $childBox = self::layoutComponent($context, $node->component, $x, $y - $previousScrollTop, $contentWidth, null, $clip);
            $contentHeight = $childBox->rect->height;
            $viewportHeight = $height === null ? $contentHeight : max(0, $height);
            $state->updateLayout($contentHeight, $viewportHeight, $context->requestRender);
            self::translateBox($childBox, $previousScrollTop - $state->scrollTop());
            $scrollView = $state instanceof ScrollView ? $state : null;
            if ($scrollView !== null && ($scrollView->primary || $context->primaryScrollView === null)) {
                $context->primaryScrollView = $scrollView;
            }
            $rect = new LayoutRect($x, $y, $safeWidth, $viewportHeight);
            $childClip = $clip->intersect($rect);
            $box = new LayoutBox(
                $component,
                $rect,
                $childClip,
                children: [$childBox],
                scrollView: $scrollView,
                scrollContentLines: self::renderCached($context, $node->component, $contentWidth),
            );
            $childBox->parent = $box;
            self::updateClips($childBox, $childClip);

            return $box;
        }

        $entries = Stack::visibleStackEntries($node->entries, $context->viewport);
        $gapTotal = max(0, count($entries) - 1) * $node->gap;

        if ($node->type === 'vstack') {
            $intrinsicHeights = array_map(
                static fn (StackLayoutEntry $entry): int => is_int($entry->basis) ? $entry->basis : self::measureHeight($context, $entry->component, $safeWidth),
                $entries,
            );
            $sizes = Stack::allocateStackSizes($entries, $intrinsicHeights, $height, $node->gap);
            $naturalHeight = array_sum($sizes) + $gapTotal;
            $allocatedHeight = $height === null ? $naturalHeight : max(0, $height);
            $rect = new LayoutRect($x, $y, $safeWidth, $allocatedHeight);
            $box = new LayoutBox($component, $rect, $clip->intersect($rect));
            $childY = $y;
            foreach ($entries as $index => $entry) {
                $child = self::layoutComponent($context, $entry->component, $x, $childY, $safeWidth, $sizes[$index], $box->clip);
                $child->parent = $box;
                $box->children[] = $child;
                $childY += $sizes[$index] + $node->gap;
            }

            return $box;
        }

        $intrinsicWidths = array_map(
            static fn (StackLayoutEntry $entry): int => is_int($entry->basis) ? $entry->basis : self::measureWidth($context, $entry->component, $safeWidth),
            $entries,
        );
        $widths = Stack::allocateStackSizes($entries, $intrinsicWidths, $safeWidth, $node->gap);
        $intrinsicHeights = [];
        foreach ($entries as $index => $entry) {
            $intrinsicHeights[] = self::measureHeight($context, $entry->component, max(1, $widths[$index]));
        }
        $allocatedHeight = $height === null ? ($intrinsicHeights === [] ? 0 : max($intrinsicHeights)) : max(0, $height);
        $rect = new LayoutRect($x, $y, $safeWidth, $allocatedHeight);
        $box = new LayoutBox($component, $rect, $clip->intersect($rect));
        $childX = $x;
        foreach ($entries as $index => $entry) {
            $naturalChildHeight = $intrinsicHeights[$index];
            $childHeight = $node->align === 'stretch' ? $allocatedHeight : min($allocatedHeight, $naturalChildHeight);
            $childY = $y;
            if ($node->align === 'center') {
                $childY += intdiv($allocatedHeight - $childHeight, 2);
            } elseif ($node->align === 'end') {
                $childY += $allocatedHeight - $childHeight;
            }
            $childWidth = $widths[$index];
            if ($childWidth === 0) {
                $box->children[] = new LayoutBox(
                    $entry->component,
                    new LayoutRect($childX, $childY, 0, $childHeight),
                    new LayoutRect($childX, $childY, 0, 0),
                    parent: $box,
                );
            } else {
                $child = self::layoutComponent($context, $entry->component, $childX, $childY, $childWidth, $childHeight, $box->clip);
                $child->parent = $box;
                $box->children[] = $child;
            }
            $childX += $childWidth + $node->gap;
        }

        return $box;
    }

    private static function replaceScrollbarCell(string $line, int $column, int $totalWidth, string $replacement, bool $preserveTargetBackground): string
    {
        if (TuiBase::isImageLine($line)) {
            return $line;
        }

        $range = Width::graphemeCellRange($line, $column);
        $start = $range['start'] ?? $column;
        $end = $range['end'] ?? $column + 1;
        $before = Width::sliceByColumn($line, 0, $start, true);
        $target = Width::sliceByColumn($line, $start, $end - $start, true);
        $after = Width::sliceByColumn($line, $end, max(0, $totalWidth - $end), true);

        $targetPrefix = '';
        $targetIndex = 0;
        while (($code = Ansi::at($target, $targetIndex)) !== null) {
            $targetPrefix .= $code[0];
            $targetIndex += $code[1];
        }
        $beforePadding = str_repeat(' ', max(0, $start - Width::visible($before)));
        $cellPaddingBefore = str_repeat(' ', max(0, $column - $start));
        $cellPaddingAfter = str_repeat(' ', max(0, $end - $column - 1));
        $targetStyle = "\x1b[0m\x1b]8;;\x07" . ($preserveTargetBackground ? Width::activeBackgroundAnsi($targetPrefix) : '');

        return $before . $beforePadding . $targetStyle . $cellPaddingBefore . $replacement . $cellPaddingAfter . $after;
    }

    /** @param list<string> $screen */
    private static function paintScrollbar(LayoutBox $box, array &$screen, int $totalWidth): void
    {
        $geometry = self::getScrollbarGeometry($box);
        $view = $box->scrollView;
        if ($geometry === null || $view === null) {
            return;
        }

        for ($offset = 0; $offset < $geometry->trackHeight; $offset++) {
            $row = $geometry->trackTop + $offset;
            if ($row < $box->clip->y || $row >= $box->clip->y + $box->clip->height || $row < 0 || $row >= count($screen)) {
                continue;
            }
            $isThumb = $row >= $geometry->thumbTop && $row < $geometry->thumbTop + $geometry->thumbHeight;
            $replacement = $isThumb
                ? ($view->scrollbarThumbStyle)($view->isScrollbarActive() ? '█' : '┃')
                : ($view->scrollbarTrackStyle)('│');
            $screen[$row] = self::replaceScrollbarCell($screen[$row] ?? '', $geometry->column, $totalWidth, $replacement, $view->scrollbar() !== 'always');
        }
    }

    /** @param list<string> $screen */
    private static function paintBox(LayoutBox $box, array &$screen, int $totalWidth): void
    {
        if ($box->lines !== null) {
            $offset = $box->lineOffset;
            $firstRow = max($box->rect->y, $box->clip->y, 0);
            $lastRow = min($box->rect->y + $box->rect->height, $box->clip->y + $box->clip->height, count($screen));
            for ($row = $firstRow; $row < $lastRow; $row++) {
                $sourceLine = $box->lines[$offset + $row - $box->rect->y] ?? null;
                if ($sourceLine === null) {
                    continue;
                }
                $line = (string) preg_replace(self::OSC133_ZONE_PREFIX, '', $sourceLine);
                // Fast path: a full-width box painting onto an untouched row uses the line as it is.
                if ($box->rect->x === 0 && $box->rect->width >= $totalWidth && (TuiBase::isImageLine($line) || $screen[$row] === '')) {
                    $screen[$row] = $line;
                } else {
                    $screen[$row] = Width::composite($screen[$row], $line, $box->rect->x, $box->rect->width, $totalWidth);
                }
            }
        }

        foreach ($box->children as $child) {
            self::paintBox($child, $screen, $totalWidth);
        }

        self::paintScrollbar($box, $screen, $totalWidth);
    }
}
