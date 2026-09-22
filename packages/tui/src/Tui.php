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
 * So each frame is compared against the last, the cursor is moved to the first line that
 * differs, and everything from there down is rewritten. When the first difference is above
 * the top of the window there is nothing to move the cursor to, and the whole screen is
 * redrawn instead.
 */
class Tui extends Container
{
    /** @var list<string> */
    private array $previousLines = [];

    private int $previousWidth = 0;

    private ?Component $focused = null;

    /** Where the cursor is, counted from the first line this drew. */
    private int $cursorRow = 0;

    private bool $renderRequested = false;

    /** The terminal was asked how big a cell is and has not answered yet. */
    private bool $awaitingCellSize = false;

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
                $this->requestRender();
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

            // Every image was measured against the wrong cell size until now.
            $this->invalidate();
            $this->requestRender(true);

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
     * Deferred rather than immediate so that a burst of events — a token arriving, a key
     * pressed, a resize — costs one frame instead of three. $force throws away what the
     * screen is believed to hold, which is what a resize needs.
     */
    public function requestRender(bool $force = false): void
    {
        if ($force) {
            $this->previousLines = [];
            $this->previousWidth = 0;
            $this->cursorRow = 0;
        }

        if ($this->renderRequested) {
            return;
        }

        $this->renderRequested = true;

        Loop::get()->defer(function (): void {
            $this->renderRequested = false;
            $this->draw();
        });
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
            $this->drawAll($lines, $width, clear: false);
            $this->placeCaret($width);

            return;
        }

        if ($widthChanged) {
            $this->drawAll($lines, $width, clear: true);
            $this->placeCaret($width);

            return;
        }

        $firstChanged = $this->firstDifference($lines);

        if ($firstChanged === null) {
            return;
        }

        // The window shows cursorRow - height + 1 through cursorRow. A change above that
        // is in scrollback, out of the cursor's reach, so the screen is redrawn instead.
        if ($firstChanged < $this->cursorRow - $height + 1) {
            $this->drawAll($lines, $width, clear: true);
            $this->placeCaret($width);

            return;
        }

        $this->drawFrom($firstChanged, $lines, $width);
        $this->placeCaret($width);
    }

    /**
     * Leave the terminal's cursor where the focused component's caret is.
     *
     * Writing a frame leaves the cursor at the end of the last line, which is the bottom
     * of the screen. An input method draws the text being composed, and its candidate
     * list, wherever that cursor is — so typing Chinese put the pinyin and the candidates
     * over the footer instead of in the box they were going into.
     *
     * The cursor stays hidden: what is seen is still the component's own inverted cell.
     * This is only about where the terminal believes it is.
     */
    private function placeCaret(int $width): void
    {
        if (!$this->focused instanceof Caret) {
            return;
        }

        $caret = $this->focused->caret($width);
        $top = $caret === null ? null : $this->rowOf($this->focused, $width);

        if ($caret === null || $top === null) {
            return;
        }

        $row = $top + $caret[0];
        $up = $this->cursorRow - $row;
        $buffer = $up > 0 ? "\x1b[{$up}A" : ($up < 0 ? "\x1b[" . -$up . 'B' : '');
        $buffer .= "\r" . ($caret[1] > 0 ? "\x1b[{$caret[1]}C" : '');

        $this->terminal->write($buffer);

        // Recorded, or the next differential draw would count rows from the bottom of a
        // frame the cursor is no longer at the bottom of.
        $this->cursorRow = $row;
    }

    /** @param list<string> $lines */
    private function drawAll(array $lines, int $width, bool $clear): void
    {
        // \e[3J clears the scrollback as well, so a redraw does not leave the previous
        // frame sitting above the new one for the user to scroll back into.
        // \r because the caret may have left the cursor part-way along a line, and the
        // first line below is written from wherever it is.
        $buffer = "\x1b[?2026h" . ($clear ? "\x1b[3J\x1b[2J\x1b[H" : "\r");

        foreach ($lines as $index => $line) {
            // Checked here as well as in drawFrom, and for the same reason. A too-wide
            // line wraps, and every cursor move after it lands a row low — but this path
            // draws the *first* frame, whose top half a differential redraw never
            // revisits, so without this the corruption has no visible cause at all.
            $this->checkWidth($lines, $index, $width);
            $buffer .= ($index > 0 ? "\r\n" : '') . $line;
        }

        $this->terminal->write($buffer . "\x1b[?2026l");
        $this->commit($lines, $width);
    }

    /**
     * Rewrite from $from down, then erase whatever the last frame left below.
     *
     * @param list<string> $lines
     */
    private function drawFrom(int $from, array $lines, int $width): void
    {
        $buffer = "\x1b[?2026h";
        $move = $from - $this->cursorRow;

        if ($move > 0) {
            $buffer .= "\x1b[{$move}B";
        } elseif ($move < 0) {
            $up = -$move;
            $buffer .= "\x1b[{$up}A";
        }

        $buffer .= "\r";

        for ($index = $from; $index < count($lines); $index++) {
            // \e[2K per line rather than one \e[J for the rest of the screen: clearing to
            // the end of the screen makes xterm.js flicker.
            $buffer .= ($index > $from ? "\r\n" : '') . "\x1b[2K";
            $this->checkWidth($lines, $index, $width);
            $buffer .= $lines[$index];
        }

        $extra = count($this->previousLines) - count($lines);

        if ($extra > 0) {
            $buffer .= str_repeat("\r\n\x1b[2K", $extra) . "\x1b[{$extra}A";
        }

        $this->terminal->write($buffer . "\x1b[?2026l");
        $this->commit($lines, $width);
    }

    /**
     * Record what the screen now holds.
     *
     * $width is the width the frame was rendered at, not the terminal's width now: a
     * resize during the write would otherwise be recorded as already drawn.
     *
     * @param list<string> $lines
     */
    private function commit(array $lines, int $width): void
    {
        // After writing N lines the cursor sits at the end of the last one.
        $this->cursorRow = count($lines) - 1;
        $this->previousLines = $lines;
        $this->previousWidth = $width;
    }

    /**
     * The first line that differs from the last frame, or null when nothing did.
     *
     * @param list<string> $lines
     */
    private function firstDifference(array $lines): ?int
    {
        $count = max(count($lines), count($this->previousLines));

        for ($index = 0; $index < $count; $index++) {
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
        $dump = ["Terminal width: {$width}", "Line {$index} is {$visible} columns wide", ''];

        foreach ($lines as $number => $dumped) {
            $dump[] = "[{$number}] (w=" . Width::visible($dumped) . ") {$dumped}";
        }

        file_put_contents($log, implode("\n", $dump) . "\n");

        throw new TuiError("Rendered line {$index} is {$visible} columns wide, terminal is {$width}. Lines written to {$log}");
    }

    /** Image protocols put their payload inline, where a column count means nothing. */
    private static function containsImage(string $line): bool
    {
        return str_contains($line, "\x1b_G") || str_contains($line, "\x1b]1337;File=");
    }
}
