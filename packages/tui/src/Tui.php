<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Components\AltScreenFlashContainer;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Images\TerminalImage;

/**
 * The screen: a component tree, drawn by redrawing as little as possible.
 *
 * Nothing here uses the alternate screen buffer. The UI is written into the normal
 * scrollback, so everything the agent has said stays where the user's scroll wheel can
 * reach it after the program exits — and that is what makes the rendering hard, because
 * lines already committed to scrollback cannot be rewritten.
 *
 * So each frame is compared against the last and **only the lines that differ are rewritten**.
 * When the first difference is above the top of the window there is nothing to move the cursor
 * to, and the whole screen is redrawn instead.
 *
 * Rewriting *from* the first difference *down* was the obvious way to do that and cost the one
 * thing this arrangement exists to protect: the line somebody is typing into. A spinner tick
 * changes one line above the editor, and everything below it — the editor, its borders, the
 * footer — was erased and written again at the spinner's rate. An input method draws what is
 * being composed at the terminal's cursor and anchors its candidate list there, so a prompt
 * being repainted twenty times a second is a candidate list that will not stay put. Measured on
 * a real frame: one line changed, eight rewritten, 1,885 bytes a tick. Per line it is one line
 * and 130 bytes, and the composing line is not touched at all.
 *
 * **One write per frame, with the caret move inside the synchronized-output wrapper.** The move
 * used to go out after it, so the terminal displayed the frame with the cursor wherever the last
 * line left it and *then* moved it — two painted states, the first of them with the cursor at the
 * bottom of the screen, which is the other half of the same candidate-list problem.
 */
class Tui extends Container
{
    // Alternate-screen sequences, upstream's `tui-alt-screen.ts` constants.
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

    /** One mouse or focus report, as found inside a read that may hold several. */
    private const string MOUSE_REPORT = '/\x1b\[<\d+;\d+;\d+[Mm]|\x1b\[M[\x00-\xff]{3}|\x1b\[[IO]/';

    /** @var list<string> */
    private array $previousLines = [];

    private int $previousWidth = 0;

    /** The height the last frame was drawn for; a height change alone redraws nothing here, but a resize that changed neither draws nothing at all. */
    private int $previousHeight = 0;

    private ?Component $focused = null;

    /** Where the cursor is, counted from the first line this drew. */
    private int $cursorRow = 0;

    /**
     * Which column the cursor is in, counted from the left edge of the line.
     *
     * Tracked rather than derived so that a frame which leaves the cursor exactly where the
     * caret already is writes **nothing** — the row alone cannot say that, and a cursor move
     * is the one thing an input method follows.
     *
     * Everything that writes a frame ends it with a `\r`, so this is 0 far more often than it
     * looks: the alternative is measuring the last line, which for a line holding an image is
     * both expensive and meaningless.
     */
    private int $cursorColumn = 0;

    private bool $renderRequested = false;

    /**
     * Frames are at most this far apart — upstream's `MIN_RENDER_INTERVAL_MS`. A window being
     * dragged asks for a frame per SIGWINCH, dozens a second; within the interval they become one.
     */
    public const float MIN_RENDER_INTERVAL = 0.016;

    private float $lastRenderAt = 0.0;

    private ?string $renderTimer = null;

    /** Whether the pending render came from a resize alone, so a size that did not change draws nothing. */
    private bool $onlyResizePending = false;

    /** The terminal was asked how big a cell is and has not answered yet. */
    private bool $awaitingCellSize = false;

    /**
     * Something else owned the screen, so the next frame clears before it draws.
     *
     * Not the same fact as an empty `previousLines`, which is also true of the very first frame —
     * and that one must *not* clear, or starting pig would wipe whatever the shell had printed.
     *
     * `commit()` puts it back to false, which no test can see today and is kept anyway: the only
     * thing that empties the record is the force that sets this, so the two always travel together.
     * The line is what makes the field mean "the screen is lost *now*" rather than "was lost once",
     * and the day something else empties the record it is what stops a stale clear.
     */
    private bool $screenIsLost = false;

    /** Input held back while that answer might still be arriving. */
    private string $cellSizeBuffer = '';

    /** @var Closure(): void|null */
    private ?Closure $onDebug = null;

    /** @var (Closure(int $width, int $height): list<string>)|null */
    private ?Closure $viewportRenderer = null;

    private bool $altScreen = false;

    /** The view the wheel moves and a selection reads from when it starts inside it — upstream's primary `ScrollView`. Drawn from screen row 0. */
    private ?ScrollView $primaryScrollView = null;

    private readonly WheelScrollAccelerator $wheelScroll;

    private readonly AltScreenFlashContainer $flashes;

    private bool $copyOnSelect = true;

    /** @var (Closure(string): (bool|string))|null true on success, an error message, or false for a generic failure */
    private ?Closure $copySelection = null;

    /**
     * A point a selection starts or ends at. `scroll` points count rows in the primary view's
     * content; the others count screen rows. `boundary` points lie between cells.
     *
     * @var array{row: int, col: int, scroll: bool, boundary?: bool}|null
     */
    private ?array $selectionAnchor = null;

    /** @var array{row: int, col: int, scroll: bool, boundary?: bool}|null */
    private ?array $selectionFocus = null;

    /** @var 'character'|'word'|'line' */
    private string $selectionGranularity = 'character';

    /** @var array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}}|null */
    private ?array $selectionInitialRange = null;

    /** @var array{timestamp: float, count: int, row: int, scroll: bool, wordStart: int, wordEnd: int}|null */
    private ?array $lastClick = null;

    /** @var array{x: int, y: int}|null */
    private ?array $selectionDragPointer = null;

    /** @var -1|0|1 */
    private int $selectionAutoScrollDirection = 0;

    private ?string $selectionAutoScrollTimer = null;

    private bool $selectionPressActive = false;

    private bool $selectionDragged = false;

    public function __construct(public readonly Terminal $terminal)
    {
        $this->wheelScroll = new WheelScrollAccelerator('auto');
        $this->flashes = new AltScreenFlashContainer(fn () => $this->requestRender());
    }

    public function setPrimaryScrollView(?ScrollView $scrollView): void
    {
        $this->primaryScrollView = $scrollView;
    }

    /** @param (Closure(string): (bool|string))|null $copy */
    public function setCopySelection(?Closure $copy): void
    {
        $this->copySelection = $copy;
    }

    public function setCopyOnSelect(bool $enabled): void
    {
        $this->copyOnSelect = $enabled;
    }

    /** Show a transient message in the alternate-screen flash stack. */
    public function flash(string $message, float $duration = 1.0): void
    {
        $this->flashes->flash($message, $duration);
    }

    /** Whether the fullscreen viewport has a non-empty active text selection. */
    public function hasActiveSelection(): bool
    {
        return $this->activeSelectionText() !== null;
    }

    public function setViewportRenderer(?Closure $renderer): void
    {
        $this->viewportRenderer = $renderer;
    }

    public function setAltScreen(bool $enabled): void
    {
        $this->altScreen = $enabled;
    }

    public function isAltScreen(): bool
    {
        return $this->altScreen;
    }

    public function setFocus(?Component $component): void
    {
        $this->focused = $component;
    }

    /** @param Closure(): void|null $handler */
    public function setDebugHandler(?Closure $handler): void
    {
        $this->onDebug = $handler;
    }

    public function start(): void
    {
        $this->terminal->start(
            function (string $data): void {
                $this->handleInput($data);
            },
            function (): void {
                // Not forced: force throws away what the screen holds, and then the
                // renderer cannot tell a resize from a first frame and skips the clear.
                $this->requestRender(resize: true);
            },
        );

        if ($this->altScreen) {
            $this->clearTextSelection();
            $this->lastClick = null;
            $this->flashes->dispose();
            $term = strtolower((string) getenv('TERM'));
            // Multiplexers can lag when every pointer movement is forwarded. Button-motion
            // tracking preserves clicks, wheel events, selections, and scrollbar dragging.
            $mouse = getenv('TMUX') !== false
                || getenv('ZELLIJ') !== false
                || getenv('STY') !== false
                || str_starts_with($term, 'tmux')
                || str_starts_with($term, 'screen')
                ? self::ENABLE_BUTTON_MOTION_MOUSE
                : self::ENABLE_ALL_MOTION_MOUSE;
            $this->terminal->write(self::ENTER_ALT_SCREEN . self::DISABLE_AUTOWRAP . $mouse . "\x1b[2J\x1b[H");
        }

        $this->terminal->hideCursor();
        $this->askForCellSize();
        $this->requestRender();
    }

    /**
     * Ask the terminal how big a character cell is, in pixels.
     *
     * Only worth asking on a terminal that draws images, because that is the only thing
     * the answer is used for. The reply comes back as *input*, so the next few keystrokes
     * have to be sifted for it before they reach a component.
     */
    private function askForCellSize(): void
    {
        if (!TerminalImage::capabilities()->drawsImages()) {
            return;
        }

        $this->awaitingCellSize = true;
        $this->terminal->write("\x1b[16t");
    }

    /**
     * Pull the cell-size reply out of the input stream, if it is in there.
     *
     * Returns what is left for the components. The reply may arrive split across reads,
     * so an incomplete escape sequence is held back rather than delivered as keystrokes —
     * but only until something that looks like a finished sequence turns up, because a
     * terminal that never answers must not swallow the user's typing forever.
     *
     * Once the reply is found, whatever followed it goes straight out even if *that* looks
     * half-finished. Upstream runs its held-back check on the remainder instead and then
     * stops buffering, so the bytes it decided to wait for are never delivered at all: a
     * `\e[` arriving on the heels of the reply is dropped and the `A` behind it is typed as a
     * letter. One press of an arrow key at exactly the wrong moment either way, and handing
     * the bytes over is the half that cannot type something nobody pressed.
     */
    private function takeCellSizeReply(string $data): string
    {
        $this->cellSizeBuffer .= $data;
        $size = TerminalImage::parseCellSizeReply($this->cellSizeBuffer);

        if ($size !== null) {
            TerminalImage::setCellSize($size);
            $this->awaitingCellSize = false;
            $rest = (string) preg_replace('/\x1b\[6;\d+;\d+t/', '', $this->cellSizeBuffer, 1);
            $this->cellSizeBuffer = '';

            // Every image was measured against the wrong cell size until now, so their
            // caches go — and then an ordinary render, **not a forced one**. Forcing empties
            // previousLines, which `draw()` reads as "first frame ever" and writes with no
            // clear from wherever the cursor already is: the whole startup screen came out
            // twice on every terminal that answers this query, which is every terminal that
            // draws pictures. Upstream asks for a plain render here for the same reason the
            // resize handler does, and the trap entry about `force` is the long version.
            $this->invalidate();
            $this->requestRender();

            return $rest;
        }

        // Still mid-sequence: wait for the rest.
        if (preg_match('/\x1b(\[6?;?[\d;]*)?$/', $this->cellSizeBuffer) === 1) {
            return '';
        }

        $rest = $this->cellSizeBuffer;
        $this->cellSizeBuffer = '';
        $this->awaitingCellSize = false;

        return $rest;
    }

    public function stop(): void
    {
        if ($this->altScreen) {
            $this->stopSelectionAutoScroll();
            $this->selectionPressActive = false;
            $this->flashes->dispose();
            $this->terminal->write(
                self::BEGIN_SYNCHRONIZED_OUTPUT . self::DISABLE_MOUSE . self::ENABLE_AUTOWRAP
                . self::EXIT_ALT_SCREEN . self::END_SYNCHRONIZED_OUTPUT,
            );
        }

        $this->terminal->showCursor();
        $this->terminal->stop();
    }

    /**
     * Draw on the next turn of the loop.
     *
     * Deferred rather than immediate so that a burst of events — a token arriving, a key pressed,
     * a resize — costs one frame instead of three.
     *
     * **`$force` means "the screen is not ours any more"**, and the only callers that can say that
     * are the ones coming back from a program that owned it: `$VISUAL`, or a suspend. It is *not*
     * what a resize needs, which is what this used to say: the resize handler asks for a plain
     * render and the width-changed path clears by itself — see the trap in CLAUDE.md, which is
     * where that correction came from.
     *
     * So it throws away what the screen is believed to hold **and asks for a clear**. Emptying the
     * record alone is not enough: the renderer reads an empty `previousLines` as the first frame
     * ever, which writes every line from wherever the cursor is with nothing cleared — and a
     * full-screen editor restores what it found on the way out, so what is there is pig's own last
     * frame and the new one lands underneath it.
     */
    public function requestRender(bool $force = false, bool $resize = false): void
    {
        if ($force) {
            $this->previousLines = [];
            $this->previousWidth = 0;
            $this->cursorRow = 0;
            $this->cursorColumn = 0;
            $this->screenIsLost = true;
        }

        if ($this->renderRequested) {
            // A content change joining a pending resize-only request makes it a real one.
            $this->onlyResizePending = $this->onlyResizePending && $resize;

            return;
        }

        $this->renderRequested = true;
        $this->onlyResizePending = $resize;

        // Within the interval of the last frame, the next one waits for the rest of it; a
        // burst of requests in that window becomes one frame. Otherwise it is the next turn.
        $elapsed = microtime(true) - $this->lastRenderAt;

        if ($elapsed < self::MIN_RENDER_INTERVAL) {
            $this->renderTimer ??= Loop::get()->delay(self::MIN_RENDER_INTERVAL - $elapsed, function (): void {
                $this->renderTimer = null;
                $this->renderNow();
            });

            return;
        }

        Loop::get()->defer($this->renderNow(...));
    }

    private function renderNow(): void
    {
        if (!$this->renderRequested) {
            return;
        }

        $this->renderRequested = false;
        $onlyResize = $this->onlyResizePending;
        $this->onlyResizePending = false;

        // A resize that landed on the same cell size — most of the signals a drag delivers —
        // changes nothing on screen: no render, no frame, no flash.
        if ($onlyResize && $this->previousWidth === $this->terminal->columns() && $this->previousHeight === $this->terminal->rows()) {
            return;
        }

        $this->lastRenderAt = microtime(true);
        $this->draw();
    }

    /** @var array<int, Closure(string): (bool|array{consume?: bool, data?: string}|null)> */
    private array $inputListeners = [];

    private int $nextListenerId = 0;

    /**
     * Intercept or observe raw terminal input before components see it. Upstream's `tui.addInputListener()`.
     *
     * A listener that returns true or `['consume' => true]` stops the keystroke from reaching
     * whatever holds focus. A returned `['data' => $text]` replaces the input for subsequent
     * listeners and the focused component.
     *
     * @param Closure(string): (bool|array{consume?: bool, data?: string}|null) $listener
     * @return Closure(): void call it to stop listening
     */
    public function onInput(Closure $listener): Closure
    {
        $id = $this->nextListenerId++;
        $this->inputListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->inputListeners[$id]);
        };
    }

    private function handleInput(string $data): void
    {
        if ($this->altScreen) {
            $data = $this->handleViewportInput($data);

            if ($data === '') {
                return;
            }
        }

        foreach ($this->inputListeners as $listener) {
            $res = $listener($data);

            if ($res === true || (is_array($res) && ($res['consume'] ?? false))) {
                return;
            }

            if (is_array($res) && isset($res['data'])) {
                $data = (string) $res['data'];
            }
        }

        if ($this->awaitingCellSize) {
            $data = $this->takeCellSizeReply($data);

            if ($data === '') {
                return;
            }
        }

        if ($this->onDebug !== null && Keys::isShiftCtrlD($data)) {
            ($this->onDebug)();

            return;
        }

        // Ctrl+C included: the focused component decides what it means, because in an
        // editor it is "copy" and at an empty prompt it is "quit".
        if ($this->focused instanceof InputHandler) {
            $this->focused->handleInput($data);
            $this->requestRender();
        }
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->viewportRenderer !== null) {
            return ($this->viewportRenderer)($width, $this->terminal->rows());
        }

        return parent::render($width);
    }

    private function draw(): void
    {
        $width = $this->terminal->columns();
        $height = $this->terminal->rows();
        $lines = $this->render($width);

        $widthChanged = $this->previousWidth !== 0 && $this->previousWidth !== $width;

        if ($this->altScreen) {
            $this->drawAltScreen($lines, $width, $height);

            return;
        }

        if ($this->previousLines === []) {
            $this->paint($this->wholeFrame($lines, $width, clear: $this->screenIsLost), $lines, $width);

            return;
        }

        if ($widthChanged) {
            $this->paint($this->wholeFrame($lines, $width, clear: true), $lines, $width);

            return;
        }

        $firstChanged = $this->firstDifference($lines);

        if ($firstChanged === null) {
            return;
        }

        // The window shows the last $height lines of the frame, so it starts at
        // count - height. A change above that is in scrollback, out of the cursor's reach.
        //
        // Counted from the frame and not from cursorRow, which is where the *cursor* is: with
        // only the changed lines rewritten that is wherever the last change was, which may be
        // anywhere, and the top of the window is not a fact about it.
        $windowStart = max(0, count($this->previousLines) - $height);

        if ($firstChanged < $windowStart) {
            // Upstream redraws the whole screen here, and so did this — and the case that made
            // it a fault is a dialog taller than the terminal has room for: the working spinner
            // sits *above* the overlay, the overlay pushed it out of the window, and the spinner
            // ticks twelve times a second. Twelve `\e[2J` a second, the screen flashing for as
            // long as the question stood.
            //
            // A full redraw cannot show a line that is still above the window afterwards; all it
            // does is make the scrollback right at the price of the flash. So: a change that the
            // *new* frame would bring into the window is redrawn, as upstream does, because that
            // is the only way to show it. One that stays above the window is **left undrawn and
            // unrecorded** — the next frame finds it again and leaves it again — and only what is
            // visible is rewritten. Unrecorded, not recorded as drawn: that is what makes the
            // shrink case above find it and redraw.
            // The second condition is a frame that shrank to above the old window: the rows
            // left to draw are all in scrollback, which is upstream's "deleted lines moved the
            // viewport up" case and a full redraw there too.
            if ($firstChanged >= max(0, count($lines) - $height) || count($lines) <= $windowStart) {
                $this->paint($this->wholeFrame($lines, $width, clear: true), $lines, $width);

                return;
            }

            $visible = $this->firstDifference($lines, $windowStart);

            if ($visible === null) {
                return;
            }

            $frame = $this->changedLines($visible, $lines, $width);
            $this->terminal->write("\x1b[?2026h" . $frame . $this->caretMove($width) . "\x1b[?2026l");
            $this->commit([...array_slice($this->previousLines, 0, $windowStart), ...array_slice($lines, $windowStart)], $width);

            return;
        }

        $this->paint($this->changedLines($firstChanged, $lines, $width), $lines, $width);
    }

    /**
     * Put one frame on the screen: the lines, then the caret, in a single write.
     *
     * @param list<string> $lines
     */
    private function paint(string $frame, array $lines, int $width): void
    {
        $this->terminal->write("\x1b[?2026h" . $frame . $this->caretMove($width) . "\x1b[?2026l");
        $this->commit($lines, $width);
    }

    /**
     * Move the terminal's cursor to the focused component's caret.
     *
     * Writing a frame leaves the cursor at the end of the last line, which is the bottom
     * of the screen. An input method draws the text being composed, and its candidate
     * list, wherever that cursor is — so typing Chinese put the pinyin and the candidates
     * over the footer instead of in the box they were going into.
     *
     * **Nothing is written when the cursor is already there**, which after a frame that
     * rewrote one line well above the prompt is the only way the composing line is left
     * alone completely: a move away and back is still a move, and the candidate window
     * follows it.
     *
     * The cursor stays hidden: what is seen is still the component's own inverted cell.
     * This is only about where the terminal believes it is.
     */
    /**
     * One alternate-screen frame — upstream's `TuiAltScreen.doRender()`.
     *
     * Every row of the window is addressed absolutely, the frame is exactly the window's height,
     * and autowrap is off for as long as the alternate screen is up. With autowrap on, one line the
     * terminal measures a cell wider than `Width::visible()` does (an emoji, an ambiguous-width
     * character) wraps; on the last row that scrolls the whole screen up a line, the dock with it,
     * and the row-by-row diff below never finds out. A height change redraws everything for the
     * same reason: the terminal may have moved what it was showing.
     *
     * @param list<string> $lines
     */
    private function drawAltScreen(array $lines, int $width, int $height): void
    {
        $dropped = max(0, count($lines) - $height);
        if ($dropped > 0) {
            $lines = array_slice($lines, $dropped);
        }

        foreach ($lines as $row => $line) {
            $this->checkWidth($lines, $row, $width);
        }

        $screen = $this->applySelection($lines);
        $screen = $this->compositeFlashes($screen, $width, $height);

        $fullRedraw = $this->previousLines === []
            || $this->screenIsLost
            || $this->previousWidth !== $width
            || $this->previousHeight !== $height;

        $buffer = self::BEGIN_SYNCHRONIZED_OUTPUT;
        if ($fullRedraw) {
            $buffer .= "\x1b[2J";
        }

        for ($row = 0; $row < $height; $row++) {
            if (!$fullRedraw && ($screen[$row] ?? '') === ($this->previousLines[$row] ?? null)) {
                continue;
            }

            $buffer .= "\x1b[" . ($row + 1) . ";1H\x1b[2K" . ($screen[$row] ?? '');
        }

        $this->terminal->write($buffer . $this->caretMove($width, -$dropped) . self::END_SYNCHRONIZED_OUTPUT);

        $frame = [];
        for ($row = 0; $row < $height; $row++) {
            $frame[] = $screen[$row] ?? '';
        }

        $this->commit($frame, $width);
    }

    /**
     * Take the mouse and focus reports out of a read and act on them; what is left is keyboard
     * input for the listeners and the focused component. Upstream's `handleViewportInput()`.
     *
     * One read can carry a whole trackpad gesture, so the reports are found anywhere in it rather
     * than only as the whole of it. Inside a bracketed paste nothing is taken out: pasted text
     * is text, whatever bytes it holds.
     */
    private function handleViewportInput(string $data): string
    {
        if (!str_contains($data, "\x1b[") || str_contains($data, "\x1b[200~")) {
            return $data;
        }

        $rest = preg_replace_callback(self::MOUSE_REPORT, function (array $match): string {
            $this->handleViewportReport($match[0]);

            return '';
        }, $data);

        if ($rest === null) {
            throw new TuiError('Splitting mouse reports failed: ' . preg_last_error_msg());
        }

        return $rest;
    }

    private function handleViewportReport(string $report): void
    {
        if ($report === self::FOCUS_OUT) {
            $hadActiveSelection = $this->selectionPressActive;
            $hadNonEmptyActiveSelection = $hadActiveSelection && $this->selectionBounds() !== null;
            $this->selectionPressActive = false;
            $this->stopSelectionAutoScroll();
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
            $this->primaryScrollView?->scrollBy($delta);
            $this->requestRender();

            return;
        }

        $event = self::parseSgrMouseEvent($report);
        if ($event === null) {
            // A legacy X10 report that is not a wheel: swallowed, never typed.
            return;
        }

        if ($this->handleScrollToEndIndicatorMouseEvent($event)) {
            return;
        }

        $this->handleSelectionMouseEvent($event);
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

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function handleScrollToEndIndicatorMouseEvent(array $event): bool
    {
        $rect = $this->primaryScrollView?->indicatorRect();
        if ($rect === null || $event['release'] || ($event['button'] & 32) !== 0 || ($event['button'] & 3) !== 0) {
            return false;
        }

        if ($event['y'] !== $rect['row'] || $event['x'] < $rect['column'] || $event['x'] >= $rect['column'] + $rect['width']) {
            return false;
        }

        $this->primaryScrollView->scrollToBottom();
        $this->requestRender();

        return true;
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

    /**
     * Rows of the primary view that are blank padding above content shorter than the view.
     *
     * Upstream's view has none; pig's `ScrollView` settles short content at the bottom, so screen
     * rows and content rows are this far apart on top of the scroll offset.
     */
    private function primaryTopPadding(ScrollView $view): int
    {
        return max(0, $view->viewportHeight() - $view->contentHeight());
    }

    private function isInPrimaryScrollView(int $y): bool
    {
        return $this->primaryScrollView !== null && $y >= 0 && $y < $this->primaryScrollView->viewportHeight();
    }

    /** @return array{row: int, col: int, scroll: bool}|null */
    private function scrollSelectionPoint(int $x, int $y): ?array
    {
        $view = $this->primaryScrollView;
        if ($view === null || $view->viewportHeight() <= 0) {
            return null;
        }

        $visibleBottom = min($this->terminal->rows() - 1, $view->viewportHeight() - 1);
        if ($visibleBottom < 0) {
            return null;
        }

        $pointerRow = max(0, min($visibleBottom, $y));
        $maxContentRow = max(0, count($view->contentLines()) - 1);

        return [
            'row' => max(0, min($maxContentRow, $view->scrollTop() + $pointerRow - $this->primaryTopPadding($view))),
            'col' => max(0, min($this->terminal->columns() - 1, $x)),
            'scroll' => true,
        ];
    }

    /**
     * @param array{button: int, x: int, y: int, release: bool} $event
     * @return array{row: int, col: int, scroll: bool}
     */
    private function selectionPoint(array $event, bool $inScroll): array
    {
        if ($inScroll) {
            $point = $this->scrollSelectionPoint($event['x'], $event['y']);
            if ($point !== null) {
                return $point;
            }
        }

        return [
            'row' => max(0, min($this->terminal->rows() - 1, $event['y'])),
            'col' => max(0, min($this->terminal->columns() - 1, $event['x'])),
            'scroll' => false,
        ];
    }

    /** @param array{row: int, col: int, scroll: bool, boundary?: bool} $point */
    private function selectionSourceLine(array $point): string
    {
        if ($point['scroll'] && $this->primaryScrollView !== null) {
            return $this->primaryScrollView->contentLines()[$point['row']] ?? '';
        }

        return $this->previousLines[$point['row']] ?? '';
    }

    /**
     * The word under a point, upstream's `getWordSelection()`.
     *
     * Upstream segments with `Intl.Segmenter`; pig has no ext-intl, so a word is a run of
     * `Chars::isWord()` graphemes — the same test Ctrl+W uses — and every other grapheme is a
     * segment of its own. Joiners still glue paths and kebab-case tokens together.
     *
     * @param array{row: int, col: int, scroll: bool, boundary?: bool} $point
     * @return array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}}|null
     */
    private function wordSelection(array $point): ?array
    {
        $line = Ansi::strip($this->selectionSourceLine($point));
        /** @var list<array{start: int, end: int, selectable: bool, joiner: bool}> $segments */
        $segments = [];
        $column = 0;
        $previousWasWord = false;

        foreach (Graphemes::split($line) as $grapheme) {
            $end = $column + Width::visible($grapheme);
            $isWord = Chars::isWord($grapheme);
            if ($isWord && $previousWasWord) {
                $segments[count($segments) - 1]['end'] = $end;
            } else {
                $joiner = in_array($grapheme, self::TERMINAL_WORD_SELECTION_JOINERS, true);
                $segments[] = ['start' => $column, 'end' => $end, 'selectable' => $isWord || $joiner, 'joiner' => $joiner];
            }
            $previousWasWord = $isWord;
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
     * @param array{row: int, col: int, scroll: bool, boundary?: bool} $point
     * @return array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}}
     */
    private function lineSelection(array $point): array
    {
        return [
            'start' => [...$point, 'col' => 0],
            'end' => [...$point, 'col' => Width::visible($this->selectionSourceLine($point)), 'boundary' => true],
        ];
    }

    /** @param array{row: int, col: int, scroll: bool, boundary?: bool} $point */
    private function updateSelectionFocus(array $point): void
    {
        if ($this->selectionGranularity === 'character' || $this->selectionInitialRange === null) {
            $this->selectionFocus = $point;

            return;
        }

        $range = $this->selectionGranularity === 'word' ? $this->wordSelection($point) : $this->lineSelection($point);
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
     * @param array{row: int, col: int, scroll: bool, boundary?: bool} $point
     * @param array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}}|null $word
     */
    private function clickCount(array $point, ?array $word): int
    {
        $now = microtime(true);
        $previous = $this->lastClick;
        $count = $word !== null
            && $previous !== null
            && $now - $previous['timestamp'] <= self::DOUBLE_CLICK_INTERVAL
            && $previous['row'] === $point['row']
            && $previous['scroll'] === $point['scroll']
            && $previous['wordStart'] === $word['start']['col']
            && $previous['wordEnd'] === $word['end']['col']
            ? ($previous['count'] % 3) + 1
            : 1;
        $this->lastClick = $word === null ? null : [
            'timestamp' => $now,
            'count' => $count,
            'row' => $point['row'],
            'scroll' => $point['scroll'],
            'wordStart' => $word['start']['col'],
            'wordEnd' => $word['end']['col'],
        ];

        return $count;
    }

    /** @param array{button: int, x: int, y: int, release: bool} $event */
    private function updateSelectionAutoScroll(array $event): void
    {
        $view = $this->primaryScrollView;
        if (($this->selectionAnchor['scroll'] ?? false) !== true || $view === null || $view->viewportHeight() <= 0) {
            $this->stopSelectionAutoScroll();

            return;
        }

        $visibleTop = 0;
        $visibleBottom = min($this->terminal->rows() - 1, $view->viewportHeight() - 1);
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
        $view = $this->primaryScrollView;
        $pointer = $this->selectionDragPointer;
        $direction = $this->selectionAutoScrollDirection;
        if (($this->selectionAnchor['scroll'] ?? false) !== true || $view === null || $pointer === null || $direction === 0) {
            $this->stopSelectionAutoScroll();

            return;
        }

        if ($view->scrollBy($direction) === $direction) {
            $this->stopSelectionAutoScroll();

            return;
        }

        $point = $this->scrollSelectionPoint($pointer['x'], $pointer['y']);
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

        $point = $this->selectionPoint($event, $this->selectionAnchor['scroll'] ?? false);

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
        $anchor = $this->selectionPoint($event, $this->isInPrimaryScrollView($event['y']));
        $word = $this->wordSelection($anchor);
        $clickCount = $this->clickCount($anchor, $word);
        $range = match ($clickCount) {
            2 => $word,
            3 => $this->lineSelection($anchor),
            default => null,
        };
        $this->selectionGranularity = $range === null ? 'character' : ($clickCount === 2 ? 'word' : 'line');
        $this->selectionInitialRange = $range;
        $this->selectionAnchor = $range['start'] ?? $anchor;
        $this->selectionFocus = $range['end'] ?? $anchor;
        $this->selectionDragged = false;
        $this->requestRender();
    }

    /** @return array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}}|null */
    private function selectionBounds(): ?array
    {
        $anchor = $this->selectionAnchor;
        $focus = $this->selectionFocus;
        if ($anchor === null || $focus === null || $anchor['scroll'] !== $focus['scroll']) {
            return null;
        }

        if ($anchor['row'] === $focus['row'] && $anchor['col'] === $focus['col']) {
            return null;
        }

        $anchorBeforeFocus = $anchor['row'] < $focus['row'] || ($anchor['row'] === $focus['row'] && $anchor['col'] < $focus['col']);

        return $anchorBeforeFocus ? ['start' => $anchor, 'end' => $focus] : ['start' => $focus, 'end' => $anchor];
    }

    /**
     * @param array{start: array{row: int, col: int, scroll: bool, boundary?: bool}, end: array{row: int, col: int, scroll: bool, boundary?: bool}} $selection
     * @return array{start: int, end: int}
     */
    private static function selectionColumns(string $line, int $row, array $selection, int $minColumn = 0, ?int $maxColumn = null): array
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

    private function activeSelectionText(): ?string
    {
        $selection = $this->selectionBounds();
        if ($selection === null) {
            return null;
        }

        $sourceLines = $this->previousLines;
        if ($selection['start']['scroll']) {
            if ($this->primaryScrollView === null) {
                return null;
            }
            $sourceLines = $this->primaryScrollView->contentLines();
        }

        $lines = [];
        for ($row = $selection['start']['row']; $row <= $selection['end']['row']; $row++) {
            $line = $sourceLines[$row] ?? '';
            $columns = self::selectionColumns($line, $row, $selection);
            $lines[] = rtrim(Ansi::strip(Width::sliceByColumn($line, $columns['start'], max(0, $columns['end'] - $columns['start']))));
        }

        $text = implode("\n", $lines);

        return $text === '' ? null : $text;
    }

    private function copySelectionToClipboard(): bool
    {
        $text = $this->activeSelectionText();
        if ($text === null) {
            return false;
        }

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

    private static function selectionHighlight(string $text): string
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
    private function applySelection(array $screen): array
    {
        $selection = $this->selectionBounds();
        if ($selection === null) {
            return $screen;
        }

        $screenSelection = $selection;
        $minRow = 0;
        $maxRow = count($screen) - 1;
        if ($selection['start']['scroll']) {
            $view = $this->primaryScrollView;
            if ($view === null) {
                return $screen;
            }
            $maxRow = min(count($screen) - 1, $view->viewportHeight() - 1);
            $offset = $this->primaryTopPadding($view) - $view->scrollTop();
            $screenSelection['start']['row'] += $offset;
            $screenSelection['end']['row'] += $offset;
        }

        foreach ($screen as $row => $line) {
            if ($row < $minRow || $row > $maxRow || $row < $screenSelection['start']['row'] || $row > $screenSelection['end']['row'] || self::containsImage($line)) {
                continue;
            }

            $lineWidth = Width::visible($line);
            $columns = self::selectionColumns($line, $row, $screenSelection, 0, $this->terminal->columns());
            if ($columns['end'] <= $columns['start']) {
                continue;
            }

            $before = Width::sliceByColumn($line, 0, $columns['start']);
            $selected = Width::sliceByColumn($line, $columns['start'], $columns['end'] - $columns['start']);
            $after = Width::sliceByColumn($line, $columns['end'], max(0, $lineWidth - $columns['end']));
            $screen[$row] = $before . self::selectionHighlight($selected) . $after;
        }

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

    private function caretMove(int $width, int $rowOffset = 0): string
    {
        if (!$this->focused instanceof Caret) {
            return '';
        }

        $caret = $this->focused->caret($width);
        $top = $caret === null ? null : $this->rowOf($this->focused, $width);

        if ($caret === null || $top === null) {
            return '';
        }

        $row = $top + $caret[0] + $rowOffset;

        if ($this->altScreen) {
            $this->cursorRow = $row;
            $this->cursorColumn = $caret[1];

            return "\x1b[" . ($row + 1) . ';' . ($caret[1] + 1) . 'H';
        }

        if ($row === $this->cursorRow && $caret[1] === $this->cursorColumn) {
            return '';
        }

        $up = $this->cursorRow - $row;
        $buffer = $up > 0 ? "\x1b[{$up}A" : ($up < 0 ? "\x1b[" . -$up . 'B' : '');
        $buffer .= "\r" . ($caret[1] > 0 ? "\x1b[{$caret[1]}C" : '');

        // Recorded, or the next differential draw would count rows from the bottom of a
        // frame the cursor is no longer at the bottom of.
        $this->cursorRow = $row;
        $this->cursorColumn = $caret[1];

        return $buffer;
    }

    /** @param list<string> $lines */
    private function wholeFrame(array $lines, int $width, bool $clear): string
    {
        // \e[3J clears the scrollback as well, so a redraw does not leave the previous
        // frame sitting above the new one for the user to scroll back into. **Screen first,
        // scrollback last**, which is upstream's order: a terminal paints what it is told in the
        // order it is told, and clearing the visible rows before the new frame arrives is the
        // part a person is waiting on — the scrollback can go afterwards.
        // \r because the caret may have left the cursor part-way along a line, and the
        // first line below is written from wherever it is.
        $buffer = $clear ? "\x1b[2J\x1b[H\x1b[3J" : "\r";

        foreach ($lines as $index => $line) {
            // Checked here as well as in changedLines, and for the same reason. A too-wide
            // line wraps, and every cursor move after it lands a row low — but this path
            // draws the *first* frame, whose top half a differential redraw never
            // revisits, so without this the corruption has no visible cause at all.
            $this->checkWidth($lines, $index, $width);
            $buffer .= ($index > 0 ? "\r\n" : '') . $line;
        }

        // After writing N lines the cursor sits at the end of the last one, and the \r puts
        // it at a column this can state rather than measure — see $cursorColumn.
        $this->cursorRow = count($lines) - 1;
        $this->cursorColumn = 0;

        return $buffer . "\r";
    }

    /**
     * Rewrite the lines that differ, grow or shrink the frame, and leave nothing else touched.
     *
     * $from is the first line that differs, so there is nothing to do above it. Below it every
     * line is compared rather than rewritten, because a change high in the frame says nothing
     * about the lines under it: a spinner tick above the prompt is one line, not everything
     * from the spinner to the footer.
     *
     * Three regions, and they need different escapes, which is the whole reason this is not one
     * loop. A line the last frame also had is addressed with a relative cursor move, because it
     * is already on the screen. A line the frame has **grown** by is not: it is written after a
     * `\r\n`, which is what makes the terminal scroll to make room — a cursor-down at the bottom
     * of the screen stays where it is, so addressing a row that does not exist yet writes over
     * the last one instead. And a line the frame has **shrunk** by has to be erased where it
     * sits, from the new last line downwards.
     *
     * That last region is where this used to be wrong rather than merely wasteful. Rewriting
     * from $from down leaves the cursor at the last line it *wrote*, and with the change beyond
     * the new frame's end — a loader vanishing from the bottom, which is every turn — it wrote
     * none, so the erase counted from one row too low: the first dead line survived and the
     * sweep ran one row past the frame, scrolling the screen to reach a line that was never ours.
     *
     * @param list<string> $lines
     */
    private function changedLines(int $from, array $lines, int $width): string
    {
        $old = count($this->previousLines);
        $new = count($lines);
        $buffer = '';
        $row = $this->cursorRow;

        for ($index = $from; $index < min($new, $old); $index++) {
            if ($this->previousLines[$index] === $lines[$index]) {
                continue;
            }

            $this->checkWidth($lines, $index, $width);

            // \e[2K per line rather than one \e[J for the rest of the screen: clearing to
            // the end of the screen makes xterm.js flicker.
            $buffer .= self::moveTo($row, $index) . "\x1b[2K" . $lines[$index];
            $row = $index;
        }

        if ($new > $old) {
            $buffer .= self::moveTo($row, $old - 1);

            for ($index = $old; $index < $new; $index++) {
                $this->checkWidth($lines, $index, $width);
                $buffer .= "\r\n\x1b[2K" . $lines[$index];
            }

            $row = $new - 1;
        }

        if ($old > $new) {
            // Down with `\e[B`, never `\r\n`: a newline on the terminal's last row **scrolls**,
            // and the vanished rows are still on the screen, so the cursor can be moved to them.
            // This is upstream's own sweep — `\r\n\x1b[2K` per vanished line — and what it costs
            // is the whole screen shifting up by that many rows every time the frame shrinks,
            // which is the end of every turn: the working loader and its spacer go, and the
            // footer is left two rows above the bottom with blank rows under it. Measured on a
            // real pty through a VT emulator; a hand-written emulator that did not scroll on
            // newline said the old sequence was fine, which is why it stayed for a day.
            $extra = $old - $new;
            $buffer .= self::moveTo($row, $new - 1) . str_repeat("\x1b[1B\r\x1b[2K", $extra) . "\x1b[{$extra}A";
            $row = $new - 1;
        }

        $this->cursorRow = $row;
        $this->cursorColumn = 0;

        return $buffer . "\r";
    }

    /**
     * Get the cursor from one row of the frame to another, at column 0.
     *
     * The `\r` is not optional and is not only for the column: it is what clears the pending
     * wrap a line exactly as wide as the terminal leaves behind.
     */
    private static function moveTo(int $from, int $to): string
    {
        $move = $to - $from;
        $vertical = $move > 0 ? "\x1b[{$move}B" : ($move < 0 ? "\x1b[" . -$move . 'A' : '');

        return $vertical . "\r";
    }

    /**
     * Record what the screen now holds.
     *
     * $width is the width the frame was rendered at, not the terminal's width now: a
     * resize during the write would otherwise be recorded as already drawn.
     *
     * Where the cursor is, is recorded by whatever built the frame — see $cursorColumn.
     *
     * @param list<string> $lines
     */
    /** The width the last frame was drawn for — for a test to ask which of a burst of sizes won. */
    public function renderedWidth(): int
    {
        return $this->previousWidth;
    }

    private function commit(array $lines, int $width): void
    {
        $this->previousLines = $lines;
        $this->previousWidth = $width;
        $this->previousHeight = $this->terminal->rows();
        $this->screenIsLost = false;
    }

    /**
     * The first line that differs from the last frame, or null when nothing did.
     *
     * @param list<string> $lines
     */
    private function firstDifference(array $lines, int $from = 0): ?int
    {
        $count = max(count($lines), count($this->previousLines));

        for ($index = $from; $index < $count; $index++) {
            if (($this->previousLines[$index] ?? '') !== ($lines[$index] ?? '')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Refuse to draw a line wider than the terminal.
     *
     * The terminal would wrap it, putting every subsequent cursor move one line out and
     * corrupting the display in a way that is almost impossible to read back from the
     * screen. Failing here names the component that produced it instead.
     *
     * @param list<string> $lines
     */
    private function checkWidth(array $lines, int $index, int $width): void
    {
        $line = $lines[$index];

        if (self::containsImage($line)) {
            return;
        }

        $visible = Width::visible($line);

        if ($visible <= $width) {
            return;
        }

        $log = sys_get_temp_dir() . '/pig-render-' . getmypid() . '.log';
        $dump = "Line {$index} is {$visible} columns wide\n" . self::widths($lines, $width);

        file_put_contents($log, $dump);

        throw new TuiError("Rendered line {$index} is {$visible} columns wide, terminal is {$width}. Lines written to {$log}");
    }

    /**
     * The frame as it stands, line by line, with the columns each one takes.
     *
     * **Two readers, which is why this is a method.** `checkWidth()` writes it when a line is too
     * wide to draw, and the application's debug key writes it on demand — the same question asked
     * after a fault and before one. Every width bug in this repository was found by looking at
     * exactly this, and until now the only way to see it was to cause the fault.
     */
    public function frame(): string
    {
        $width = $this->terminal->columns();

        return self::widths($this->render($width), $width);
    }

    /**
     * One line per rendered line, with its width and its bytes.
     *
     * The escapes are kept as escapes — `\e` and not an escape that moves the cursor of whatever
     * is reading the log. A column count next to a line whose codes are invisible is the pair that
     * makes a padding bug obvious.
     *
     * @param list<string> $lines
     */
    private static function widths(array $lines, int $width): string
    {
        $dump = ["Terminal width: {$width}", 'Lines: ' . count($lines), ''];

        foreach ($lines as $number => $line) {
            $dump[] = "[{$number}] (w=" . Width::visible($line) . ') ' . json_encode($line);
        }

        return implode("\n", $dump) . "\n";
    }

    /** Image protocols put their payload inline, where a column count means nothing. */
    private static function containsImage(string $line): bool
    {
        return str_contains($line, "\x1b_G") || str_contains($line, "\x1b]1337;File=");
    }
}
