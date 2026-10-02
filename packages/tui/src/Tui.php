<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;
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

    public function __construct(public readonly Terminal $terminal)
    {
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

    private function handleInput(string $data): void
    {
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

    private function draw(): void
    {
        $width = $this->terminal->columns();
        $height = $this->terminal->rows();
        $lines = $this->render($width);

        $widthChanged = $this->previousWidth !== 0 && $this->previousWidth !== $width;

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
    private function caretMove(int $width): string
    {
        if (!$this->focused instanceof Caret) {
            return '';
        }

        $caret = $this->focused->caret($width);
        $top = $caret === null ? null : $this->rowOf($this->focused, $width);

        if ($caret === null || $top === null) {
            return '';
        }

        $row = $top + $caret[0];

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
        // frame sitting above the new one for the user to scroll back into.
        // \r because the caret may have left the cursor part-way along a line, and the
        // first line below is written from wherever it is.
        $buffer = $clear ? "\x1b[3J\x1b[2J\x1b[H" : "\r";

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
            $extra = $old - $new;
            $buffer .= self::moveTo($row, $new - 1) . str_repeat("\r\n\x1b[2K", $extra) . "\x1b[{$extra}A";
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
