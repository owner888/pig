<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\LayoutComponent;
use Pig\Tui\ScrollLayoutNode;
use Pig\Tui\ScrollLayoutState;
use Pig\Tui\TuiError;

/**
 * A viewport that scrolls one child vertically — upstream's `components/scroll-view.ts`.
 *
 * The fullscreen renderer's layout engine gives it a height and calls `updateLayout()` every
 * frame; that is where following the end happens. Rendered outside a layout it is just its child.
 */
final class ScrollView extends Container implements LayoutComponent, ScrollLayoutState
{
    public readonly bool $followEnd;

    public readonly bool $primary;

    /** @var 'chain'|'contain' */
    public readonly string $overscroll;

    /** @var Closure(string): string */
    public readonly Closure $scrollbarTrackStyle;

    /** @var Closure(string): string */
    public readonly Closure $scrollbarThumbStyle;

    /** @var 'hidden'|'auto'|'always' */
    private string $currentScrollbar;

    private readonly float $scrollbarHideDelay;

    private int $currentScrollTop = 0;

    private int $contentHeight = 0;

    private int $currentViewportHeight = 0;

    private bool $followingEnd;

    private bool $followSuppressedAtEnd = false;

    /** @var (Closure(): void)|null */
    private ?Closure $requestRenderCallback = null;

    private bool $transientScrollbarVisible = false;

    private bool $scrollbarActive = false;

    private ?string $scrollbarHideTimer = null;

    /**
     * @param 'none'|'end' $follow
     * @param 'chain'|'contain' $overscroll
     * @param 'hidden'|'auto'|'always' $scrollbar
     * @param (Closure(string): string)|null $scrollbarTrackStyle
     * @param (Closure(string): string)|null $scrollbarThumbStyle
     */
    public function __construct(
        private readonly Component $child,
        string $follow = 'none',
        bool $primary = false,
        string $overscroll = 'chain',
        string $scrollbar = 'hidden',
        ?Closure $scrollbarTrackStyle = null,
        ?Closure $scrollbarThumbStyle = null,
        int $scrollbarHideDelayMs = 1000,
    ) {
        parent::addChild($child);
        $this->followEnd = $follow === 'end';
        $this->followingEnd = $this->followEnd;
        $this->primary = $primary;
        $this->overscroll = $overscroll;
        $this->currentScrollbar = $scrollbar;
        $this->scrollbarTrackStyle = $scrollbarTrackStyle ?? static fn (string $text): string => "\x1b[90m{$text}\x1b[39m";
        $this->scrollbarThumbStyle = $scrollbarThumbStyle ?? static fn (string $text): string => "\x1b[37m{$text}\x1b[39m";
        $this->scrollbarHideDelay = max(0, $scrollbarHideDelayMs) / 1000;
    }

    #[\Override]
    public function scrollTop(): int
    {
        return $this->currentScrollTop;
    }

    public function isFollowingEnd(): bool
    {
        return $this->followingEnd;
    }

    #[\Override]
    public function viewportHeight(): int
    {
        return $this->currentViewportHeight;
    }

    /** @return 'hidden'|'auto'|'always' */
    public function scrollbar(): string
    {
        return $this->currentScrollbar;
    }

    public function isScrollbarVisible(): bool
    {
        if ($this->currentScrollbar === 'always') {
            return $this->currentViewportHeight > 0;
        }

        return $this->currentScrollbar === 'auto'
            && $this->contentHeight > $this->currentViewportHeight
            && $this->transientScrollbarVisible;
    }

    public function isScrollbarActive(): bool
    {
        return $this->scrollbarActive;
    }

    /** @param 'hidden'|'auto'|'always' $scrollbar */
    public function setScrollbar(string $scrollbar): void
    {
        if ($scrollbar === $this->currentScrollbar) {
            return;
        }

        $this->currentScrollbar = $scrollbar;
        if ($scrollbar !== 'auto') {
            $this->hideTransientScrollbar();
        } elseif ($this->scrollbarActive) {
            $this->markScrollbarActivity();
        }
        $this->requestRender();
    }

    #[\Override]
    public function getContentWidth(int $width): int
    {
        return $this->currentScrollbar === 'always' && $width > 1 ? $width - 1 : $width;
    }

    public function setScrollbarActive(bool $active): void
    {
        if ($active === $this->scrollbarActive) {
            return;
        }

        $this->scrollbarActive = $active;
        $this->markScrollbarActivity();
        $this->requestRender();
    }

    /** Scroll to an absolute row; `$disableFollow` keeps follow-end off even at the end. */
    public function scrollTo(int $scrollTop, bool $disableFollow = false): void
    {
        $maxScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);
        $next = max(0, min($maxScrollTop, $scrollTop));
        $nextFollowSuppressedAtEnd = $disableFollow && $next === $maxScrollTop;
        $nextFollowingEnd = !$nextFollowSuppressedAtEnd && $this->followEnd && $next === $maxScrollTop;
        if ($next === $this->currentScrollTop
            && $nextFollowingEnd === $this->followingEnd
            && $nextFollowSuppressedAtEnd === $this->followSuppressedAtEnd) {
            return;
        }

        $moved = $next !== $this->currentScrollTop;
        $this->currentScrollTop = $next;
        $this->followingEnd = $nextFollowingEnd;
        $this->followSuppressedAtEnd = $nextFollowSuppressedAtEnd;
        if ($moved) {
            $this->markScrollbarActivity();
        }
        $this->requestRender();
    }

    /**
     * Move by `$lines` and return the part that could not be moved, which is how a wheel event
     * chains to an outer view and how a drag-selection stops auto-scrolling at either end.
     */
    public function scrollBy(int $lines): int
    {
        if ($lines === 0) {
            return 0;
        }

        $maxScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);
        $start = $this->followingEnd ? $maxScrollTop : $this->currentScrollTop;
        $next = max(0, min($maxScrollTop, $start + $lines));
        $moved = $next - $start;
        $wasFollowingEnd = $this->followingEnd;
        $this->currentScrollTop = $next;
        $this->followingEnd = $this->followEnd && $next === $maxScrollTop;
        $this->followSuppressedAtEnd = false;
        if ($moved !== 0) {
            $this->markScrollbarActivity();
        }
        if ($moved !== 0 || $this->followingEnd !== $wasFollowingEnd) {
            $this->requestRender();
        }

        return $lines - $moved;
    }

    public function scrollToStart(): void
    {
        $following = $this->followEnd && $this->contentHeight <= $this->currentViewportHeight;
        $changed = $this->currentScrollTop !== 0 || $this->followingEnd !== $following;
        $this->currentScrollTop = 0;
        $this->followingEnd = $following;
        $this->followSuppressedAtEnd = false;
        if ($changed) {
            $this->markScrollbarActivity();
            $this->requestRender();
        }
    }

    public function scrollToEnd(): void
    {
        $next = max(0, $this->contentHeight - $this->currentViewportHeight);
        $changed = $this->currentScrollTop !== $next || $this->followingEnd !== $this->followEnd;
        $this->currentScrollTop = $next;
        $this->followingEnd = $this->followEnd;
        $this->followSuppressedAtEnd = false;
        if ($changed) {
            $this->markScrollbarActivity();
            $this->requestRender();
        }
    }

    #[\Override]
    public function updateLayout(int $contentHeight, int $viewportHeight, Closure $requestRender): void
    {
        $this->contentHeight = max(0, $contentHeight);
        $this->currentViewportHeight = max(0, $viewportHeight);
        $this->requestRenderCallback = $requestRender;
        $maxScrollTop = max(0, $this->contentHeight - $this->currentViewportHeight);
        $this->currentScrollTop = $this->followingEnd ? $maxScrollTop : max(0, min($this->currentScrollTop, $maxScrollTop));
        if ($this->currentScrollTop < $maxScrollTop) {
            $this->followSuppressedAtEnd = false;
        }
        if ($this->followEnd && $this->currentScrollTop === $maxScrollTop && !$this->followSuppressedAtEnd) {
            $this->followingEnd = true;
        }
        if ($this->contentHeight <= $this->currentViewportHeight) {
            $this->hideTransientScrollbar();
        }
    }

    #[\Override]
    public function addChild(Component $child): void
    {
        throw new TuiError('ScrollView has exactly one child');
    }

    #[\Override]
    public function removeChild(Component $child): void
    {
        throw new TuiError('ScrollView child cannot be removed');
    }

    #[\Override]
    public function clear(): void
    {
        throw new TuiError('ScrollView child cannot be cleared');
    }

    #[\Override]
    public function render(int $width): array
    {
        $contentWidth = $this->getContentWidth($width);
        $lines = $this->child->render($contentWidth);

        return $contentWidth === $width ? $lines : array_map(static fn (string $line): string => "{$line} ", $lines);
    }

    #[\Override]
    public function layoutNode(): ScrollLayoutNode
    {
        return new ScrollLayoutNode($this->child, $this);
    }

    private function requestRender(): void
    {
        if ($this->requestRenderCallback !== null) {
            ($this->requestRenderCallback)();
        }
    }

    private function markScrollbarActivity(): void
    {
        if ($this->currentScrollbar !== 'auto' || $this->contentHeight <= $this->currentViewportHeight) {
            return;
        }

        $this->transientScrollbarVisible = true;
        if ($this->scrollbarHideTimer !== null) {
            Loop::get()->cancel($this->scrollbarHideTimer);
            $this->scrollbarHideTimer = null;
        }
        if ($this->scrollbarActive) {
            return;
        }

        $this->scrollbarHideTimer = Loop::get()->delay($this->scrollbarHideDelay, function (): void {
            $this->scrollbarHideTimer = null;
            $this->transientScrollbarVisible = false;
            $this->requestRender();
        });
    }

    private function hideTransientScrollbar(): void
    {
        $this->transientScrollbarVisible = false;
        if ($this->scrollbarHideTimer === null) {
            return;
        }

        Loop::get()->cancel($this->scrollbarHideTimer);
        $this->scrollbarHideTimer = null;
    }
}
