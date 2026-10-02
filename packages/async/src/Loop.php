<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;
use Throwable;

/**
 * Single-threaded event loop: stream readiness, timers, deferred callbacks.
 *
 * Upstream pi has no counterpart to this file — JS ships an event loop, PHP does not.
 * Everything async in pig (LLM streaming, keyboard input, tool subprocesses) is a
 * watcher on this loop, so a single stream_select() can wait on the LLM socket and
 * STDIN at the same time. That is what makes Esc-to-interrupt and typing while the
 * model streams possible.
 *
 * Singleton on purpose: a CLI agent has exactly one loop, and threading a $loop
 * argument through every call site buys nothing.
 */
final class Loop
{
    private static ?self $instance = null;

    /** @var array<string, array{stream: resource, callback: Closure}> */
    private array $readers = [];

    /** @var array<string, array{stream: resource, callback: Closure}> */
    private array $writers = [];

    /** @var array<string, array{at: float, callback: Closure}> */
    private array $timers = [];

    /** @var list<Closure> */
    private array $queue = [];

    private int $nextId = 0;

    private bool $stopped = false;

    /** @var Closure(Throwable): void|null */
    private ?Closure $onError = null;

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drop the loop and every watcher on it.
     *
     * Test seam only — a process has one loop for its whole life.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Invoke $callback whenever $stream has bytes to read.
     *
     * The watcher stays armed until cancel(); a callback that reads to EOF is
     * responsible for cancelling itself.
     *
     * @param resource $stream
     * @return string watcher id for cancel()
     */
    public function onReadable($stream, Closure $callback): string
    {
        $id = 'r' . $this->nextId++;
        $this->readers[$id] = ['stream' => $stream, 'callback' => $callback];

        return $id;
    }

    /**
     * Invoke $callback whenever $stream can accept bytes.
     *
     * Needed because fwrite() on a non-blocking socket short-writes: it returns the
     * count it managed, and the rest waits for the kernel buffer to drain.
     *
     * @param resource $stream
     * @return string watcher id for cancel()
     */
    public function onWritable($stream, Closure $callback): string
    {
        $id = 'w' . $this->nextId++;
        $this->writers[$id] = ['stream' => $stream, 'callback' => $callback];

        return $id;
    }

    /** Disarm a reader, a writer or a timer. Unknown ids are a no-op. */
    public function cancel(string $id): void
    {
        unset($this->readers[$id], $this->writers[$id], $this->timers[$id]);
    }

    /**
     * Run $callback on the next tick, before any I/O is polled.
     *
     * This is the only way a completed Future may resume a suspended Fiber:
     * resuming one that has not suspended yet raises FiberError.
     */
    public function defer(Closure $callback): void
    {
        $this->queue[] = $callback;
    }

    /** @return string watcher id for cancel() */
    public function delay(float $seconds, Closure $callback): string
    {
        $id = 't' . $this->nextId++;
        $this->timers[$id] = ['at' => microtime(true) + $seconds, 'callback' => $callback];

        return $id;
    }

    /** True when nothing is left to run — no queue, no watchers, no timers. */
    public function isIdle(): bool
    {
        return $this->queue === []
            && $this->readers === []
            && $this->writers === []
            && $this->timers === [];
    }

    /** One iteration: deferred callbacks, then I/O, then expired timers. */
    public function tick(): void
    {
        $this->runQueue();
        $this->poll($this->pollTimeout());
        $this->runTimers();
    }

    /** Tick until stop() is called or there is no work left. */
    public function run(): void
    {
        $this->stopped = false;

        while (!$this->stopped && !$this->isIdle()) {
            $this->tick();
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Where a throw out of a callback goes, instead of out of `run()`.
     *
     * workerman's `setErrorHandler()`, and the reason pig wants it is written five times over in
     * the traps: "runs inside the loop's own input callback, so there is nothing above it to
     * catch" is why one byte that is not UTF-8 in a tool's output, a `/settings` hint one column
     * too wide, and a cursor left inside a character each ended **the whole session** rather than
     * one frame. Every one of those was fixed at its source; the class stayed open, because a
     * render is the one thing here that runs on every tick and is written by whoever added a
     * component.
     *
     * **With no handler set a throw still escapes**, which is the behaviour every test in this
     * suite was written against and the honest default: a library that swallows by itself is the
     * silent fallback this project forbids. An application that sets one is saying it has
     * somewhere to report to — `InteractiveMode` has a transcript — and that a drawn error beats
     * a stack trace over a half-drawn screen.
     *
     * Upstream has no counterpart: there is no `uncaughtException` handler anywhere in the four
     * ported packages, so a throw inside a render ends the process there too. This is pig's own
     * answer, taken from workerman rather than from pi.
     *
     * @param Closure(Throwable): void|null $handler null puts the rethrow back
     */
    public function setErrorHandler(?Closure $handler): void
    {
        $this->onError = $handler;
    }

    /**
     * Keep the next poll from blocking.
     *
     * A tick runs deferred callbacks and only then polls, so anything that finishes
     * during that first phase — a coroutine returning, for one — must say so, or the
     * poll settles in to wait on watchers whose work is already over. Single-threaded,
     * there is no way to interrupt a select() already under way; this makes sure the
     * next one returns at once instead.
     */
    public function wake(): void
    {
        $this->queue[] = static fn () => null;
    }

    /**
     * Run one callback, and hand a throw to the error handler rather than out of the loop.
     *
     * Every callback the loop invokes goes through here — deferred, readable, writable, timer —
     * because a throw from any of them is the same accident with the same consequence, and a
     * wrapper on three of the four is the shape this document keeps calling *a rule present in
     * one place and absent in its sibling*.
     */
    private function safely(Closure $callback, mixed ...$arguments): void
    {
        if ($this->onError === null) {
            $callback(...$arguments);

            return;
        }

        try {
            $callback(...$arguments);
        } catch (Throwable $error) {
            // Not caught: a handler that throws is the application's own bug, and there is
            // nowhere better for it to go than where an unhandled throw already went.
            ($this->onError)($error);
        }
    }

    private function runQueue(): void
    {
        // Snapshot: callbacks queued by these callbacks belong to the next tick,
        // otherwise a self-deferring callback starves I/O forever.
        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $at => $callback) {
            try {
                $this->safely($callback);
            } catch (Throwable $error) {
                // A throw out of one callback — the root coroutine failing, with no error
                // handler installed — must not take the rest of the snapshot with it. What was
                // queued behind it goes back to the front, so a message another callback had
                // deferred for delivery still arrives on the next tick. A microtask that throws
                // in JavaScript does not cancel the ones after it either.
                $this->queue = [...array_slice($queue, $at + 1), ...$this->queue];

                throw $error;
            }
        }
    }

    /** Seconds to wait for I/O; null means block until a stream is ready. */
    private function pollTimeout(): ?float
    {
        if ($this->queue !== []) {
            return 0.0;
        }

        $next = null;
        foreach ($this->timers as $timer) {
            if ($next === null || $timer['at'] < $next) {
                $next = $timer['at'];
            }
        }

        if ($next === null) {
            return null;
        }

        // Floor at zero: an overdue timer yields a negative delay, and stream_select()
        // rejects a negative timeout. Zero means "poll once and move on", which is what
        // an overdue timer wants anyway.
        return max(0.0, $next - microtime(true));
    }

    private function poll(?float $timeout): void
    {
        if ($this->readers === [] && $this->writers === []) {
            // Timers but no streams. stream_select() cannot wait on nothing — PHP 8 raises
            // ValueError("No stream arrays were passed") — so the wait is a sleep. Nothing
            // else can make progress here: with no watchers armed, no callback can fire.
            if ($timeout !== null && $timeout > 0.0) {
                usleep((int) ($timeout * 1_000_000));
            }

            return;
        }

        // A closed stream is dropped from the array by stream_select(), which then
        // reports "No stream arrays were passed" — pointing at the wrong thing entirely.
        // Name the watcher instead: closing a stream without cancelling its watcher is
        // the actual mistake, and it is an easy one to make when a socket hits EOF.
        $read = [];
        foreach ($this->readers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                throw new AsyncError("Reader {$id} watches a closed stream; cancel the watcher before closing it");
            }

            $read[$id] = $watcher['stream'];
        }

        $write = [];
        foreach ($this->writers as $id => $watcher) {
            if (!is_resource($watcher['stream'])) {
                throw new AsyncError("Writer {$id} watches a closed stream; cancel the watcher before closing it");
            }

            $write[$id] = $watcher['stream'];
        }

        $except = null;
        $seconds = $timeout === null ? null : (int) $timeout;
        $microseconds = $timeout === null ? 0 : (int) round(($timeout - (int) $timeout) * 1_000_000);

        // stream_select() preserves array keys, so the watcher id survives the call
        // and maps straight back to its callback.
        // stream_select() raises a warning as well as returning false. Capture it with a
        // handler rather than @-suppressing: the message carries the errno this method
        // has to branch on, and silencing it would throw that away.
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $ready = stream_select($read, $write, $except, $seconds, $microseconds);
        } finally {
            restore_error_handler();
        }

        if ($ready === false) {
            $message = $warning !== '' ? $warning : 'unknown error';

            // EINTR (errno 4): a signal arrived while select() was blocked — SIGWINCH on
            // every terminal resize, once the TUI installs its handler. The handler has
            // already run; this is not a failure, and the next tick re-arms the watchers.
            // Matched on the errno rather than the text, which is locale-dependent.
            if (str_contains($message, 'Unable to select [4]')) {
                return;
            }

            throw new AsyncError("stream_select() failed: {$message}");
        }

        if ($ready === 0) {
            return;
        }

        foreach (array_keys($read) as $id) {
            // A callback may have cancelled a later watcher in this same batch.
            if (isset($this->readers[$id])) {
                $this->safely($this->readers[$id]['callback'], $this->readers[$id]['stream']);
            }
        }

        foreach (array_keys($write) as $id) {
            if (isset($this->writers[$id])) {
                $this->safely($this->writers[$id]['callback'], $this->writers[$id]['stream']);
            }
        }
    }

    private function runTimers(): void
    {
        $now = microtime(true);

        foreach ($this->timers as $id => $timer) {
            if ($timer['at'] > $now) {
                continue;
            }

            unset($this->timers[$id]);
            $this->safely($timer['callback']);
        }
    }
}
