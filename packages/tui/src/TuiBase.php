<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;
use Pig\Tui\Images\TerminalImage;

/**
 * What both renderers share — upstream's `TuiBase` in `tui.ts`: the terminal, focus, input
 * listeners, the cell-size query and when a frame is drawn. *How* a frame is drawn is
 * `doRender()`, which `TuiMainScreen` and `TuiAltScreen` each implement.
 *
 * pig keeps its own `Caret` interface for where the terminal cursor goes rather than upstream's
 * `CURSOR_MARKER`, and has no overlay stack or terminal-color queries yet.
 */
abstract class TuiBase extends Container implements TUI
{
    /**
     * Frames are at most this far apart — upstream's `MIN_RENDER_INTERVAL_MS`. A window being
     * dragged asks for a frame per SIGWINCH, dozens a second; within the interval they become one.
     */
    public const float MIN_RENDER_INTERVAL = 0.016;

    /** `regular` or `fullscreen`, upstream's `TuiMode`; each subclass names its own as `MODE`. */
    public readonly string $mode;

    /** Global callback for the debug key (Shift+Ctrl+D), called before input reaches the focused component. */
    public ?Closure $onDebug = null;

    private ?Component $focusedComponent = null;

    /** @var list<Closure(string): (bool|array{consume?: bool, data?: string}|null)> */
    private array $inputListeners = [];

    private bool $renderRequested = false;

    private float $lastRenderAt = 0.0;

    private ?string $renderTimer = null;

    /** Whether the pending render came from a resize alone, so a size that did not change draws nothing. */
    private bool $onlyResizePending = false;

    protected bool $stopped = false;

    /** The width the last frame was drawn for. */
    protected int $previousWidth = 0;

    /** The height the last frame was drawn for; a resize that changed neither draws nothing at all. */
    protected int $previousHeight = 0;

    /** The terminal was asked how big a cell is and has not answered yet. */
    private bool $awaitingCellSize = false;

    /** Input held back while that answer might still be arriving. */
    private string $cellSizeBuffer = '';

    public function __construct(public readonly Terminal $terminal)
    {
        $this->mode = static::MODE;
    }

    abstract protected function doRender(): void;

    protected function resetRenderState(): void
    {
    }

    protected function beforeTerminalStart(): void
    {
    }

    protected function afterTerminalStart(): void
    {
    }

    protected function beforeTerminalStop(TuiStopOptions $options): void
    {
    }

    protected function afterTerminalStop(TuiStopOptions $options): void
    {
    }

    #[\Override]
    public function getFocusedComponent(): ?Component
    {
        return $this->focusedComponent;
    }

    #[\Override]
    public function setFocus(?Component $component): void
    {
        $this->focusedComponent = $component;
    }

    #[\Override]
    public function start(): void
    {
        $this->stopped = false;
        $this->beforeTerminalStart();
        $this->terminal->start(
            function (string $data): void {
                $this->handleTerminalInput($data);
            },
            function (): void {
                // Not forced: force throws away what the screen holds, and then the
                // renderer cannot tell a resize from a first frame and skips the clear.
                $this->scheduleRender(onlyResize: true);
            },
        );
        $this->afterTerminalStart();
        $this->terminal->hideCursor();
        $this->queryCellSize();
        $this->requestRender();
    }

    /**
     * Ask the terminal how big a character cell is, in pixels.
     *
     * Only worth asking on a terminal that draws images, because that is the only thing
     * the answer is used for. The reply comes back as *input*, so the next few keystrokes
     * have to be sifted for it before they reach a component.
     */
    private function queryCellSize(): void
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
            // previousLines, which `doRender()` reads as "first frame ever" and writes with no
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

    #[\Override]
    public function stop(?TuiStopOptions $options = null): void
    {
        $options ??= new TuiStopOptions();
        $this->stopped = true;
        $this->cancelRenderTimer();
        $this->beforeTerminalStop($options);
        $this->terminal->showCursor();
        $this->terminal->stop();
        $this->afterTerminalStop($options);
    }

    /** Draw now, not on the next turn of the loop — upstream's `renderNow()`. */
    #[\Override]
    public function renderNow(bool $force = false): void
    {
        if ($force) {
            $this->resetRenderState();
        }

        $this->renderRequested = false;
        $this->onlyResizePending = false;
        $this->cancelRenderTimer();
        $this->lastRenderAt = microtime(true);
        $this->doRender();
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
    #[\Override]
    public function requestRender(bool $force = false): void
    {
        if ($force) {
            $this->resetRenderState();
        }

        $this->scheduleRender(onlyResize: false);
    }

    private function scheduleRender(bool $onlyResize): void
    {
        if ($this->renderRequested) {
            // A content change joining a pending resize-only request makes it a real one.
            $this->onlyResizePending = $this->onlyResizePending && $onlyResize;

            return;
        }

        $this->renderRequested = true;
        $this->onlyResizePending = $onlyResize;

        // Within the interval of the last frame, the next one waits for the rest of it; a
        // burst of requests in that window becomes one frame. Otherwise it is the next turn.
        $elapsed = microtime(true) - $this->lastRenderAt;

        if ($elapsed < self::MIN_RENDER_INTERVAL) {
            $this->renderTimer ??= Loop::get()->delay(self::MIN_RENDER_INTERVAL - $elapsed, function (): void {
                $this->renderTimer = null;
                $this->flushRender();
            });

            return;
        }

        Loop::get()->defer($this->flushRender(...));
    }

    private function flushRender(): void
    {
        if (!$this->renderRequested || $this->stopped) {
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
        $this->doRender();
    }

    private function cancelRenderTimer(): void
    {
        if ($this->renderTimer === null) {
            return;
        }

        Loop::get()->cancel($this->renderTimer);
        $this->renderTimer = null;
    }

    /**
     * Intercept or observe raw terminal input before components see it. Upstream's `addInputListener()`.
     *
     * A listener that returns true or `['consume' => true]` stops the keystroke from reaching
     * whatever holds focus. A returned `['data' => $text]` replaces the input for subsequent
     * listeners and the focused component.
     *
     * @param Closure(string): (bool|array{consume?: bool, data?: string}|null) $listener
     * @return Closure(): void call it to stop listening
     */
    #[\Override]
    public function addInputListener(Closure $listener): Closure
    {
        $this->inputListeners[] = $listener;

        return function () use ($listener): void {
            $this->removeInputListener($listener);
        };
    }

    #[\Override]
    public function removeInputListener(Closure $listener): void
    {
        $this->inputListeners = array_values(array_filter(
            $this->inputListeners,
            static fn (Closure $registered): bool => $registered !== $listener,
        ));
    }

    private function handleTerminalInput(string $data): void
    {
        foreach ($this->inputListeners as $listener) {
            $res = $listener($data);

            if ($res === true || (is_array($res) && ($res['consume'] ?? false))) {
                return;
            }

            if (is_array($res) && isset($res['data'])) {
                $data = (string) $res['data'];
            }
        }

        if ($data === '') {
            return;
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
        if ($this->focusedComponent instanceof InputHandler) {
            $this->focusedComponent->handleInput($data);
            $this->requestRender();
        }
    }

    /** The width the last frame was drawn for — for a test to ask which of a burst of sizes won. */
    public function renderedWidth(): int
    {
        return $this->previousWidth;
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
    protected function checkWidth(array $lines, int $index, int $width): void
    {
        $line = $lines[$index];

        if (self::isImageLine($line)) {
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

    /** Image protocols put their payload inline, where a column count means nothing — upstream's `isImageLine()`. */
    public static function isImageLine(string $line): bool
    {
        return str_contains($line, "\x1b_G") || str_contains($line, "\x1b]1337;File=");
    }
}
