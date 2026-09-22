<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;

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

    /** @var array<string, array{at: float, callback: Closure}> */
    private array $timers = [];

    /** @var list<Closure> */
    private array $queue = [];

    private int $nextId = 0;

    private bool $stopped = false;

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

    /** Disarm a reader or a timer. Unknown ids are a no-op. */
    public function cancel(string $id): void
    {
        unset($this->readers[$id], $this->timers[$id]);
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

    private function runQueue(): void
    {
        // Snapshot: callbacks queued by these callbacks belong to the next tick,
        // otherwise a self-deferring callback starves I/O forever.
        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $callback) {
            $callback();
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
        if ($this->readers === []) {
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

        $write = null;
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
                ($this->readers[$id]['callback'])($this->readers[$id]['stream']);
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
            ($timer['callback'])();
        }
    }
}
