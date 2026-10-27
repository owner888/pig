<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Pig\Async\Loop;

/**
 * The real terminal: STDIN in raw mode, STDOUT with the cursor hidden.
 *
 * Node hands upstream `setRawMode()` and a `resize` event. PHP has neither, so raw mode
 * goes through `stty` and the resize arrives as SIGWINCH. Input is a readable watcher on
 * the event loop rather than a callback, which is the point of the whole exercise: the
 * same `select()` waits on the keyboard and on the model's socket, so a keystroke can
 * interrupt a response that is still streaming.
 */
final class ProcessTerminal implements Terminal, BufferedTerminal
{
    /** How long `flush()` keeps waiting for a full output buffer to drain, in seconds. */
    private const float DRAIN_TIMEOUT = 5.0;

    /** How much is handed to one `fwrite()`; see the loop in `write()`. */
    private const int WRITE_SLICE = 65536;

    /** DA1, the sentinel behind the program status query: every terminal answers it. */
    private const string DEVICE_ATTRIBUTES_QUERY = "\x1b[c";

    private const string DEVICE_ATTRIBUTES_REPLY = '/\x1b\[\?[\d;]*c/';

    private const string PROGRAM_STATUS_REPLY = '/\x1b\]7501;\?[^\x07\x1b]*(?:\x07|\x1b\\\\)/';

    /** A tail that could be the start of either reply, cut by the read. */
    private const string PARTIAL_PROGRAM_STATUS_REPLY = '/\x1b(?:\](?:7(?:5(?:0(?:1(?:;(?:\?[^\x07\x1b]*\x1b?)?)?)?)?)?)?|\[(?:\?[\d;]*)?)?$/';

    /** Terminal settings as they were before we touched them, for `stty` to restore. */
    private ?string $savedState = null;

    private ?string $inputWatcher = null;

    private ?Closure $onResize = null;

    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    private int $columns = 80;

    private int $rows = 24;

    /** Latest program status, kept across stop() so start() can report it again. */
    private ?ProgramStatus $programStatus = null;

    /** Upstream's `DEFAULT_ESCAPE_TIMEOUT_MS` and `DEFAULT_SSH_ESCAPE_TIMEOUT_MS`. */
    private const int DEFAULT_ESCAPE_TIMEOUT_MS = 10;

    private const int DEFAULT_SSH_ESCAPE_TIMEOUT_MS = 100;

    /** The timer of a lone ESC held back to see whether the rest of an Alt+key follows it. */
    private ?string $escapeTimer = null;

    /**
     * Upstream's `writeLogPath`: where `PIG_TUI_WRITE_LOG` says every byte written goes, resolved
     * once — a directory becomes a `tui-<timestamp>-<pid>.log` file inside it.
     */
    private string $writeLogPath = '';

    /** Upstream's `progressInterval`: the OSC 9;4;3 keepalive, re-sent every second while active. */
    private ?string $progressTimer = null;

    private const string PROGRESS_ACTIVE = "\x1b]9;4;3\x07";

    private const string PROGRESS_CLEAR = "\x1b]9;4;0\x07";

    /** Whether the terminal confirmed OSC 7501 support since the last start(), or `PIG_PROGRAM_STATUS=1`. */
    private bool $programStatusSupported = false;

    /** The support query was sent and its DA1 sentinel has not arrived yet. */
    private bool $programStatusQueryPending = false;

    /** The end of a read that may be a reply still arriving, held for the next read. */
    private string $programStatusReplyBuffer = '';

    /** What was written and the terminal has not taken yet, from `$pendingAt` on. */
    private string $pending = '';

    private int $pendingAt = 0;

    /** The watcher writing `$pending` out as the terminal takes it; null while nothing waits. */
    private ?string $drainWatcher = null;

    /** @var list<Closure(): void> `whenDrained()`'s callbacks */
    private array $onDrained = [];

    /** Once taken, `$pending` is cut back when this much of its front has been written. */
    private const int COMPACT_AFTER = 1 << 20;

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(mixed $input = null, mixed $output = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
        $this->writeLogPath = self::resolveWriteLogPath();

        // A process that ends without `stop()` — a fatal error — still gets its last bytes out.
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable) {
                // The terminal is gone; there is nobody to tell.
            }
        });
    }

    /**
     * Upstream's `resolveEscapeTimeoutMs()` in `terminal.ts`: how long a lone ESC waits for the rest
     * of an escape sequence before it is the Escape key. Legacy Alt+key input is ESC plus another
     * byte, and over SSH the two can arrive in separate reads — so a positive `PIG_TUI_ESC_TIMEOUT`
     * (milliseconds) first, then 100ms when `SSH_CONNECTION` or `SSH_TTY` is set, else 10ms.
     *
     * @param array<string, string>|null $env the environment to read; null for the process's
     */
    public static function resolveEscapeTimeoutMs(?array $env = null): float
    {
        $read = static fn (string $name): string => $env === null ? (string) getenv($name) : ($env[$name] ?? '');

        $configured = $read('PIG_TUI_ESC_TIMEOUT');

        // `Number(…)`: a numeric string, finite and above zero; anything else is not a setting.
        if (is_numeric(trim($configured)) && is_finite((float) $configured) && (float) $configured > 0) {
            return (float) $configured;
        }

        if ($read('SSH_CONNECTION') !== '' || $read('SSH_TTY') !== '') {
            return self::DEFAULT_SSH_ESCAPE_TIMEOUT_MS;
        }

        return self::DEFAULT_ESCAPE_TIMEOUT_MS;
    }

    /**
     * The `writeLogPath` initialiser upstream's `ProcessTerminal` has: `PIG_TUI_WRITE_LOG` unset is no
     * log; an existing directory gets a file per run named for the local time and the process; any
     * other value is the file, appended to.
     */
    private static function resolveWriteLogPath(): string
    {
        $configured = (string) getenv('PIG_TUI_WRITE_LOG');

        if ($configured === '' || !is_dir($configured)) {
            return $configured;
        }

        return rtrim($configured, '/') . '/tui-' . date('Y-m-d_H-i-s') . '-' . getmypid() . '.log';
    }

    #[\Override]
    public function start(Closure $onInput, Closure $onResize): void
    {
        $this->onResize = $onResize;
        $this->savedState = $this->stty('-g');

        // Raw mode: no line buffering, no echo, no signal keys — Ctrl+C becomes a byte the
        // focused component reads, which is how a component gets to decide what it means.
        $this->stty('raw -echo');

        // Bracketed paste: the terminal wraps pasted text in \e[200~ ... \e[201~, so a paste
        // of twenty lines is one event instead of twenty Enters.
        $this->write("\x1b[?2004h");

        // Kitty keyboard protocol: disambiguate (1), so terminals that speak it report keys plain
        // ANSI cannot distinguish — Shift+Enter as \e[13;2u rather than \r — and report alternate
        // keys (4), so Ctrl+Shift+= also says it typed `+` and a `ctrl++` binding matches it
        // (upstream #9880). Not event types (2): pig reads presses only.
        $this->write("\x1b[>5u");

        // The OSC 7501 program status query, with DA1 as its sentinel: a terminal that supports
        // it replies before DA, one that does not answers DA alone. `PIG_PROGRAM_STATUS=1` or `0`
        // (upstream's `PI_PROGRAM_STATUS`) skips the query. Upstream sends it inside its kitty
        // keyboard query, which pig does not make; the sentinel is the part that matters.
        $override = getenv('PIG_PROGRAM_STATUS');
        $this->programStatusSupported = $override === '1';
        $this->programStatusQueryPending = $override !== '1' && $override !== '0';

        if ($this->programStatusQueryPending) {
            $this->write(ProgramStatus::QUERY . self::DEVICE_ATTRIBUTES_QUERY);
        }

        $this->writeProgramStatus();

        $this->measure();
        $this->watchResize();

        stream_set_blocking($this->input, false);

        $this->inputWatcher = Loop::get()->onReadable($this->input, function () use ($onInput): void {
            $data = fread($this->input, 65536);

            if ($data === false || $data === '') {
                return;
            }

            $data = $this->takeProgramStatusReplies($data);

            $data = $this->holdLoneEscape($data, $onInput);

            if ($data === null || $data === '') {
                return;
            }

            $onInput(self::normalizeNativeInput($data));
        });
    }

    /**
     * Upstream's `StdinBuffer` escape window, the one part of it this terminal needs: a read that is
     * a lone ESC is held for `resolveEscapeTimeoutMs()`, so an Alt+key whose two bytes arrive in two
     * reads — SSH does that — is still one key. A read that follows inside the window takes the ESC
     * back in front of it; none, and the ESC goes on its own as the Escape key. Null while held.
     *
     * @param Closure(string): void $onInput
     */
    private function holdLoneEscape(string $data, Closure $onInput): ?string
    {
        if ($this->escapeTimer !== null) {
            Loop::get()->cancel($this->escapeTimer);
            $this->escapeTimer = null;
            $data = "\x1b" . $data;
        }

        if ($data !== "\x1b") {
            return $data;
        }

        $this->escapeTimer = Loop::get()->delay(self::resolveEscapeTimeoutMs() / 1000, function () use ($onInput): void {
            $this->escapeTimer = null;
            $onInput("\x1b");
        });

        return null;
    }

    /**
     * Pull the support query's answers out of a read: the OSC 7501 echo, if the terminal sent
     * one, and the DA1 sentinel behind it. What is left goes to the components.
     *
     * Upstream splits stdin into sequences first (`StdinBuffer`) and looks at them one at a
     * time; here a reply may arrive cut across two reads, so a tail that could be the start
     * of one is held until the next read — only while the sentinel is owed, and never a lone
     * escape, which is more often a key than the first byte of a reply.
     */
    private function takeProgramStatusReplies(string $data): string
    {
        if (!$this->programStatusQueryPending) {
            return $data;
        }

        $data = $this->programStatusReplyBuffer . $data;
        $this->programStatusReplyBuffer = '';

        $data = (string) preg_replace(self::PROGRAM_STATUS_REPLY, '', $data, 1, $replied);

        if ($replied === 1) {
            $this->programStatusSupported = true;
            $this->writeProgramStatus();
        }

        // The DA answers the query whether or not the terminal spoke first. Later ones are
        // somebody else's sentinel — the colour query's — and pass through.
        $data = (string) preg_replace(self::DEVICE_ATTRIBUTES_REPLY, '', $data, 1, $answered);

        if ($answered === 1) {
            $this->programStatusQueryPending = false;

            return $data;
        }

        if (preg_match(self::PARTIAL_PROGRAM_STATUS_REPLY, $data, $partial) === 1 && strlen($partial[0]) >= 2) {
            $this->programStatusReplyBuffer = $partial[0];
            $data = substr($data, 0, -strlen($partial[0]));
        }

        return $data;
    }

    /**
     * If the terminal (such as macOS Terminal.app) does not report extended keys and sends a bare
     * "\r" when Shift, Command, Control or Option is held, inspect the local macOS modifier state via CoreGraphics.
     * Matches upstream's `normalizeNativeShiftEnterInput` in `ProcessTerminal.ts`.
     */
    private static function normalizeNativeInput(string $data): string
    {
        if ($data !== "\r" || PHP_OS_FAMILY !== 'Darwin') {
            return $data;
        }

        if (getenv('SSH_CONNECTION') !== false || getenv('SSH_CLIENT') !== false || getenv('SSH_TTY') !== false) {
            return $data;
        }

        static $ffi = null;
        static $available = true;

        if ($available && $ffi === null && class_exists('FFI')) {
            try {
                $ffi = \FFI::cdef("
                    typedef uint32_t CGEventSourceStateID;
                    typedef uint64_t CGEventFlags;
                    CGEventFlags CGEventSourceFlagsState(CGEventSourceStateID stateID);
                ", "/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics");
            } catch (\Throwable) {
                $available = false;
            }
        }

        if ($ffi === null) {
            return $data;
        }

        try {
            $flags = $ffi->CGEventSourceFlagsState(1); // kCGEventSourceStateCombinedSessionState

            if (($flags & 0x00020000) !== 0) { // kCGEventFlagMaskShift
                return "\x1b[13;2u"; // Shift+Enter
            }

            if (($flags & 0x00100000) !== 0) { // kCGEventFlagMaskCommand
                return "\x1b[13;9u"; // Command+Enter
            }

            if (($flags & 0x00040000) !== 0) { // kCGEventFlagMaskControl
                return "\x1b[13;5u"; // Ctrl+Enter
            }

            if (($flags & 0x00080000) !== 0) { // kCGEventFlagMaskAlternate (Option)
                return "\x1b[13;3u"; // Alt+Enter
            }
        } catch (\Throwable) {
            // Ignore any runtime glitches and keep raw data
        }

        return $data;
    }

    #[\Override]
    public function stop(): void
    {
        if ($this->clearProgressTimer()) {
            $this->write(self::PROGRESS_CLEAR);
        }

        // Remove the status while stopped, after exit or while suspended. start() reports it again.
        if ($this->programStatusSupported && $this->programStatus !== null) {
            $this->write((new ProgramStatus('clear'))->format());
        }

        $this->programStatusSupported = false;
        $this->programStatusQueryPending = false;
        $this->programStatusReplyBuffer = '';

        $this->write("\x1b[?2004l");
        $this->write("\x1b[<u");

        if ($this->inputWatcher !== null) {
            Loop::get()->cancel($this->inputWatcher);
            $this->inputWatcher = null;
        }

        if ($this->escapeTimer !== null) {
            Loop::get()->cancel($this->escapeTimer);
            $this->escapeTimer = null;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGWINCH, SIG_DFL);
        }

        $this->onResize = null;

        // On the screen before the terminal is handed back: whatever comes next — the shell, an
        // editor, the suspended prompt — writes after it, never into the middle of a frame. And the
        // terminal is handed back even when that fails: a shell left in raw mode — no echo, no
        // newline on Enter, `reset` to get out — is worse than a transcript cut short.
        try {
            $this->flush();
        } finally {
            if ($this->savedState !== null) {
                $this->stty($this->savedState);
                $this->savedState = null;
            }
        }
    }

    /**
     * Upstream's `restoreOnExit()`. Unlike Node, PHP leaves the tty raw when it dies, so the
     * `stty` state saved by start() goes back too.
     */
    #[\Override]
    public function restoreOnExit(string $before, string $after): void
    {
        $sequence = $before;
        if ($this->progressTimer !== null) {
            $sequence .= self::PROGRESS_CLEAR;
        }
        $sequence .= "\x1b[?2004l\x1b[<u" . $after;
        $this->write($sequence);
        $this->flush();

        if ($this->savedState !== null) {
            $this->stty($this->savedState);
            $this->savedState = null;
        }
    }

    /**
     * Hand $data to the terminal, and return without waiting for it to be taken.
     *
     * `fwrite()` to a terminal returns short. It has to: the tty has a buffer of a few kilobytes
     * and a frame is bigger than that. Taking the return value as "done" drops the rest of the
     * frame — usually in the middle of an escape sequence — and from there every cursor move is
     * against a screen that holds something else. So what the terminal does not take now is kept,
     * in order, and written from the loop as it reads (`BufferedTerminal`). This used to wait for
     * it instead, and a resize redrawing a long conversation — megabytes, which a terminal takes
     * seconds to parse — held the loop for all of them: `PIG_STALL_LOG` measured 4083ms.
     *
     * Non-blocking is not optional here either: STDIN and STDOUT are dups of the same open file
     * description when both are the terminal, so putting the input in non-blocking mode does the
     * same to the output whether or not anyone asked.
     */
    #[\Override]
    public function write(string $data): void
    {
        // `PIG_TUI_WRITE_LOG` — upstream's `PI_TUI_WRITE_LOG`: every byte that goes to the terminal,
        // appended, for reading a frame back through a VT emulator when what is on screen and what
        // the renderer meant disagree. Upstream ignores a failed append; here it is the warning
        // `file_put_contents()` gives, since a log that silently is not being written is the one
        // thing a person debugging with it cannot afford.
        if ($this->writeLogPath !== '') {
            file_put_contents($this->writeLogPath, $data, FILE_APPEND);
        }

        // Behind what is already waiting, never past it.
        if ($this->pending !== '') {
            $this->pending .= $data;

            return;
        }

        $written = $this->writeSome($data, 0);

        if ($written < strlen($data)) {
            $this->pending = $data;
            $this->pendingAt = $written;
            $this->drainWatcher = Loop::get()->onWritable($this->output, $this->drain(...));
        }
    }

    #[\Override]
    public function hasPendingOutput(): bool
    {
        return $this->pending !== '';
    }

    #[\Override]
    public function whenDrained(Closure $callback): void
    {
        if ($this->pending === '') {
            Loop::get()->defer($callback);

            return;
        }

        $this->onDrained[] = $callback;
    }

    #[\Override]
    public function flush(): void
    {
        $lastProgressAt = microtime(true);

        while ($this->pending !== '') {
            $previous = $this->pendingAt;
            $this->pendingAt = $this->writeSome($this->pending, $this->pendingAt);

            if ($this->pendingAt >= strlen($this->pending)) {
                $this->drained();

                return;
            }

            if ($this->pendingAt > $previous) {
                $lastProgressAt = microtime(true);
            } elseif (microtime(true) - $lastProgressAt >= self::DRAIN_TIMEOUT) {
                // The buffer is full and has stopped accepting output for DRAIN_TIMEOUT seconds.
                $left = strlen($this->pending) - $this->pendingAt;
                $this->pending = '';
                $this->pendingAt = 0;
                $this->cancelDrain();

                throw new TuiError("Terminal stopped accepting output with {$left} bytes still to write");
            }

            $read = $except = null;
            $write = [$this->output];

            // SIGWINCH lands here too: a window being dragged interrupts this `select()` with
            // EINTR, which PHP reports as a *warning* — and a warning printed into the raw
            // terminal in the middle of a frame is corruption. The interruption is not a
            // failure; the loop goes round and writes the rest.
            set_error_handler(static fn (): bool => true);

            try {
                stream_select($read, $write, $except, 0, 50_000);
            } finally {
                restore_error_handler();
            }
        }
    }

    /** The loop's turn: as much as the terminal takes now. */
    private function drain(): void
    {
        $this->pendingAt = $this->writeSome($this->pending, $this->pendingAt);

        if ($this->pendingAt >= strlen($this->pending)) {
            $this->drained();

            return;
        }

        // Written from the front and appended at the back: the front is let go of now and then,
        // or a terminal that never quite catches up keeps everything ever written.
        if ($this->pendingAt >= self::COMPACT_AFTER) {
            $this->pending = substr($this->pending, $this->pendingAt);
            $this->pendingAt = 0;
        }
    }

    private function drained(): void
    {
        $this->pending = '';
        $this->pendingAt = 0;
        $this->cancelDrain();
        $callbacks = $this->onDrained;
        $this->onDrained = [];

        foreach ($callbacks as $callback) {
            Loop::get()->defer($callback);
        }
    }

    private function cancelDrain(): void
    {
        if ($this->drainWatcher !== null) {
            Loop::get()->cancel($this->drainWatcher);
            $this->drainWatcher = null;
        }
    }

    /**
     * As much of $data from $offset as the terminal takes now, without waiting; the new offset.
     *
     * A slice at a time, not the whole remainder: a tty takes a frame about a kilobyte at a time,
     * and `substr($data, $written)` copies everything still to go on each of those writes — 2,400
     * copies of a 2.5MB frame, 72ms of memcpy, measured. Nothing a terminal does wants more than
     * this per call.
     */
    private function writeSome(string $data, int $offset): int
    {
        $length = strlen($data);

        while ($offset < $length) {
            $count = fwrite($this->output, substr($data, $offset, self::WRITE_SLICE));

            if ($count === false) {
                throw new TuiError("Writing to the terminal failed after {$offset} of {$length} bytes");
            }

            if ($count === 0) {
                break;
            }

            $offset += $count;
        }

        return $offset;
    }

    #[\Override]
    public function columns(): int
    {
        $this->remeasureIfStale();

        return $this->columns;
    }

    #[\Override]
    public function rows(): int
    {
        $this->remeasureIfStale();

        return $this->rows;
    }

    /** Set by SIGWINCH; cleared by the first size read after it. */
    private bool $sizeStale = false;

    private function remeasureIfStale(): void
    {
        if ($this->sizeStale) {
            $this->sizeStale = false;
            $this->measure();
        }
    }

    #[\Override]
    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");
        } elseif ($lines < 0) {
            $up = -$lines;
            $this->write("\x1b[{$up}A");
        }
    }

    #[\Override]
    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    #[\Override]
    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    #[\Override]
    public function clearLine(): void
    {
        $this->write("\x1b[K");
    }

    #[\Override]
    public function clearFromCursor(): void
    {
        $this->write("\x1b[J");
    }

    #[\Override]
    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    #[\Override]
    public function setProgramStatus(ProgramStatus $status): void
    {
        $this->programStatus = $status->state === 'clear' ? null : $status;

        if ($this->programStatusSupported) {
            $this->write($status->format());
        }
    }

    private function writeProgramStatus(): void
    {
        if ($this->programStatusSupported && $this->programStatus !== null) {
            $this->write($this->programStatus->format());
        }
    }

    #[\Override]
    public function setProgress(bool $active): void
    {
        if (!$active) {
            $this->clearProgressTimer();
            $this->write(self::PROGRESS_CLEAR);

            return;
        }

        $this->write(self::PROGRESS_ACTIVE);

        if ($this->progressTimer === null) {
            $keepAlive = function () use (&$keepAlive): void {
                $this->write(self::PROGRESS_ACTIVE);
                $this->progressTimer = Loop::get()->delay(1.0, $keepAlive);
            };
            $this->progressTimer = Loop::get()->delay(1.0, $keepAlive);
        }
    }

    private function clearProgressTimer(): bool
    {
        if ($this->progressTimer === null) {
            return false;
        }

        Loop::get()->cancel($this->progressTimer);
        $this->progressTimer = null;

        return true;
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        $this->write("\x1b]0;{$title}\x07");
    }

    /**
     * Ask the kernel how big the window is now.
     *
     * `stty size` forks, so this runs when the size can actually have changed — at start
     * and on SIGWINCH — and never per frame.
     */
    private function measure(): void
    {
        $size = $this->stty('size');

        if ($size === null || preg_match('/^(\d+)\s+(\d+)$/', trim($size), $match) !== 1) {
            return;
        }

        $measuredRows = (int) $match[1];
        $measuredCols = (int) $match[2];

        if ($measuredRows > 0) {
            $this->rows = $measuredRows;
        }

        if ($measuredCols > 3) {
            $this->columns = $measuredCols;
        }
    }

    private function watchResize(): void
    {
        if (!function_exists('pcntl_signal')) {
            // Without pcntl the size read at start is the size we keep. Say so rather than
            // redrawing at the wrong width for the rest of the session.
            throw new TuiError('ext-pcntl is required: without SIGWINCH the TUI cannot notice a resize');
        }

        pcntl_async_signals(true);

        // The handler does nothing but mark the size stale and ask for a frame. `stty size` is a
        // fork — 4ms here — and a window being dragged delivers a SIGWINCH per pixel: measuring
        // in the handler ran that fork once per signal, serialised, with every frame queued
        // behind it. Node reads the size from an ioctl for nothing; PHP has no ioctl, so the next
        // best thing is to measure once per *frame*, however many signals arrived since.
        pcntl_signal(SIGWINCH, function (): void {
            $this->sizeStale = true;
            ($this->onResize ?? static fn () => null)();
        });
    }

    /**
     * Run `stty` against the terminal and hand back what it printed.
     *
     * PHP has no termios binding in core, and ext-pcntl does not cover it either, so the
     * one external process this package needs is this one. It must talk to the terminal
     * itself, which is why stdin is inherited rather than piped.
     */
    private function stty(string $arguments): ?string
    {
        $descriptors = [0 => ['file', '/dev/tty', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        set_error_handler(static fn (): bool => true);
        try {
            $process = proc_open("stty {$arguments}", $descriptors, $pipes);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            // Fall back to current input descriptor if /dev/tty cannot be opened directly
            $fallbackDescriptors = [0 => $this->input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            set_error_handler(static fn (): bool => true);
            try {
                $process = proc_open("stty {$arguments}", $fallbackDescriptors, $pipes);
            } finally {
                restore_error_handler();
            }

            if (!is_resource($process)) {
                return null;
            }
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status !== 0) {
            return null;
        }

        return $output === false ? null : trim($output);
    }
}
