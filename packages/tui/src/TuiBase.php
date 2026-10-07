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
 * Not ported yet: the overlay stack and terminal-colour queries.
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

    private bool $immediateRenderScheduled = false;

    private float $lastRenderAt = 0.0;

    private ?string $renderTimer = null;

    private bool $showHardwareCursor = false;

    private bool $clearOnShrink = false;

    protected int $fullRedrawCount = 0;

    protected bool $stopped = false;

    /** The width the last frame was drawn for. */
    protected int $previousWidth = 0;

    /** The height the last frame was drawn for. */
    protected int $previousHeight = 0;

    /** The terminal was asked how big a cell is and has not answered yet. */
    private bool $awaitingCellSize = false;

    /** Input held back while that answer might still be arriving. */
    private string $cellSizeBuffer = '';

    /** @param string|null $logDirectory where crash dumps go; the system temp directory when null */
    public function __construct(
        public readonly Terminal $terminal,
        ?bool $showHardwareCursor = null,
        protected readonly ?string $logDirectory = null,
    ) {
        $this->mode = static::MODE;
        if ($showHardwareCursor !== null) {
            $this->showHardwareCursor = $showHardwareCursor;
        }
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
        if ($this->focusedComponent instanceof Focusable) {
            $this->focusedComponent->focused = false;
        }

        $this->focusedComponent = $component;

        if ($component instanceof Focusable) {
            $component->focused = true;
        }
    }

    /** How many full redraws there have been — for a test to ask whether a frame was one. */
    public function fullRedraws(): int
    {
        return $this->fullRedrawCount;
    }

    #[\Override]
    public function getShowHardwareCursor(): bool
    {
        return $this->showHardwareCursor;
    }

    #[\Override]
    public function setShowHardwareCursor(bool $enabled): void
    {
        if ($this->showHardwareCursor === $enabled) {
            return;
        }

        $this->showHardwareCursor = $enabled;
        if (!$enabled && !$this->stopped) {
            $this->terminal->hideCursor();
        }
        $this->requestRender();
    }

    #[\Override]
    public function getClearOnShrink(): bool
    {
        return $this->clearOnShrink;
    }

    /**
     * Whether to redraw everything when content shrinks. When true, rows the content no longer
     * reaches are cleared; when false (the default) they stay, which redraws less on slow terminals.
     */
    #[\Override]
    public function setClearOnShrink(bool $enabled): void
    {
        $this->clearOnShrink = $enabled;
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
                // Not forced: the renderer compares sizes itself and redraws what a resize needs.
                $this->requestRender();
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
        $this->cancelRenderTimer();
        $this->lastRenderAt = microtime(true);
        $this->doRender();
    }

    /**
     * Draw soon — upstream's `requestRender()`.
     *
     * A plain request is throttled: frames are at most `MIN_RENDER_INTERVAL` apart, so a burst of
     * requests becomes one frame. **`$force` means "the screen is not ours any more"** — coming
     * back from `$VISUAL` or a suspend: what the screen is believed to hold is thrown away, so the
     * next frame redraws everything with a clear, and it is drawn without waiting.
     */
    #[\Override]
    public function requestRender(bool $force = false): void
    {
        if ($force) {
            $this->resetRenderState();
            $this->requestImmediateRender();

            return;
        }

        if ($this->renderRequested) {
            return;
        }

        $this->renderRequested = true;
        Loop::get()->defer($this->scheduleRender(...));
    }

    /** Draw on the next turn, ahead of any throttled frame — keyboard input does not wait for one. */
    private function requestImmediateRender(): void
    {
        $this->cancelRenderTimer();
        $this->renderRequested = true;
        if ($this->immediateRenderScheduled) {
            return;
        }

        $this->immediateRenderScheduled = true;
        Loop::get()->defer(function (): void {
            $this->immediateRenderScheduled = false;
            if ($this->stopped || !$this->renderRequested) {
                return;
            }
            // A previously queued scheduleRender() can create a timer before this runs; input
            // preempts that throttled frame.
            $this->cancelRenderTimer();
            $this->renderRequested = false;
            $this->lastRenderAt = microtime(true);
            $this->doRender();
        });
    }

    private function cancelRenderTimer(): void
    {
        if ($this->renderTimer === null) {
            return;
        }

        Loop::get()->cancel($this->renderTimer);
        $this->renderTimer = null;
    }

    private function scheduleRender(): void
    {
        if ($this->stopped || $this->renderTimer !== null || !$this->renderRequested) {
            return;
        }

        $delay = max(0.0, self::MIN_RENDER_INTERVAL - (microtime(true) - $this->lastRenderAt));
        $this->renderTimer = Loop::get()->delay($delay, function (): void {
            $this->renderTimer = null;
            if ($this->stopped || !$this->renderRequested) {
                return;
            }
            $this->renderRequested = false;
            $this->lastRenderAt = microtime(true);
            $this->doRender();
            if ($this->renderRequested) {
                $this->scheduleRender();
            }
        });
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
            // Keyboard input is latency-sensitive: not the throttled path.
            $this->requestImmediateRender();
        }
    }

    /** The width the last frame was drawn for — for a test to ask which of a burst of sizes won. */
    public function renderedWidth(): int
    {
        return $this->previousWidth;
    }

    /**
     * Find `CURSOR_MARKER` in the visible part of a frame, strip it, and say where it was —
     * upstream's `extractCursorPosition()`. Only the bottom `$height` lines are searched.
     *
     * @param list<string> $lines
     * @return array{row: int, col: int}|null
     */
    protected function extractCursorPosition(array &$lines, int $height): ?array
    {
        $viewportTop = max(0, count($lines) - $height);
        for ($row = count($lines) - 1; $row >= $viewportTop; $row--) {
            $markerIndex = strpos($lines[$row], self::CURSOR_MARKER);
            if ($markerIndex !== false) {
                $before = substr($lines[$row], 0, $markerIndex);
                $lines[$row] = $before . substr($lines[$row], $markerIndex + strlen(self::CURSOR_MARKER));

                return ['row' => $row, 'col' => Width::visible($before)];
            }
        }

        return null;
    }

    /**
     * Close every line's styling and hyperlink at its end, and turn what a terminal draws
     * differently from `Width::visible()` into what it measures — upstream's `applyLineResets()`.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    protected function applyLineResets(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (!self::isImageLine($line)) {
                $lines[$index] = Width::normalizeTerminalOutput($line) . Width::SEGMENT_RESET;
            }
        }

        return $lines;
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

        $log = ($this->logDirectory ?? sys_get_temp_dir()) . '/pig-render-' . getmypid() . '.log';
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
