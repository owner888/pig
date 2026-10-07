<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The regular-mode renderer — upstream's `TuiMainScreen`: a component tree, drawn by redrawing
 * as little as possible.
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
class TuiMainScreen extends TuiBase
{
    public const string MODE = 'regular';

    /** @var list<string> */
    private array $previousLines = [];

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

    /**
     * Throw away what the screen is believed to hold and ask for a clear — what
     * `requestRender(true)` means here; see its comment in `TuiBase`.
     */
    #[\Override]
    protected function resetRenderState(): void
    {
        $this->previousLines = [];
        $this->previousWidth = 0;
        $this->cursorRow = 0;
        $this->cursorColumn = 0;
        $this->screenIsLost = true;
    }

    #[\Override]
    protected function doRender(): void
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
        $focused = $this->getFocusedComponent();

        if (!$focused instanceof Caret) {
            return '';
        }

        $caret = $focused->caret($width);
        $top = $caret === null ? null : $this->rowOf($focused, $width);

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

}
