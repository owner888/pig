<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Components\AltScreenFlashContainer;
use Pig\Tui\Components\AltScreenSearchComponent;
use Pig\Tui\Components\ScrollView;

/**
 * The fullscreen renderer — upstream's `TuiAltScreen` in `tui-alt-screen.ts`.
 *
 * Owns the alternate screen. Each frame is laid out by `Layout` into exactly the window's
 * rectangle — a layout root set with `setLayoutRoot()`, or else the children inside an implicit
 * follow-end scroll view — and written row by row. Mouse reporting is on, so the wheel, the
 * scrollbar, text selection and copy, and the jump-to-latest click are handled here rather than
 * by the terminal.
 *
 * Not ported yet: Kitty and iTerm2 image placement.
 */
class TuiAltScreen extends TuiBase implements ViewportTUI
{
    public const string MODE = 'fullscreen';

    private const string ENTER_ALT_SCREEN = "\x1b[?1049h";
    private const string EXIT_ALT_SCREEN = "\x1b[?1049l";
    private const string DISABLE_AUTOWRAP = "\x1b[?7l";
    private const string ENABLE_AUTOWRAP = "\x1b[?7h";
    private const string ENABLE_BUTTON_MOTION_MOUSE = "\x1b[?1000h\x1b[?1002h\x1b[?1004h\x1b[?1006h";
    private const string ENABLE_ALL_MOTION_MOUSE = "\x1b[?1000h\x1b[?1002h\x1b[?1003h\x1b[?1004h\x1b[?1006h";
    private const string DISABLE_MOUSE = "\x1b[?1006l\x1b[?1004l\x1b[?1003l\x1b[?1002l\x1b[?1000l";
    private const string FOCUS_IN = "\x1b[I";
    private const string FOCUS_OUT = "\x1b[O";
    private const string BEGIN_SYNCHRONIZED_OUTPUT = "\x1b[?2026h";
    private const string END_SYNCHRONIZED_OUTPUT = "\x1b[?2026l";
    private const string OSC133_ZONE_PREFIX = '/^(?:\x1b\]133;[ABC](?:\x07|\x1b\\\\))+/';
    private const string OSC133_PROMPT_START = '/^\x1b\]133;A(?:\x07|\x1b\\\\)/';
    private const int PAGE_SCROLL_OVERLAP = 4;
    private const int ALT_WHEEL_SCROLL_MULTIPLIER = 5;
    private const float DOUBLE_CLICK_INTERVAL = 0.5;
    private const float COPY_ERROR_FLASH_DURATION = 5.0;
    private const float SELECTION_AUTO_SCROLL_INTERVAL = 0.05;

    /**
     * Regular mode delegates double-click selection to the terminal emulator. Fullscreen owns mouse
     * selection, so mirror common terminal word-selection behavior by keeping paths and kebab-case
     * tokens whole.
     */
    private const array TERMINAL_WORD_SELECTION_JOINERS = ['/', '-'];

    /**
     * One mouse or focus report, as found inside a read that may hold several. Upstream's input
     * is split into single sequences before it gets here; pig's reads are not, so the reports are
     * picked out of whatever arrived.
     */
    private const string MOUSE_REPORT = '/\x1b\[<\d+;\d+;\d+[Mm]|\x1b\[M[\x00-\xff]{3}|\x1b\[[IO]/';

    /** @var list<string> the frame as written, selection and flashes included */
    private array $previousScreen = [];

    private ?Component $layoutRoot = null;

    private ?LayoutFrame $currentLayout = null;

    private readonly Component $implicitDocument;

    private readonly ScrollView $implicitScrollView;

    private readonly AltScreenFlashContainer $flashes;

    /** Upstream's `altScreenActive`: stop() may run twice, and the second must not print the document again. */
    private bool $altScreenActive = false;

    /**
     * A point a selection starts or ends at: a row of a scroll view's content when `scrollView` is
     * set, a screen row otherwise. `boundary` points lie between cells.
     *
     * @var array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}|null
     */
    private ?array $selectionAnchor = null;

    /** @var array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}|null */
    private ?array $selectionFocus = null;

    /** @var 'character'|'word'|'line' */
    private string $selectionGranularity = 'character';

    /** @var array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}}|null */
    private ?array $selectionInitialRange = null;

    /** @var array{timestamp: float, count: int, row: int, scrollView: ?ScrollView, wordStart: int, wordEnd: int}|null */
    private ?array $lastClick = null;

    /** @var array{x: int, y: int}|null */
    private ?array $selectionDragPointer = null;

    /** @var -1|0|1 */
    private int $selectionAutoScrollDirection = 0;

    private ?string $selectionAutoScrollTimer = null;

    private bool $selectionPressActive = false;

    private bool $selectionDragged = false;

    /** @var array{scrollView: ScrollView, grabOffset: int}|null */
    private ?array $scrollbarDrag = null;

    private ?ScrollView $scrollbarHover = null;

    /** @var array{row: int, column: int, width: int}|null */
    private ?array $scrollToEndIndicatorRect = null;

    /**
     * Upstream's `ActiveSearch`: the open transcript search.
     *
     * @var array{component: AltScreenSearchComponent, index: AltScreenSearchIndex, overlay: ?OverlayHandle, query: string, matches: list<AltScreenSearchMatch>, selectedIndex: int, selectedKey: ?string, anchorRow: int, selectionMode: 'query'|'retain'|'next'|'previous'}|null
     */
    private ?array $activeSearch = null;

    private ?string $pressedUrl = null;

    private ?TuiMouseDispatchTarget $mouseCapture = null;

    private ?TuiMouseDispatchTarget $mousePressTarget = null;

    /** @var array{x: int, y: int}|null */
    private ?array $mousePressPoint = null;

    private bool $mousePressMoved = false;

    /** @var array{timestamp: float, count: int, component: Component, x: int, y: int}|null */
    private ?array $lastComponentClick = null;

    private readonly WheelScrollAccelerator $wheelScroll;

    private readonly bool $mouseEnabled;

    /** @var Closure(string): string */
    private readonly Closure $searchMatchStyle;

    /** @var Closure(string): string */
    private readonly Closure $searchCurrentMatchStyle;

    /** @var Closure(string, bool): string */
    private readonly Closure $searchNavigationButtonStyle;

    /** @var (Closure(string): void)|null */
    private readonly ?Closure $openUrl;

    /** @var (Closure(): void)|null */
    private readonly ?Closure $onRightClickPaste;

    /** @var (Closure(): string)|null */
    private readonly ?Closure $scrollToEndIndicator;

    private bool $copyOnSelect;

    /** @var (Closure(string): (bool|string))|null */
    private readonly ?Closure $copySelection;

    public function __construct(
        Terminal $terminal,
        ?bool $showHardwareCursor = null,
        ?string $logDirectory = null,
        ?TuiAltScreenOptions $options = null,
    ) {
        parent::__construct($terminal, $showHardwareCursor, $logDirectory);
        $options ??= new TuiAltScreenOptions();
        $this->implicitDocument = new class (
            fn (int $width): array => parent::render($width),
            fn (TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null => parent::handleMouse($event),
            fn () => $this->invalidateChildren(),
        ) implements Component, MouseHandler {
            public function __construct(
                private readonly Closure $renderChildren,
                private readonly Closure $handleChildMouse,
                private readonly Closure $invalidateChildren,
            ) {
            }

            #[\Override]
            public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
            {
                return ($this->handleChildMouse)($event);
            }

            #[\Override]
            public function render(int $width): array
            {
                return ($this->renderChildren)($width);
            }

            #[\Override]
            public function invalidate(): void
            {
                ($this->invalidateChildren)();
            }
        };
        $this->implicitScrollView = new ScrollView($this->implicitDocument, follow: 'end', primary: true);
        $this->flashes = new AltScreenFlashContainer(fn () => $this->requestRender());
        $this->wheelScroll = new WheelScrollAccelerator($options->wheelScrollLines);
        $this->mouseEnabled = $options->mouse;
        $this->searchMatchStyle = $options->searchMatchStyle ?? static fn (string $text): string => "\x1b[4m{$text}\x1b[24m";
        $this->searchCurrentMatchStyle = $options->searchCurrentMatchStyle ?? static fn (string $text): string => "\x1b[1;7m{$text}\x1b[22;27m";
        $this->searchNavigationButtonStyle = $options->searchNavigationButtonStyle ?? static fn (string $text, bool $hovered): string => $text;
        $this->openUrl = $options->openUrl;
        $this->onRightClickPaste = $options->onRightClickPaste;
        $this->scrollToEndIndicator = $options->scrollToEndIndicator;
        $this->copyOnSelect = $options->copyOnSelect;
        $this->copySelection = $options->copySelection;
        $this->addInputListener($this->handleViewportInput(...));
    }

    public function viewportTop(): int
    {
        return $this->getPrimaryScrollView()->scrollTop();
    }

    public function isFollowingOutput(): bool
    {
        return $this->getPrimaryScrollView()->isFollowingEnd();
    }

    /** @param int|'auto' $lines */
    public function setWheelScrollLines(int|string $lines): void
    {
        $this->wheelScroll->setLines($lines);
    }

    public function getCopyOnSelect(): bool
    {
        return $this->copyOnSelect;
    }

    public function setCopyOnSelect(bool $enabled): void
    {
        $this->copyOnSelect = $enabled;
    }

    /** Whether the fullscreen viewport has a non-empty active text selection. */
    public function hasActiveSelection(): bool
    {
        return $this->getActiveSelectionText() !== null;
    }

    /** Copy the active selection, if any, the way a mouse release would. */
    public function copyActiveSelectionToClipboard(): bool
    {
        $text = $this->getActiveSelectionText();

        return $text !== null && $this->copyTextToClipboard($text);
    }

    /** @return list<string> the last frame, one line per terminal row, as written */
    public function getScreenLines(): array
    {
        return $this->previousScreen;
    }

    #[\Override]
    public function setLayoutRoot(?Component $component): void
    {
        if ($this->layoutRoot === $component) {
            return;
        }

        $this->layoutRoot = $component;
        $this->currentLayout = null;
        $this->requestRender();
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->layoutRoot?->render($width) ?? parent::render($width);
    }

    /** Show a transient message in the alternate-screen flash stack. */
    public function flash(string $message, float $duration = 1.0): void
    {
        $this->flashes->flash($message, $duration);
    }

    public function scrollBy(int $lines): void
    {
        $this->getPrimaryScrollView()->scrollBy($lines);
        $this->requestRender();
    }

    public function scrollToTop(): void
    {
        $this->getPrimaryScrollView()->scrollToStart();
        $this->requestRender();
    }

    public function scrollToBottom(): void
    {
        $this->getPrimaryScrollView()->scrollToEnd();
        $this->requestRender();
    }

    /** -1 for the previous OSC 133 prompt above the top row, 1 for the next one below it. */
    private function scrollToPrompt(int $direction): void
    {
        if ($this->currentLayout === null) {
            return;
        }
        $scrollView = $this->getPrimaryScrollView();
        $lines = Layout::getScrollViewBox($this->currentLayout, $scrollView)?->scrollContentLines;
        if ($lines === null) {
            return;
        }

        for ($row = $scrollView->scrollTop() + $direction; $row >= 0 && $row < count($lines); $row += $direction) {
            if (preg_match(self::OSC133_PROMPT_START, $lines[$row] ?? '') !== 1) {
                continue;
            }
            $scrollView->scrollTo($row);
            $this->requestRender();

            return;
        }
    }

    private function toggleSearch(): void
    {
        if ($this->activeSearch !== null) {
            $this->closeSearch();

            return;
        }
        $component = new AltScreenSearchComponent(
            fn (string $query) => $this->updateSearchQuery($query),
            $this->searchNavigationButtonStyle,
        );
        $this->activeSearch = [
            'component' => $component,
            'index' => new AltScreenSearchIndex(),
            'overlay' => null,
            'query' => '',
            'matches' => [],
            'selectedIndex' => -1,
            'selectedKey' => null,
            'anchorRow' => $this->getPrimaryScrollView()->scrollTop(),
            'selectionMode' => 'query',
        ];
        $overlay = $this->showOverlay($component, new OverlayOptions(width: '40%', minWidth: 32, anchor: 'top-right', margin: 1));
        $this->activeSearch['overlay'] = $overlay;
    }

    private function closeSearch(): void
    {
        $search = $this->activeSearch;
        if ($search === null) {
            return;
        }
        $this->activeSearch = null;
        $search['overlay']?->hide();
        $this->requestRender();
    }

    private function updateSearchQuery(string $query): void
    {
        if ($this->activeSearch === null || $query === $this->activeSearch['query']) {
            return;
        }
        $selected = $this->activeSearch['matches'][$this->activeSearch['selectedIndex']] ?? null;
        $this->activeSearch['anchorRow'] = $selected?->segments[0]?->row ?? $this->getPrimaryScrollView()->scrollTop();
        $this->activeSearch['query'] = $query;
        $this->activeSearch['selectionMode'] = 'query';
        $this->activeSearch['component']->setResult(-1, 0);
        $this->requestRender();
    }

    /** @param -1|1 $direction */
    private function navigateSearch(int $direction): void
    {
        if ($this->activeSearch === null || $this->activeSearch['query'] === '') {
            return;
        }
        $this->activeSearch['selectionMode'] = $direction < 0 ? 'previous' : 'next';
        $this->requestRender();
    }

    /** @return -1|1|null */
    private function getSearchNavigationDirectionAt(int $x, int $y): ?int
    {
        $bounds = ($this->activeSearch['overlay'] ?? null)?->getBounds();
        if ($this->activeSearch === null || $bounds === null) {
            return null;
        }
        if ($x < $bounds->col || $x >= $bounds->col + $bounds->width || $y < $bounds->row || $y >= $bounds->row + $bounds->height) {
            return null;
        }

        return $this->activeSearch['component']->getNavigationDirectionAt($y - $bounds->row, $x - $bounds->col);
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleSearchMouseEvent(array $event): bool
    {
        if ($this->activeSearch === null) {
            return false;
        }
        $direction = $this->getSearchNavigationDirectionAt($event['x'], $event['y']);
        if ($this->activeSearch['component']->setHoveredNavigationDirection($direction)) {
            $this->requestRender();
        }
        if ($direction === null || $event['release'] || ($event['button'] & 32) !== 0 || ($event['button'] & 3) !== 0) {
            return false;
        }
        $this->navigateSearch($direction);

        return true;
    }

    /** @return bool whether the selected match was scrolled into view, so the frame must be laid out again */
    private function refreshSearch(LayoutFrame $layout): bool
    {
        if ($this->activeSearch === null) {
            return false;
        }
        $search = &$this->activeSearch;
        $scrollView = $layout->primaryScrollView ?? $this->implicitScrollView;
        $box = Layout::getScrollViewBox($layout, $scrollView);
        $lines = $box?->scrollContentLines;
        if ($lines === null || trim($search['query']) === '') {
            $search['matches'] = [];
            $search['selectedIndex'] = -1;
            $search['selectedKey'] = null;
            $search['selectionMode'] = 'retain';
            $search['component']->setResult(-1, 0);

            return false;
        }

        $shouldRevealSelection = $search['selectionMode'] !== 'retain';
        $result = $search['index']->search($lines, $search['query']);
        $matches = $result->matches;
        $search['matches'] = $matches;
        if (!$result->changed && $search['selectionMode'] === 'retain') {
            return false;
        }

        $exactIndex = $search['selectedIndex'];
        if ($result->changed) {
            $exactIndex = -1;
            if ($search['selectedKey'] !== null) {
                foreach ($matches as $index => $match) {
                    if (AltScreenSearch::getAltScreenSearchMatchKey($match) === $search['selectedKey']) {
                        $exactIndex = $index;
                        break;
                    }
                }
            }
        }
        $count = count($matches);
        $selectedIndex = -1;
        if ($count > 0) {
            if ($search['selectionMode'] === 'query') {
                $low = 0;
                $high = $count;
                while ($low < $high) {
                    $middle = $low + intdiv($high - $low, 2);
                    if (($matches[$middle]->segments[0]->row ?? 0) < $search['anchorRow']) {
                        $low = $middle + 1;
                    } else {
                        $high = $middle;
                    }
                }
                $selectedIndex = $low < $count ? $low : 0;
            } elseif ($search['selectionMode'] === 'next') {
                $baseIndex = $exactIndex >= 0 ? $exactIndex : min($search['selectedIndex'], $count - 1);
                $selectedIndex = $baseIndex < 0 ? 0 : ($baseIndex + 1) % $count;
            } elseif ($search['selectionMode'] === 'previous') {
                $baseIndex = $exactIndex >= 0 ? $exactIndex : min($search['selectedIndex'], $count - 1);
                $selectedIndex = $baseIndex < 0 ? $count - 1 : ($baseIndex - 1 + $count) % $count;
            } else {
                $selectedIndex = $exactIndex >= 0 ? $exactIndex : min(max(0, $search['selectedIndex']), $count - 1);
            }
        }

        $search['selectedIndex'] = $selectedIndex;
        $search['selectedKey'] = $selectedIndex >= 0 ? AltScreenSearch::getAltScreenSearchMatchKey($matches[$selectedIndex]) : null;
        $search['selectionMode'] = 'retain';
        $search['component']->setResult($selectedIndex, $count);
        if (!$shouldRevealSelection) {
            return false;
        }

        $selected = $matches[$selectedIndex] ?? null;
        $firstSegment = $selected?->segments[0] ?? null;
        $lastSegment = $selected === null ? null : ($selected->segments[count($selected->segments) - 1] ?? null);
        if ($box === null || $firstSegment === null || $lastSegment === null || $scrollView->viewportHeight() <= 0) {
            return false;
        }
        $before = $scrollView->scrollTop();
        $visibleBottom = $before + $scrollView->viewportHeight() - 1;
        $target = $before;
        if ($firstSegment->row < $before || $lastSegment->row > $visibleBottom) {
            $target = $firstSegment->row - intdiv($scrollView->viewportHeight(), 3);
        }
        $scrollView->scrollTo($target, disableFollow: true);

        return $scrollView->scrollTop() !== $before;
    }

    private function applySearchTextHighlight(string $text, bool $current): string
    {
        $style = $current ? $this->searchCurrentMatchStyle : $this->searchMatchStyle;
        $result = '';
        $plainStart = 0;
        $index = 0;
        $length = strlen($text);
        while ($index < $length) {
            $ansi = Ansi::at($text, $index);
            if ($ansi === null) {
                $index++;
                continue;
            }
            if ($index > $plainStart) {
                $result .= $style(substr($text, $plainStart, $index - $plainStart));
            }
            $result .= $ansi[0];
            $index += $ansi[1];
            $plainStart = $index;
        }
        if ($plainStart < $length) {
            $result .= $style(substr($text, $plainStart));
        }

        return $result;
    }

    /**
     * @param list<string> $screen
     *
     * @return list<string>
     */
    private function applySearchHighlights(array $screen, LayoutFrame $layout): array
    {
        $search = $this->activeSearch;
        if ($search === null || $search['selectedIndex'] < 0 || $search['matches'] === []) {
            return $screen;
        }
        $scrollView = $layout->primaryScrollView ?? $this->implicitScrollView;
        $box = Layout::getScrollViewBox($layout, $scrollView);
        if ($box === null) {
            return $screen;
        }

        /** @var array<int, list<array{startCol: int, endCol: int, current: bool}>> $rangesByRow */
        $rangesByRow = [];
        $scrollbarColumn = Layout::getScrollbarGeometry($box)?->column;
        $minRow = max(0, $box->rect->y, $box->clip->y);
        $maxRow = min(count($screen), $box->rect->y + $box->rect->height, $box->clip->y + $box->clip->height);
        $minColumn = max(0, $box->rect->x, $box->clip->x);
        $maxColumn = min(
            $this->terminal->columns(),
            $box->rect->x + $box->rect->width,
            $box->clip->x + $box->clip->width,
            $scrollbarColumn ?? PHP_INT_MAX,
        );
        $minContentRow = $scrollView->scrollTop() + $minRow - $box->rect->y;
        $maxContentRow = $scrollView->scrollTop() + $maxRow - $box->rect->y - 1;
        $matches = $search['matches'];
        $count = count($matches);
        $low = 0;
        $high = $count;
        while ($low < $high) {
            $middle = $low + intdiv($high - $low, 2);
            $segments = $matches[$middle]->segments;
            $lastRow = $segments[count($segments) - 1]->row ?? -1;
            if ($lastRow < $minContentRow) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        for ($matchIndex = $low; $matchIndex < $count; $matchIndex++) {
            $match = $matches[$matchIndex];
            if (($match->segments[0]->row ?? 0) > $maxContentRow) {
                break;
            }
            foreach ($match->segments as $segment) {
                $row = $box->rect->y + $segment->row - $scrollView->scrollTop();
                if ($row < $minRow || $row >= $maxRow) {
                    continue;
                }
                $startCol = max($minColumn, $box->rect->x + $segment->startCol);
                $endCol = min($maxColumn, $box->rect->x + $segment->endCol);
                if ($endCol <= $startCol) {
                    continue;
                }
                $rangesByRow[$row][] = ['startCol' => $startCol, 'endCol' => $endCol, 'current' => $matchIndex === $search['selectedIndex']];
            }
        }

        foreach ($rangesByRow as $row => $ranges) {
            $line = $screen[$row] ?? '';
            if (self::isImageLine($line)) {
                continue;
            }
            $lineWidth = Width::visible($line);
            usort($ranges, static fn (array $a, array $b): int => $b['startCol'] <=> $a['startCol']);
            foreach ($ranges as $range) {
                $startCol = min($range['startCol'], $lineWidth);
                $endCol = min($range['endCol'], $lineWidth);
                if ($endCol <= $startCol) {
                    continue;
                }
                $before = Width::sliceByColumn($line, 0, $startCol, true);
                $highlighted = Width::sliceByColumn($line, $startCol, $endCol - $startCol, true);
                $after = Width::sliceByColumn($line, $endCol, max(0, $lineWidth - $endCol), true);
                $line = $before . $this->applySearchTextHighlight($highlighted, $range['current']) . $after;
            }
            $screen[$row] = $line;
        }

        return $screen;
    }

    private function invalidateChildren(): void
    {
        foreach ($this->children as $child) {
            $child->invalidate();
        }
    }

    private function getPrimaryScrollView(): ScrollView
    {
        return $this->currentLayout?->primaryScrollView ?? $this->implicitScrollView;
    }

    #[\Override]
    protected function beforeTerminalStart(): void
    {
        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = false;
        $this->stopScrollbarHover();
        $this->stopScrollbarDrag();
        $this->flashes->dispose();
        $this->altScreenActive = true;
        $this->selectionAnchor = null;
        $this->selectionFocus = null;
        $this->selectionGranularity = 'character';
        $this->selectionInitialRange = null;
        $this->lastClick = null;
        $this->pressedUrl = null;
        $this->selectionDragged = false;
        $this->clearComponentMouseGesture();
        $this->lastComponentClick = null;
        $this->resetRenderState();
        $term = strtolower((string) getenv('TERM'));
        // Multiplexers can lag when every pointer movement is forwarded. Button-motion
        // tracking preserves clicks, wheel events, selections, and scrollbar dragging.
        $mouseSequence = getenv('TMUX') !== false
            || getenv('ZELLIJ') !== false
            || getenv('STY') !== false
            || str_starts_with($term, 'tmux')
            || str_starts_with($term, 'screen')
            ? self::ENABLE_BUTTON_MOTION_MOUSE
            : self::ENABLE_ALL_MOTION_MOUSE;
        $this->terminal->write(
            self::ENTER_ALT_SCREEN . self::DISABLE_AUTOWRAP . ($this->mouseEnabled ? $mouseSequence : '') . "\x1b[2J\x1b[H\x1b[?25l",
        );
    }

    #[\Override]
    protected function beforeTerminalStop(TuiStopOptions $options): void
    {
        $this->closeSearch();
        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = false;
        $this->stopScrollbarHover();
        $this->stopScrollbarDrag();
        $this->clearComponentMouseGesture();
        $this->flashes->dispose();
        if (!$this->altScreenActive) {
            return;
        }

        $this->terminal->write(
            self::BEGIN_SYNCHRONIZED_OUTPUT . ($this->mouseEnabled ? self::DISABLE_MOUSE : '') . self::ENABLE_AUTOWRAP . self::END_SYNCHRONIZED_OUTPUT,
        );
    }

    /**
     * Leave the alternate screen and, unless another renderer is taking over, print the whole
     * document into the normal screen so the conversation is still there in the scrollback.
     */
    #[\Override]
    protected function afterTerminalStop(TuiStopOptions $options): void
    {
        if (!$this->altScreenActive) {
            return;
        }
        $this->altScreenActive = false;

        if ($options->preserveScreen) {
            $this->terminal->write(self::BEGIN_SYNCHRONIZED_OUTPUT . self::EXIT_ALT_SCREEN . "\x1b[?25h" . self::END_SYNCHRONIZED_OUTPUT);

            return;
        }

        $width = max(1, $this->terminal->columns());
        $buffer = self::BEGIN_SYNCHRONIZED_OUTPUT . self::EXIT_ALT_SCREEN . self::DISABLE_AUTOWRAP;
        $document = array_map(
            static fn (string $line): string => str_replace(TUI::CURSOR_MARKER, '', (string) preg_replace(self::OSC133_ZONE_PREFIX, '', $line)),
            $this->render($width),
        );
        foreach ($this->applyLineResets($document) as $row => $line) {
            if (!self::isImageLine($line) && Width::visible($line) > $width) {
                $line = Width::sliceByColumn($line, 0, $width, true);
            }
            $buffer .= ($row > 0 ? "\r\n" : '') . "\r\x1b[2K" . $line;
        }
        $buffer .= "\x1b[0m" . self::ENABLE_AUTOWRAP . "\r\n\x1b[?25h" . self::END_SYNCHRONIZED_OUTPUT;
        $this->terminal->write($buffer);
    }

    /** @return list<Component> */
    #[\Override]
    protected function getMountedRoots(): array
    {
        return $this->layoutRoot !== null ? [$this->layoutRoot] : $this->children;
    }

    #[\Override]
    protected function resetRenderState(): void
    {
        $this->previousScreen = [];
        $this->previousWidth = 0;
        $this->previousHeight = 0;
        $this->currentLayout = null;
    }

    /**
     * Take the mouse and focus reports out of a read and act on them; what is left is keyboard
     * input for the other listeners and the focused component. Upstream's `handleViewportInput()`.
     * Inside a bracketed paste nothing is taken out: pasted text is text, whatever bytes it holds.
     *
     * @return array{consume?: bool, data?: string}|null
     */
    private function handleViewportInput(string $data): ?array
    {
        $rest = $data;
        if (str_contains($data, "\x1b[") && !str_contains($data, "\x1b[200~")) {
            // A report the viewport defers (a wheel over a focused overlay nothing took) stays in
            // the read, for the focused component — upstream returns `undefined` for it.
            $rest = preg_replace_callback(
                self::MOUSE_REPORT,
                fn (array $match): string => $this->handleViewportReport($match[0]) ? '' : $match[0],
                $data,
            );

            if ($rest === null) {
                throw new TuiError('Splitting mouse reports failed: ' . preg_last_error_msg());
            }
            if ($rest === '') {
                return ['consume' => true];
            }
        }

        if ($this->handleViewportKey($rest)) {
            return ['consume' => true];
        }

        return $rest === $data ? null : ['data' => $rest];
    }

    private function shouldDeferViewportInputToOverlay(): bool
    {
        return $this->isOverlayFocused() && ($this->activeSearch['overlay'] ?? null)?->isFocused() !== true;
    }

    private function clearComponentMouseGesture(): void
    {
        $this->mouseCapture = null;
        $this->mousePressTarget = null;
        $this->mousePressPoint = null;
        $this->mousePressMoved = false;
    }

    /** Upstream's `tui.altScreen.*` half of `handleViewportInput()`: true when the key was ours. */
    private function handleViewportKey(string $data): bool
    {
        $keybindings = Keybindings::getKeybindings();
        $isRelease = Keys::isKeyRelease($data);
        if ($keybindings->matches($data, 'tui.altScreen.search')) {
            if (!$isRelease) {
                $this->toggleSearch();
            }

            return true;
        }
        if (($this->activeSearch['overlay'] ?? null)?->isFocused() === true) {
            if ($keybindings->matches($data, 'tui.altScreen.searchNext')) {
                if (!$isRelease) {
                    $this->navigateSearch(1);
                }

                return true;
            }
            if ($keybindings->matches($data, 'tui.altScreen.searchPrevious')) {
                if (!$isRelease) {
                    $this->navigateSearch(-1);
                }

                return true;
            }
            if ($keybindings->matches($data, 'tui.altScreen.searchClose')) {
                if (!$isRelease) {
                    $this->closeSearch();
                }

                return true;
            }
        }
        if ($this->shouldDeferViewportInputToOverlay()) {
            return false;
        }
        if ($keybindings->matches($data, 'tui.altScreen.pageUp')) {
            if (!$isRelease) {
                $this->scrollBy(-max(1, $this->getPrimaryScrollView()->viewportHeight() - self::PAGE_SCROLL_OVERLAP));
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.pageDown')) {
            if (!$isRelease) {
                $this->scrollBy(max(1, $this->getPrimaryScrollView()->viewportHeight() - self::PAGE_SCROLL_OVERLAP));
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.halfPageUp')) {
            if (!$isRelease) {
                $this->scrollBy(-max(1, intdiv($this->getPrimaryScrollView()->viewportHeight(), 2)));
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.halfPageDown')) {
            if (!$isRelease) {
                $this->scrollBy(max(1, intdiv($this->getPrimaryScrollView()->viewportHeight(), 2)));
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.lineUp')) {
            if (!$isRelease) {
                $this->scrollBy(-1);
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.lineDown')) {
            if (!$isRelease) {
                $this->scrollBy(1);
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.previousPrompt')) {
            if (!$isRelease) {
                $this->scrollToPrompt(-1);
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.nextPrompt')) {
            if (!$isRelease) {
                $this->scrollToPrompt(1);
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.top')) {
            if (!$isRelease) {
                $this->scrollToTop();
            }

            return true;
        }
        if ($keybindings->matches($data, 'tui.altScreen.bottom')) {
            if (!$isRelease) {
                $this->scrollToBottom();
            }

            return true;
        }

        return false;
    }

    /** @return bool false when the report is left for the focused component */
    private function handleViewportReport(string $report): bool
    {
        if ($report === self::FOCUS_OUT) {
            $hadActiveSelection = $this->selectionPressActive;
            $hadNonEmptyActiveSelection = $hadActiveSelection && $this->getSelectionBounds() !== null;
            $this->selectionPressActive = false;
            $this->stopSelectionAutoScroll();
            $this->stopScrollbarHover();
            if ($this->activeSearch !== null && $this->activeSearch['component']->setHoveredNavigationDirection(null)) {
                $this->requestRender();
            }
            $this->stopScrollbarDrag();
            $this->pressedUrl = null;
            $this->selectionDragged = false;
            $this->clearComponentMouseGesture();
            $this->lastComponentClick = null;
            if ($hadActiveSelection) {
                $this->selectionAnchor = null;
                $this->selectionFocus = null;
                $this->selectionGranularity = 'character';
                $this->selectionInitialRange = null;
                if ($hadNonEmptyActiveSelection) {
                    $this->requestRender();
                }
            }
            $this->lastClick = null;

            return true;
        }

        if ($report === self::FOCUS_IN) {
            return true;
        }

        $wheel = self::parseWheelEvent($report);
        if ($wheel !== null) {
            $lines = $this->wheelScroll->next($wheel['direction'], microtime(true) * 1000);
            // SGR mouse button codes use bit 3 (value 8) for the Alt modifier.
            $delta = $wheel['direction'] * (($wheel['button'] & 8) !== 0 ? $lines * self::ALT_WHEEL_SCROLL_MULTIPLIER : $lines);
            $event = $this->createMouseEvent('wheel', $wheel['button'], $wheel['x'], $wheel['y'], wheelDelta: $delta);
            $overlay = $this->dispatchMouseToOverlay($event);
            $result = $overlay['result'] ?? ($overlay['hit'] ? null : $this->dispatchMouseToLayout($event));
            if ($result !== null) {
                if ($this->applyMouseDispatchResult($event, $result)) {
                    $this->requestRender();
                }

                return true;
            }
            if ($this->shouldDeferViewportInputToOverlay()) {
                return false;
            }
            $this->routeWheel($wheel['x'], $wheel['y'], $delta);

            return true;
        }

        $event = self::parseSgrMouseEvent($report);
        if ($event !== null) {
            $this->handleMouseEvent($event);
        }

        // Anything else is a legacy X10 report that is not a wheel: swallowed, never typed.
        return true;
    }

    /** @return array{direction: -1|1, x: int, y: int, button: int}|null */
    private static function parseWheelEvent(string $data): ?array
    {
        if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)[Mm]$/', $data, $sgr) === 1) {
            $button = (int) $sgr[1];
            $direction = $button & 3;
            if (($button & 64) === 0 || ($direction !== 0 && $direction !== 1)) {
                return null;
            }

            return ['direction' => $direction === 0 ? -1 : 1, 'x' => (int) $sgr[2] - 1, 'y' => (int) $sgr[3] - 1, 'button' => $button];
        }

        if (strlen($data) === 6 && str_starts_with($data, "\x1b[M")) {
            $button = ord($data[3]) - 32;
            $direction = $button & 3;
            if (($button & 64) === 0 || ($direction !== 0 && $direction !== 1)) {
                return null;
            }

            return ['direction' => $direction === 0 ? -1 : 1, 'x' => ord($data[4]) - 33, 'y' => ord($data[5]) - 33, 'button' => $button];
        }

        return null;
    }

    /** @return array{button: int, x: int, y: int, release: bool}|null */
    private static function parseSgrMouseEvent(string $data): ?array
    {
        if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $data, $match) !== 1) {
            return null;
        }

        return ['button' => (int) $match[1], 'x' => (int) $match[2] - 1, 'y' => (int) $match[3] - 1, 'release' => $match[4] === 'm'];
    }

    /** The innermost scroll view under the pointer takes the wheel; what it cannot use chains outwards. */
    private function routeWheel(int $x, int $y, int $delta): void
    {
        $remaining = $delta;
        $seen = [];
        foreach ($this->currentLayout === null ? [] : Layout::getScrollViewsAt($this->currentLayout, $x, $y) as $scrollView) {
            $seen[] = $scrollView;
            $remaining = $scrollView->scrollBy($remaining);
            if ($remaining === 0 || $scrollView->overscroll === 'contain') {
                break;
            }
        }
        $primary = $this->getPrimaryScrollView();
        if ($remaining !== 0 && !in_array($primary, $seen, true)) {
            $primary->scrollBy($remaining);
        }
        $this->updateScrollbarHover($x, $y);
        $this->requestRender();
    }

    /** @return 'left'|'middle'|'right'|'none' */
    private static function decodeMouseButton(int $button): string
    {
        return match ($button & 3) {
            0 => 'left',
            1 => 'middle',
            2 => 'right',
            default => 'none',
        };
    }

    /** @param 'press'|'release'|'move'|'drag'|'click'|'wheel' $type */
    private function createMouseEvent(string $type, int $button, int $x, int $y, ?int $wheelDelta = null, ?int $clickCount = null): TuiMouseEvent
    {
        return new TuiMouseEvent(
            type: $type,
            button: $type === 'wheel' ? 'none' : self::decodeMouseButton($button),
            x: $x,
            y: $y,
            screenX: $x,
            screenY: $y,
            width: max(1, $this->terminal->columns()),
            height: max(1, $this->terminal->rows()),
            shift: ($button & 4) !== 0,
            alt: ($button & 8) !== 0,
            ctrl: ($button & 16) !== 0,
            wheelDelta: $wheelDelta,
            clickCount: $clickCount,
        );
    }

    private function dispatchMouseToLayout(TuiMouseEvent $event): ?TuiMouseDispatchResult
    {
        if ($this->currentLayout === null) {
            return null;
        }
        $visited = [];
        foreach (Layout::getLayoutBoxesAt($this->currentLayout, $event->screenX, $event->screenY) as $box) {
            if (in_array($box->component, $visited, true)) {
                continue;
            }
            // A stack's own handler would forward to children that have boxes of their own.
            if ($box->component instanceof LayoutComponent && self::usesContainerMouseHandler($box->component)) {
                continue;
            }
            $visited[] = $box->component;
            $result = Mouse::dispatchMouseEvent(
                $box->component,
                $event->at($event->screenX - $box->rect->x, $event->screenY - $box->rect->y, $box->rect->width, $box->rect->height),
            );
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** Upstream's `box.component.handleMouse === Container.prototype.handleMouse`. */
    private static function usesContainerMouseHandler(Component $component): bool
    {
        return $component instanceof Container
            && (new \ReflectionMethod($component, 'handleMouse'))->getDeclaringClass()->getName() === Container::class;
    }

    private function applyMouseDispatchResult(TuiMouseEvent $event, TuiMouseDispatchResult $result): bool
    {
        $focusTarget = $this->resolveMouseFocusTarget($result->focusTarget ?? $result->target->component);
        $focusChanged = $result->focus && $this->getFocusedComponent() !== $focusTarget;
        if ($result->focus) {
            $this->setFocus($focusTarget);
        }
        if ($result->capture) {
            $this->mouseCapture = $result->target;
        }

        return $result->render ?? ($focusChanged || in_array($event->type, ['press', 'click', 'drag', 'wheel'], true));
    }

    private function dispatchMouseToTarget(TuiMouseEvent $event, TuiMouseDispatchTarget $target): ?TuiMouseDispatchResult
    {
        return Mouse::dispatchMouseEvent($target->component, Mouse::retargetMouseEvent($event, $target));
    }

    private function getComponentClickCount(TuiMouseDispatchTarget $target, int $x, int $y): int
    {
        $now = microtime(true);
        $previous = $this->lastComponentClick;
        $count = $previous !== null
            && $now - $previous['timestamp'] <= self::DOUBLE_CLICK_INTERVAL
            && $previous['component'] === $target->component
            && $previous['x'] === $x
            && $previous['y'] === $y
                ? ($previous['count'] % 3) + 1
                : 1;
        $this->lastComponentClick = ['timestamp' => $now, 'count' => $count, 'component' => $target->component, 'x' => $x, 'y' => $y];

        return $count;
    }

    private function clearTextSelection(): void
    {
        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = false;
        $this->selectionAnchor = null;
        $this->selectionFocus = null;
        $this->selectionGranularity = 'character';
        $this->selectionInitialRange = null;
        $this->pressedUrl = null;
        $this->selectionDragged = false;
    }

    /** @param array{button: int, x: int, y: int, release: bool} $raw */
    private function handleMouseEvent(array $raw): void
    {
        $isMotion = ($raw['button'] & 32) !== 0;
        $type = $raw['release'] ? 'release' : ($isMotion ? (self::decodeMouseButton($raw['button']) === 'none' ? 'move' : 'drag') : 'press');
        $event = $this->createMouseEvent($type, $raw['button'], $raw['x'], $raw['y']);

        $target = $this->mouseCapture ?? $this->mousePressTarget;
        if ($target !== null) {
            if ($this->mousePressPoint !== null && ($raw['x'] !== $this->mousePressPoint['x'] || $raw['y'] !== $this->mousePressPoint['y'])) {
                $this->mousePressMoved = true;
                $this->lastComponentClick = null;
            }
            $render = false;
            $targetResult = $this->dispatchMouseToTarget($event, $target);
            if ($targetResult !== null) {
                $render = $this->applyMouseDispatchResult($event, $targetResult);
            }
            if ($raw['release']) {
                if (!$this->mousePressMoved && $this->mousePressPoint !== null && $this->mousePressPoint['x'] === $raw['x'] && $this->mousePressPoint['y'] === $raw['y']) {
                    $clickEvent = $this->createMouseEvent('click', $raw['button'], $raw['x'], $raw['y'], clickCount: $this->getComponentClickCount($target, $raw['x'], $raw['y']));
                    $clickResult = $this->dispatchMouseToTarget($clickEvent, $target);
                    if ($clickResult !== null) {
                        $render = $this->applyMouseDispatchResult($clickEvent, $clickResult) || $render;
                    }
                }
                $this->clearComponentMouseGesture();
            }
            if ($render) {
                $this->requestRender();
            }

            return;
        }

        if ($this->handleSearchMouseEvent($raw)) {
            return;
        }

        $overlay = $this->dispatchMouseToOverlay($event);
        if (!$overlay['hit']) {
            if ($this->handleScrollToEndIndicatorMouseEvent($raw)) {
                return;
            }
            $scrollbarHandled = $this->handleScrollbarMouseEvent($raw);
            if ($this->scrollbarDrag === null) {
                $this->updateScrollbarHover($raw['x'], $raw['y']);
            }
            if ($scrollbarHandled) {
                return;
            }
        } else {
            $this->stopScrollbarHover();
        }

        $result = $overlay['result'] ?? ($overlay['hit'] ? null : $this->dispatchMouseToLayout($event));
        if ($result !== null) {
            $render = $this->applyMouseDispatchResult($event, $result);
            if ($type === 'press') {
                $this->clearTextSelection();
                $this->mousePressTarget = $result->target;
                $this->mousePressPoint = ['x' => $raw['x'], 'y' => $raw['y']];
                $this->mousePressMoved = false;
            }
            if ($render) {
                $this->requestRender();
            }

            return;
        }

        if ($this->handleRightClickPaste($raw)) {
            return;
        }
        $this->handleSelectionMouseEvent($raw);
    }

    /**
     * Upstream wraps the callback in a catch that drops its failure ("best-effort"); here the
     * callback reports its own failures, and anything it throws is a crash like any other.
     *
     * @param array{button: int, x: int, y: int, release: bool} $event
     */
    private function handleRightClickPaste(array $event): bool
    {
        $termProgram = getenv('TERM_PROGRAM');
        if (
            $this->onRightClickPaste === null
            || PHP_OS_FAMILY !== 'Windows'
            || ($termProgram !== false && strtolower($termProgram) === 'vscode')
            || $event['release']
            || $event['button'] !== 2
        ) {
            return false;
        }
        ($this->onRightClickPaste)();

        return true;
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleScrollToEndIndicatorMouseEvent(array $event): bool
    {
        $rect = $this->scrollToEndIndicatorRect;
        if ($rect === null || $event['release'] || ($event['button'] & 32) !== 0 || ($event['button'] & 3) !== 0) {
            return false;
        }

        if ($event['y'] !== $rect['row'] || $event['x'] < $rect['column'] || $event['x'] >= $rect['column'] + $rect['width']) {
            return false;
        }

        $this->scrollToBottom();

        return true;
    }

    /** @return array{scrollView: ScrollView, geometry: ScrollbarGeometry}|null */
    private function getScrollbarTargetAt(int $x, int $y, bool $includeHiddenAuto = false): ?array
    {
        if ($this->currentLayout === null) {
            return null;
        }

        foreach (Layout::getScrollViewsAt($this->currentLayout, $x, $y) as $scrollView) {
            $box = Layout::getScrollViewBox($this->currentLayout, $scrollView);
            $geometry = $box === null ? null : Layout::getScrollbarGeometry($box, $includeHiddenAuto);
            if ($geometry !== null && $x === $geometry->column && $y >= $geometry->trackTop && $y < $geometry->trackTop + $geometry->trackHeight) {
                return ['scrollView' => $scrollView, 'geometry' => $geometry];
            }
        }

        return null;
    }

    private function setScrollbarHover(?ScrollView $scrollView): void
    {
        if ($scrollView === $this->scrollbarHover) {
            return;
        }

        $this->scrollbarHover?->setScrollbarActive(false);
        $this->scrollbarHover = $scrollView;
        $this->scrollbarHover?->setScrollbarActive(true);
    }

    private function updateScrollbarHover(int $x, int $y): void
    {
        $this->setScrollbarHover($this->getScrollbarTargetAt($x, $y, true)['scrollView'] ?? null);
    }

    private function stopScrollbarHover(): void
    {
        $this->setScrollbarHover(null);
    }

    private function scrollScrollbarToPointer(ScrollView $scrollView, ScrollbarGeometry $geometry, int $pointerY, int $grabOffset): void
    {
        $maxThumbOffset = $geometry->trackHeight - $geometry->thumbHeight;
        $thumbOffset = max(0, min($maxThumbOffset, $pointerY - $geometry->trackTop - $grabOffset));
        $scrollTop = $maxThumbOffset === 0 ? 0 : (int) round(($thumbOffset / $maxThumbOffset) * $geometry->maxScrollTop);
        $scrollView->scrollTo($scrollTop);
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleScrollbarMouseEvent(array $event): bool
    {
        if ($this->scrollbarDrag !== null) {
            if ($event['release']) {
                $this->stopScrollbarDrag();

                return true;
            }

            $box = $this->currentLayout === null ? null : Layout::getScrollViewBox($this->currentLayout, $this->scrollbarDrag['scrollView']);
            $geometry = $box === null ? null : Layout::getScrollbarGeometry($box);
            if ($geometry !== null) {
                $this->scrollScrollbarToPointer($this->scrollbarDrag['scrollView'], $geometry, $event['y'], $this->scrollbarDrag['grabOffset']);
            }

            return true;
        }

        if ($event['release'] || ($event['button'] & 32) !== 0 || ($event['button'] & 3) !== 0) {
            return false;
        }

        $target = $this->getScrollbarTargetAt($event['x'], $event['y']);
        if ($target === null) {
            return false;
        }

        $this->clearTextSelection();
        $this->lastClick = null;
        $this->setScrollbarHover($target['scrollView']);
        $geometry = $target['geometry'];
        $onThumb = $event['y'] >= $geometry->thumbTop && $event['y'] < $geometry->thumbTop + $geometry->thumbHeight;
        $grabOffset = $onThumb ? $event['y'] - $geometry->thumbTop : intdiv($geometry->thumbHeight, 2);
        if (!$onThumb) {
            $this->scrollScrollbarToPointer($target['scrollView'], $geometry, $event['y'], $grabOffset);
        }
        $this->scrollbarDrag = ['scrollView' => $target['scrollView'], 'grabOffset' => $grabOffset];

        return true;
    }

    private function stopScrollbarDrag(): void
    {
        $this->scrollbarDrag = null;
    }

    /** @return array{row: int, col: int, scrollView: ScrollView}|null */
    private function getScrollSelectionPoint(ScrollView $scrollView, int $x, int $y): ?array
    {
        $box = $this->currentLayout === null ? null : Layout::getScrollViewBox($this->currentLayout, $scrollView);
        if ($box === null || $box->rect->height <= 0 || $box->clip->height <= 0) {
            return null;
        }

        $visibleTop = max(0, $box->rect->y, $box->clip->y);
        $visibleBottom = min($this->terminal->rows() - 1, $box->rect->y + $box->rect->height - 1, $box->clip->y + $box->clip->height - 1);
        if ($visibleBottom < $visibleTop) {
            return null;
        }

        $pointerRow = max($visibleTop, min($visibleBottom, $y));
        $maxContentRow = max(0, count($box->scrollContentLines ?? ['']) - 1);

        return [
            'row' => max(0, min($maxContentRow, $scrollView->scrollTop() + $pointerRow - $box->rect->y)),
            'col' => max(0, min($box->rect->width - 1, $x - $box->rect->x)),
            'scrollView' => $scrollView,
        ];
    }

    /**
     * @param array{button: int, x: int, y: int, release: bool} $event
     * @return array{row: int, col: int, scrollView: ?ScrollView}
     */
    private function getSelectionPoint(array $event, ?ScrollView $scrollView = null): array
    {
        if ($scrollView !== null) {
            $point = $this->getScrollSelectionPoint($scrollView, $event['x'], $event['y']);
            if ($point !== null) {
                return $point;
            }
        }

        return [
            'row' => max(0, min($this->terminal->rows() - 1, $event['y'])),
            'col' => max(0, min($this->terminal->columns() - 1, $event['x'])),
            'scrollView' => null,
        ];
    }

    /** @param array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool} $point */
    private function getSelectionSourceLine(array $point): string
    {
        if ($point['scrollView'] !== null && $this->currentLayout !== null) {
            $lines = Layout::getScrollViewBox($this->currentLayout, $point['scrollView'])?->scrollContentLines;
            if ($lines !== null) {
                return $lines[$point['row']] ?? '';
            }
        }

        return $this->previousScreen[$point['row']] ?? '';
    }

    /**
     * The word under a point, upstream's `getWordSelection()`.
     *
     * Upstream segments with `Intl.Segmenter`; pig has no ext-intl, so the Unicode word rules it
     * needs are written out: a run of `Chars::isWord()` graphemes is one word, `.` `'` `’` `:` `·`
     * between two word characters do not break it (`compositor.ts`, `don't`), `,` `;` do not between
     * two digits (`1,000`), and a run of whitespace is one segment. Everything else stands alone.
     * Joiners then glue paths and kebab-case tokens together, as upstream does.
     *
     * @param array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool} $point
     * @return array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}}|null
     */
    private function getWordSelection(array $point): ?array
    {
        $graphemes = Graphemes::split(Ansi::strip($this->getSelectionSourceLine($point)));
        /** @var list<array{start: int, end: int, selectable: bool, joiner: bool}> $segments */
        $segments = [];
        $column = 0;
        $previousKind = null;
        foreach ($graphemes as $index => $grapheme) {
            $end = $column + Width::visible($grapheme);
            $kind = self::wordSegmentKind($graphemes, $index);
            if ($kind !== 'other' && $kind === $previousKind) {
                $segments[count($segments) - 1]['end'] = $end;
            } else {
                $joiner = in_array($grapheme, self::TERMINAL_WORD_SELECTION_JOINERS, true);
                $segments[] = ['start' => $column, 'end' => $end, 'selectable' => $kind === 'word' || $joiner, 'joiner' => $joiner];
            }
            $previousKind = $kind;
            $column = $end;
        }

        $clicked = null;
        foreach ($segments as $index => $segment) {
            if ($point['col'] >= $segment['start'] && $point['col'] < $segment['end']) {
                $clicked = $index;
                break;
            }
        }

        if ($clicked === null) {
            return null;
        }

        $canJoin = static fn (array $left, array $right): bool => $left['selectable'] && $right['selectable'] && ($left['joiner'] || $right['joiner']);
        $selectionStart = $segments[$clicked]['start'];
        $selectionEnd = $segments[$clicked]['end'];
        for ($index = $clicked; $index > 0 && $canJoin($segments[$index - 1], $segments[$index]); $index--) {
            $selectionStart = $segments[$index - 1]['start'];
        }
        for ($index = $clicked; $index < count($segments) - 1 && $canJoin($segments[$index], $segments[$index + 1]); $index++) {
            $selectionEnd = $segments[$index + 1]['end'];
        }

        return [
            'start' => [...$point, 'col' => $selectionStart],
            'end' => [...$point, 'col' => $selectionEnd, 'boundary' => true],
        ];
    }

    /**
     * @param list<string> $graphemes
     * @return 'word'|'space'|'other'
     */
    private static function wordSegmentKind(array $graphemes, int $index): string
    {
        $grapheme = $graphemes[$index];
        if (Chars::isWord($grapheme)) {
            return 'word';
        }
        if (Chars::isWhitespace($grapheme)) {
            return 'space';
        }

        $before = $graphemes[$index - 1] ?? '';
        $after = $graphemes[$index + 1] ?? '';
        if (in_array($grapheme, ['.', "'", '’', ':', '·'], true) && Chars::isWord($before) && Chars::isWord($after)) {
            return 'word';
        }
        if (in_array($grapheme, [',', ';'], true) && ctype_digit($before) && ctype_digit($after)) {
            return 'word';
        }

        return 'other';
    }

    /**
     * @param array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool} $point
     * @return array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}}
     */
    private function getLineSelection(array $point): array
    {
        return [
            'start' => [...$point, 'col' => 0],
            'end' => [...$point, 'col' => Width::visible($this->getSelectionSourceLine($point)), 'boundary' => true],
        ];
    }

    /** @param array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool} $point */
    private function updateSelectionFocus(array $point): void
    {
        if ($this->selectionGranularity === 'character' || $this->selectionInitialRange === null) {
            $this->selectionFocus = $point;

            return;
        }

        $range = $this->selectionGranularity === 'word' ? $this->getWordSelection($point) : $this->getLineSelection($point);
        if ($range === null) {
            return;
        }

        $initial = $this->selectionInitialRange;
        $targetBeforeInitial = $range['start']['row'] < $initial['start']['row']
            || ($range['start']['row'] === $initial['start']['row'] && $range['start']['col'] < $initial['start']['col']);
        if ($targetBeforeInitial) {
            $this->selectionAnchor = $initial['end'];
            $this->selectionFocus = $range['start'];
        } else {
            $this->selectionAnchor = $initial['start'];
            $this->selectionFocus = $range['end'];
        }
    }

    /**
     * @param array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool} $point
     * @param array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}}|null $word
     */
    private function getClickCount(array $point, ?array $word): int
    {
        $now = microtime(true);
        $previous = $this->lastClick;
        $count = $word !== null
            && $previous !== null
            && $now - $previous['timestamp'] <= self::DOUBLE_CLICK_INTERVAL
            && $previous['row'] === $point['row']
            && $previous['scrollView'] === $point['scrollView']
            && $previous['wordStart'] === $word['start']['col']
            && $previous['wordEnd'] === $word['end']['col']
            ? ($previous['count'] % 3) + 1
            : 1;
        $this->lastClick = $word === null ? null : [
            'timestamp' => $now,
            'count' => $count,
            'row' => $point['row'],
            'scrollView' => $point['scrollView'],
            'wordStart' => $word['start']['col'],
            'wordEnd' => $word['end']['col'],
        ];

        return $count;
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function updateSelectionAutoScroll(array $event): void
    {
        $scrollView = $this->selectionAnchor['scrollView'] ?? null;
        $box = $scrollView === null || $this->currentLayout === null ? null : Layout::getScrollViewBox($this->currentLayout, $scrollView);
        if ($box === null || $box->rect->height <= 0 || $box->clip->height <= 0) {
            $this->stopSelectionAutoScroll();

            return;
        }

        $visibleTop = max(0, $box->rect->y, $box->clip->y);
        $visibleBottom = min($this->terminal->rows() - 1, $box->rect->y + $box->rect->height - 1, $box->clip->y + $box->clip->height - 1);
        $this->selectionDragPointer = ['x' => $event['x'], 'y' => $event['y']];
        $this->selectionAutoScrollDirection = $event['y'] <= $visibleTop ? -1 : ($event['y'] >= $visibleBottom ? 1 : 0);
        if ($this->selectionAutoScrollDirection === 0) {
            $this->stopSelectionAutoScroll();

            return;
        }

        $this->selectionAutoScrollTimer ??= Loop::get()->delay(self::SELECTION_AUTO_SCROLL_INTERVAL, $this->autoScrollSelection(...));
    }

    /** Upstream runs this on a 50 ms `setInterval`; the loop has one-shot timers, so it re-arms itself. */
    private function autoScrollSelection(): void
    {
        $this->selectionAutoScrollTimer = null;
        $scrollView = $this->selectionAnchor['scrollView'] ?? null;
        $pointer = $this->selectionDragPointer;
        $direction = $this->selectionAutoScrollDirection;
        if ($scrollView === null || $pointer === null || $direction === 0) {
            $this->stopSelectionAutoScroll();

            return;
        }

        if ($scrollView->scrollBy($direction) === $direction) {
            $this->stopSelectionAutoScroll();

            return;
        }

        $point = $this->getScrollSelectionPoint($scrollView, $pointer['x'], $pointer['y']);
        if ($point !== null) {
            $this->updateSelectionFocus($point);
        }
        $this->requestRender();
        $this->selectionAutoScrollTimer = Loop::get()->delay(self::SELECTION_AUTO_SCROLL_INTERVAL, $this->autoScrollSelection(...));
    }

    private function stopSelectionAutoScroll(): void
    {
        if ($this->selectionAutoScrollTimer !== null) {
            Loop::get()->cancel($this->selectionAutoScrollTimer);
            $this->selectionAutoScrollTimer = null;
        }

        $this->selectionAutoScrollDirection = 0;
        $this->selectionDragPointer = null;
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleSelectionMouseEvent(array $event): void
    {
        $button = $event['button'] & 3;
        if ($button !== 0 && !($event['release'] && $button === 3)) {
            return;
        }

        $point = $this->getSelectionPoint($event, $this->selectionAnchor['scrollView'] ?? null);

        if ($event['release']) {
            if (!$this->selectionPressActive) {
                return;
            }

            $this->selectionPressActive = false;
            $this->stopSelectionAutoScroll();
            if ($this->selectionAnchor === null) {
                return;
            }

            $this->updateSelectionFocus($point);
            $isClick = !$this->selectionDragged
                && $this->selectionAnchor['scrollView'] === $point['scrollView']
                && $this->selectionAnchor['row'] === $point['row']
                && $this->selectionAnchor['col'] === $point['col'];
            $clickedUrl = $isClick ? $this->pressedUrl : null;
            $this->pressedUrl = null;
            if ($clickedUrl !== null && $this->openUrl !== null) {
                $this->selectionAnchor = null;
                $this->selectionFocus = null;
                // Upstream drops a failure here ("best-effort"); the callback reports its own.
                ($this->openUrl)($clickedUrl);
                $this->requestRender();

                return;
            }
            if ($isClick) {
                $clickEvent = $this->createMouseEvent('click', $event['button'], $event['x'], $event['y'], clickCount: $this->lastClick['count'] ?? 1);
                $overlay = $this->dispatchMouseToOverlay($clickEvent);
                $result = $overlay['result'] ?? ($overlay['hit'] ? null : $this->dispatchMouseToLayout($clickEvent));
                if ($result !== null) {
                    $render = $this->applyMouseDispatchResult($clickEvent, $result);
                    $this->clearTextSelection();
                    if ($render) {
                        $this->requestRender();
                    }

                    return;
                }
            }
            if ($this->copyOnSelect) {
                $this->copySelectionToClipboard();
            }
            $this->requestRender();

            return;
        }

        if (($event['button'] & 32) !== 0) {
            if (!$this->selectionPressActive || $this->selectionAnchor === null) {
                return;
            }

            $this->selectionDragged = true;
            $this->lastClick = null;
            $this->pressedUrl = null;
            $this->updateSelectionFocus($point);
            $this->updateSelectionAutoScroll($event);
            $this->requestRender();

            return;
        }

        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = true;
        $scrollView = !$this->hasOverlay() && $this->currentLayout !== null
            ? (Layout::getScrollViewsAt($this->currentLayout, $event['x'], $event['y'])[0] ?? null)
            : null;
        $anchor = $this->getSelectionPoint($event, $scrollView);
        $word = $this->getWordSelection($anchor);
        $clickCount = $this->getClickCount($anchor, $word);
        $range = match ($clickCount) {
            2 => $word,
            3 => $this->getLineSelection($anchor),
            default => null,
        };
        $this->selectionGranularity = $range === null ? 'character' : ($clickCount === 2 ? 'word' : 'line');
        $this->selectionInitialRange = $range;
        $this->selectionAnchor = $range['start'] ?? $anchor;
        $this->selectionFocus = $range['end'] ?? $anchor;
        $this->selectionDragged = false;
        $this->pressedUrl = $range !== null
            ? null
            : Width::getOsc8LinkAtColumn(
                $this->previousScreen[max(0, min($this->terminal->rows() - 1, $event['y']))] ?? '',
                max(0, min($this->terminal->columns() - 1, $event['x'])),
            );
        $this->requestRender();
    }

    /** @return array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}}|null */
    private function getSelectionBounds(): ?array
    {
        $anchor = $this->selectionAnchor;
        $focus = $this->selectionFocus;
        if ($anchor === null || $focus === null || $anchor['scrollView'] !== $focus['scrollView']) {
            return null;
        }

        if ($anchor['row'] === $focus['row'] && $anchor['col'] === $focus['col']) {
            return null;
        }

        $anchorBeforeFocus = $anchor['row'] < $focus['row'] || ($anchor['row'] === $focus['row'] && $anchor['col'] < $focus['col']);

        return $anchorBeforeFocus ? ['start' => $anchor, 'end' => $focus] : ['start' => $focus, 'end' => $anchor];
    }

    /**
     * @param array{start: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}, end: array{row: int, col: int, scrollView: ?ScrollView, boundary?: bool}} $selection
     * @return array{start: int, end: int}
     */
    private static function getSelectionColumns(string $line, int $row, array $selection, int $minColumn = 0, ?int $maxColumn = null): array
    {
        $lineWidth = Width::visible($line);
        $maxColumn ??= $lineWidth;
        $start = max(0, $minColumn);
        $end = min($lineWidth, $maxColumn);
        if ($row === $selection['start']['row']) {
            $start = Width::graphemeCellRange($line, $selection['start']['col'])['start'] ?? min($selection['start']['col'], $lineWidth);
        }
        if ($row === $selection['end']['row']) {
            $end = ($selection['end']['boundary'] ?? false)
                ? min($selection['end']['col'], $lineWidth)
                : (Width::graphemeCellRange($line, $selection['end']['col'])['end'] ?? min($selection['end']['col'] + 1, $lineWidth));
        }

        return ['start' => max($minColumn, $start), 'end' => min($maxColumn, $end)];
    }

    private function getActiveSelectionText(): ?string
    {
        $selection = $this->getSelectionBounds();
        if ($selection === null) {
            return null;
        }

        $sourceLines = $this->previousScreen;
        if ($selection['start']['scrollView'] !== null) {
            $box = $this->currentLayout === null ? null : Layout::getScrollViewBox($this->currentLayout, $selection['start']['scrollView']);
            if ($box?->scrollContentLines === null) {
                return null;
            }
            $sourceLines = $box->scrollContentLines;
        }

        $lines = [];
        for ($row = $selection['start']['row']; $row <= $selection['end']['row']; $row++) {
            $line = $sourceLines[$row] ?? '';
            $columns = self::getSelectionColumns($line, $row, $selection);
            $lines[] = rtrim(Ansi::strip(Width::sliceByColumn($line, $columns['start'], max(0, $columns['end'] - $columns['start']), true)));
        }

        $text = implode("\n", $lines);

        return $text === '' ? null : $text;
    }

    private function copySelectionToClipboard(): bool
    {
        $text = $this->getActiveSelectionText();

        return $text !== null && $this->copyTextToClipboard($text);
    }

    private function copyTextToClipboard(string $text): bool
    {
        // Prefer the injected clipboard (it can say whether the copy happened). A bare OSC 52
        // write can show "Copied!" while leaving the system clipboard untouched — macOS
        // Terminal.app, tmux without OSC 52 passthrough — so it is only the fallback.
        if ($this->copySelection !== null) {
            $result = ($this->copySelection)($text);
            $ok = $result === true;
            if ($ok) {
                $this->flash('Copied!');
            } else {
                $this->flash(is_string($result) ? $result : 'Copy failed', self::COPY_ERROR_FLASH_DURATION);
            }

            return $ok;
        }

        $this->terminal->write("\x1b]52;c;" . base64_encode($text) . "\x07");
        $this->flash('Copied!');

        return true;
    }

    private static function applySelectionHighlight(string $text): string
    {
        $result = "\x1b[7m";
        $index = 0;
        $length = strlen($text);
        while ($index < $length) {
            $code = Ansi::at($text, $index);
            if ($code === null) {
                $result .= $text[$index];
                $index++;
                continue;
            }
            $result .= $code[0];
            if (str_ends_with($code[0], 'm')) {
                $result .= "\x1b[7m";
            }
            $index += $code[1];
        }

        return $result . "\x1b[27m";
    }

    /**
     * @param list<string> $screen
     * @return list<string>
     */
    private function applySelection(array $screen, ?LayoutFrame $layout): array
    {
        $selection = $this->getSelectionBounds();
        if ($selection === null) {
            return $screen;
        }

        $screenSelection = $selection;
        $minRow = 0;
        $maxRow = count($screen) - 1;
        $minColumn = 0;
        $maxColumn = $this->terminal->columns();
        $scrollView = $selection['start']['scrollView'];
        if ($scrollView !== null) {
            $box = $layout === null ? null : Layout::getScrollViewBox($layout, $scrollView);
            if ($box === null) {
                return $screen;
            }
            $minRow = max(0, $box->rect->y, $box->clip->y);
            $maxRow = min(count($screen) - 1, $box->rect->y + $box->rect->height - 1, $box->clip->y + $box->clip->height - 1);
            $minColumn = max(0, $box->rect->x, $box->clip->x);
            $maxColumn = min($this->terminal->columns(), $box->rect->x + $box->rect->width, $box->clip->x + $box->clip->width);
            foreach (['start', 'end'] as $edge) {
                $screenSelection[$edge]['row'] = $box->rect->y + $selection[$edge]['row'] - $scrollView->scrollTop();
                $screenSelection[$edge]['col'] = $box->rect->x + $selection[$edge]['col'];
            }
        }

        foreach ($screen as $row => $line) {
            if ($row < $minRow || $row > $maxRow || $row < $screenSelection['start']['row'] || $row > $screenSelection['end']['row'] || self::isImageLine($line)) {
                continue;
            }

            $lineWidth = Width::visible($line);
            $columns = self::getSelectionColumns($line, $row, $screenSelection, $minColumn, $maxColumn);
            if ($columns['end'] <= $columns['start']) {
                continue;
            }

            $before = Width::sliceByColumn($line, 0, $columns['start'], true);
            $selected = Width::sliceByColumn($line, $columns['start'], $columns['end'] - $columns['start'], true);
            $after = Width::sliceByColumn($line, $columns['end'], max(0, $lineWidth - $columns['end']), true);
            $screen[$row] = $before . self::applySelectionHighlight($selected) . $after;
        }

        return $screen;
    }

    /**
     * @param list<string> $screen
     * @return list<string>
     */
    private function compositeScrollToEndIndicator(array $screen, LayoutFrame $layout, int $width): array
    {
        $this->scrollToEndIndicatorRect = null;
        $scrollView = $layout->primaryScrollView ?? $this->implicitScrollView;
        if ($this->scrollToEndIndicator === null || !$scrollView->followEnd || $scrollView->isFollowingEnd()) {
            return $screen;
        }

        $box = Layout::getScrollViewBox($layout, $scrollView);
        $clip = $box?->clip;
        if ($clip === null || $clip->width <= 0 || $clip->height <= 0) {
            return $screen;
        }

        $row = $clip->y + $clip->height - 1;
        if ($row >= count($screen) || self::isImageLine($screen[$row] ?? '')) {
            return $screen;
        }

        $scrollbarColumn = Layout::getScrollbarGeometry($box)?->column;
        $label = Width::truncate(($this->scrollToEndIndicator)(), $clip->width, '');
        $labelWidth = Width::visible($label);
        $column = $clip->x + intdiv($clip->width - $labelWidth, 2);
        $rightEdge = $scrollbarColumn ?? $clip->x + $clip->width;
        $text = Width::truncate($label, max(0, $rightEdge - $column), '');
        $textWidth = Width::visible($text);
        if ($textWidth === 0) {
            return $screen;
        }

        $screen[$row] = Width::composite($screen[$row] ?? '', $text, $column, $textWidth, $width);
        $this->scrollToEndIndicatorRect = ['row' => $row, 'column' => $column, 'width' => $textWidth];

        return $screen;
    }

    /**
     * @param list<string> $screen
     * @return list<string>
     */
    private function compositeFlashes(array $screen, int $width, int $height): array
    {
        $flashLines = array_slice($this->flashes->render($width), -$height);
        if ($flashLines === []) {
            return $screen;
        }

        while (count($screen) < $height) {
            $screen[] = '';
        }

        foreach ($flashLines as $row => $line) {
            $flashWidth = Width::visible($line);
            if ($flashWidth === 0) {
                continue;
            }
            $screen[$row] = Width::composite($screen[$row], $line, $width - $flashWidth, $flashWidth, $width);
        }

        return $screen;
    }

    /**
     * One frame — upstream's `doRender()`.
     *
     * Every row of the window is addressed absolutely, the frame is exactly the window's height,
     * and autowrap is off for as long as the alternate screen is up: one line the terminal
     * measures a cell wider than `Width::visible()` does would otherwise wrap, and on the last
     * row scroll the whole screen — the dock with it — without the diff finding out. A line wider
     * than the window is cut to it rather than refused, as upstream does here.
     */
    #[\Override]
    protected function doRender(): void
    {
        if ($this->stopped || !$this->altScreenActive) {
            return;
        }

        $width = max(1, $this->terminal->columns());
        $height = max(1, $this->terminal->rows());
        $root = $this->layoutRoot ?? $this->implicitScrollView;
        $nextLayout = Layout::renderLayoutFrame($root, $width, $height, fn () => $this->requestRender());
        if ($this->refreshSearch($nextLayout)) {
            $nextLayout = Layout::renderLayoutFrame($root, $width, $height, fn () => $this->requestRender());
        }
        $screen = array_map(static fn (string $line): string => (string) preg_replace(self::OSC133_ZONE_PREFIX, '', $line), $nextLayout->lines);
        $screen = $this->applySearchHighlights($screen, $nextLayout);
        $screen = $this->compositeScrollToEndIndicator($screen, $nextLayout, $width);
        $screen = $this->compositeOverlays($screen, $width, $height);
        if (count($screen) > $height) {
            $screen = array_slice($screen, count($screen) - $height);
        }
        $screen = $this->applySelection($screen, $nextLayout);
        $screen = $this->compositeFlashes($screen, $width, $height);
        $cursorPos = $this->extractCursorPosition($screen, $height);
        $screen = array_map(
            static fn (string $line): string => self::isImageLine($line) || Width::visible($line) <= $width ? $line : Width::sliceByColumn($line, 0, $width, true),
            $this->applyLineResets($screen),
        );

        $fullRedraw = $this->previousScreen === [] || $this->previousWidth !== $width || $this->previousHeight !== $height;
        $buffer = self::BEGIN_SYNCHRONIZED_OUTPUT;
        if ($fullRedraw) {
            $this->fullRedrawCount++;
            $buffer .= "\x1b[2J";
        }

        for ($row = 0; $row < $height; $row++) {
            if (!$fullRedraw && ($screen[$row] ?? '') === ($this->previousScreen[$row] ?? null)) {
                continue;
            }
            $buffer .= "\x1b[" . ($row + 1) . ";1H\x1b[2K" . ($screen[$row] ?? '');
        }

        if ($cursorPos !== null) {
            $buffer .= "\x1b[" . ($cursorPos['row'] + 1) . ';' . (min($width, $cursorPos['col']) + 1) . 'H';
            $buffer .= $this->getShowHardwareCursor() ? "\x1b[?25h" : "\x1b[?25l";
        } else {
            $buffer .= "\x1b[?25l";
        }
        $buffer .= self::END_SYNCHRONIZED_OUTPUT;
        $this->terminal->write($buffer);

        $frame = [];
        for ($row = 0; $row < $height; $row++) {
            $frame[] = $screen[$row] ?? '';
        }
        $this->previousScreen = $frame;
        $this->previousWidth = $width;
        $this->previousHeight = $height;
        $this->currentLayout = $nextLayout;
    }
}
