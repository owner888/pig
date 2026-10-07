<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\Width;

/**
 * A viewport that scrolls its content vertically.
 *
 * Ported from upstream's `components/scroll-view.ts`. Supports:
 * - Following output end automatically (`followEnd`)
 * - Scrolling up/down by lines
 * - Jumping to start or end
 * - Centered floating jump-to-end indicator when scrolled up away from bottom
 */
final class ScrollView extends Container
{
    private bool $followingEnd = true;

    private int $currentScrollTop = 0;

    private int $contentHeight = 0;

    /** @var list<string> the whole content as last rendered, which a selection reads its text from */
    private array $contentLines = [];

    private int $currentViewportHeight = 0;

    /** @var array{row: int, column: int, width: int}|null */
    private ?array $indicatorRect = null;

    public function __construct(
        private readonly Component $child,
        private readonly bool $followEnd = true,
    ) {
        $this->followingEnd = $followEnd;
        $this->addChild($this->child);
    }

    public function scrollTop(): int
    {
        return $this->currentScrollTop;
    }

    public function isFollowingEnd(): bool
    {
        return $this->followingEnd;
    }

    public function viewportHeight(): int
    {
        return $this->currentViewportHeight;
    }

    public function contentHeight(): int
    {
        return $this->contentHeight;
    }

    /** @return list<string> */
    public function contentLines(): array
    {
        return $this->contentLines;
    }

    /** @return array{row: int, column: int, width: int}|null */
    public function indicatorRect(): ?array
    {
        return $this->indicatorRect;
    }

    /**
     * Move by `$lines` and return the part that could not be moved — upstream returns the
     * remainder too, which is how a drag-selection stops auto-scrolling at either end.
     */
    public function scrollBy(int $lines): int
    {
        if ($lines === 0) {
            return 0;
        }

        $maxScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);
        $start = $this->followingEnd ? $maxScrollTop : $this->currentScrollTop;
        $next = max(0, min($maxScrollTop, $start + $lines));

        $this->currentScrollTop = $next;
        $this->followingEnd = $this->followEnd && $next === $maxScrollTop;

        return $lines - ($next - $start);
    }

    public function scrollToStart(): void
    {
        $this->currentScrollTop = 0;
        $this->followingEnd = $this->followEnd && $this->contentHeight <= $this->currentViewportHeight;
    }

    public function scrollToBottom(): void
    {
        $this->currentScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);
        $this->followingEnd = $this->followEnd;
    }

    /**
     * @param int $width
     * @param int $height
     * @param (Closure(): string)|null $indicator
     * @return list<string>
     */
    public function renderViewport(int $width, int $height, ?Closure $indicator = null): array
    {
        $this->currentViewportHeight = max(1, $height);
        $lines = $this->child->render($width);
        $this->contentLines = $lines;
        $this->contentHeight = count($lines);

        $maxScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);

        if ($this->followingEnd) {
            $this->currentScrollTop = $maxScrollTop;
        } else {
            $this->currentScrollTop = max(0, min($maxScrollTop, $this->currentScrollTop));
            if ($this->currentScrollTop === $maxScrollTop) {
                $this->followingEnd = true;
            }
        }

        // Slice visible lines for viewport
        $visibleLines = array_slice($lines, $this->currentScrollTop, $this->currentViewportHeight);

        // Pad top with empty lines if content is shorter than viewport, keeping it naturally settled
        while (count($visibleLines) < $this->currentViewportHeight) {
            array_unshift($visibleLines, '');
        }

        // Render scroll-to-end indicator if scrolled up away from end
        $this->indicatorRect = null;
        if (!$this->followingEnd && $this->contentHeight > $this->currentViewportHeight && $indicator !== null) {
            $label = $indicator();
            if ($label !== '') {
                $labelWidth = Width::visible($label);
                if ($labelWidth > 0 && $labelWidth <= $width) {
                    $column = max(0, (int) floor(($width - $labelWidth) / 2));
                    $lastRow = count($visibleLines) - 1;
                    $visibleLines[$lastRow] = Width::composite(
                        $visibleLines[$lastRow],
                        $label,
                        $column,
                        $labelWidth,
                        $width,
                    );
                    $this->indicatorRect = ['row' => $lastRow, 'column' => $column, 'width' => $labelWidth];
                }
            }
        }

        return $visibleLines;
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->child->render($width);
    }
}
