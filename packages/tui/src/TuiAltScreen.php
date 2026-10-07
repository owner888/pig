<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Components\AltScreenFlashContainer;
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
 * Not ported yet: transcript search, component mouse handlers, overlays, OSC 8 link clicks,
 * Kitty image placement, and the `tui.altScreen.*` keys (the interactive mode binds those).
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

    private readonly WheelScrollAccelerator $wheelScroll;

    private readonly bool $mouseEnabled;

    /** @var (Closure(): string)|null */
    private readonly ?Closure $scrollToEndIndicator;

    private bool $copyOnSelect;

    /** @var (Closure(string): (bool|string))|null */
    private readonly ?Closure $copySelection;

    public function __construct(Terminal $terminal, ?TuiAltScreenOptions $options = null)
    {
        parent::__construct($terminal);
        $options ??= new TuiAltScreenOptions();
        $this->implicitDocument = new class (fn (int $width): array => parent::render($width), fn () => $this->invalidateChildren()) implements Component {
            public function __construct(private readonly Closure $renderChildren, private readonly Closure $invalidateChildren)
            {
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

    #[\Override]
    public function invalidate(): void
    {
        if ($this->layoutRoot !== null) {
            $this->layoutRoot->invalidate();

            return;
        }

        $this->invalidateChildren();
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

    /** One page, less a few rows of overlap — upstream's page keys. */
    public function scrollPage(int $direction): void
    {
        $this->scrollBy($direction * max(1, $this->getPrimaryScrollView()->viewportHeight() - self::PAGE_SCROLL_OVERLAP));
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
        $this->selectionDragged = false;
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
        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = false;
        $this->stopScrollbarHover();
        $this->stopScrollbarDrag();
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
        foreach ($this->render($width) as $row => $line) {
            $line = (string) preg_replace(self::OSC133_ZONE_PREFIX, '', $line);
            if (!self::isImageLine($line) && Width::visible($line) > $width) {
                $line = Width::sliceByColumn($line, 0, $width, true);
            }
            $buffer .= ($row > 0 ? "\r\n" : '') . "\r\x1b[2K" . $line;
        }
        $buffer .= "\x1b[0m" . self::ENABLE_AUTOWRAP . "\r\n\x1b[?25h" . self::END_SYNCHRONIZED_OUTPUT;
        $this->terminal->write($buffer);
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
        if (!str_contains($data, "\x1b[") || str_contains($data, "\x1b[200~")) {
            return null;
        }

        $rest = preg_replace_callback(self::MOUSE_REPORT, function (array $match): string {
            $this->handleViewportReport($match[0]);

            return '';
        }, $data);

        if ($rest === null) {
            throw new TuiError('Splitting mouse reports failed: ' . preg_last_error_msg());
        }

        return match (true) {
            $rest === '' => ['consume' => true],
            $rest === $data => null,
            default => ['data' => $rest],
        };
    }

    private function handleViewportReport(string $report): void
    {
        if ($report === self::FOCUS_OUT) {
            $hadActiveSelection = $this->selectionPressActive;
            $hadNonEmptyActiveSelection = $hadActiveSelection && $this->getSelectionBounds() !== null;
            $this->selectionPressActive = false;
            $this->stopSelectionAutoScroll();
            $this->stopScrollbarHover();
            $this->stopScrollbarDrag();
            $this->selectionDragged = false;
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

            return;
        }

        if ($report === self::FOCUS_IN) {
            return;
        }

        $wheel = self::parseWheelEvent($report);
        if ($wheel !== null) {
            $lines = $this->wheelScroll->next($wheel['direction'], microtime(true) * 1000);
            // SGR mouse button codes use bit 3 (value 8) for the Alt modifier.
            $delta = $wheel['direction'] * (($wheel['button'] & 8) !== 0 ? $lines * self::ALT_WHEEL_SCROLL_MULTIPLIER : $lines);
            $this->routeWheel($wheel['x'], $wheel['y'], $delta);

            return;
        }

        $event = self::parseSgrMouseEvent($report);
        if ($event !== null) {
            $this->handleMouseEvent($event);
        }
        // Anything else is a legacy X10 report that is not a wheel: swallowed, never typed.
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

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleMouseEvent(array $event): void
    {
        if ($this->handleScrollToEndIndicatorMouseEvent($event)) {
            return;
        }

        $scrollbarHandled = $this->handleScrollbarMouseEvent($event);
        if ($this->scrollbarDrag === null) {
            $this->updateScrollbarHover($event['x'], $event['y']);
        }
        if ($scrollbarHandled) {
            return;
        }

        $this->handleSelectionMouseEvent($event);
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

    private function clearTextSelection(): void
    {
        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = false;
        $this->selectionAnchor = null;
        $this->selectionFocus = null;
        $this->selectionGranularity = 'character';
        $this->selectionInitialRange = null;
        $this->selectionDragged = false;
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
            $this->updateSelectionFocus($point);
            $this->updateSelectionAutoScroll($event);
            $this->requestRender();

            return;
        }

        $this->stopSelectionAutoScroll();
        $this->selectionPressActive = true;
        $scrollView = $this->currentLayout === null ? null : (Layout::getScrollViewsAt($this->currentLayout, $event['x'], $event['y'])[0] ?? null);
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
     * Where the focused component's caret is on screen — upstream finds `CURSOR_MARKER` in the
     * painted lines; pig asks the `Caret` of the leaf box that holds the focused component.
     *
     * @return array{row: int, col: int}|null
     */
    private function cursorPosition(LayoutFrame $layout): ?array
    {
        $focused = $this->getFocusedComponent();
        if (!$focused instanceof Caret) {
            return null;
        }

        $visit = static function (LayoutBox $box) use (&$visit, $focused): ?array {
            if ($box->lines !== null) {
                $caret = Layout::caretIn($box->component, $focused, $box->rect->width);
                if ($caret === null) {
                    return null;
                }
                $row = $box->rect->y + $caret[0] - $box->lineOffset;
                $col = $box->rect->x + $caret[1];

                return $row >= $box->clip->y && $row < $box->clip->y + $box->clip->height ? ['row' => $row, 'col' => $col] : null;
            }
            foreach ($box->children as $child) {
                $found = $visit($child);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $visit($layout->root);
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
        $nextLayout = Layout::renderLayoutFrame($root, $width, $height, fn () => $this->requestRender(), $this->getFocusedComponent());
        $screen = array_map(static fn (string $line): string => (string) preg_replace(self::OSC133_ZONE_PREFIX, '', $line), $nextLayout->lines);
        $screen = $this->compositeScrollToEndIndicator($screen, $nextLayout, $width);
        if (count($screen) > $height) {
            $screen = array_slice($screen, count($screen) - $height);
        }
        $screen = $this->applySelection($screen, $nextLayout);
        $screen = $this->compositeFlashes($screen, $width, $height);
        $cursorPos = $this->cursorPosition($nextLayout);
        $screen = array_map(
            static fn (string $line): string => self::isImageLine($line) || Width::visible($line) <= $width ? $line : Width::sliceByColumn($line, 0, $width, true),
            $screen,
        );

        $fullRedraw = $this->previousScreen === [] || $this->previousWidth !== $width || $this->previousHeight !== $height;
        $buffer = self::BEGIN_SYNCHRONIZED_OUTPUT;
        if ($fullRedraw) {
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
        }
        $buffer .= "\x1b[?25l" . self::END_SYNCHRONIZED_OUTPUT;
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
