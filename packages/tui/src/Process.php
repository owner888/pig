<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;
use Fiber;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * Run a command and take what it printed.
 *
 * For the small external tools a terminal program has to ask things of — what is on the
 * clipboard, what files are in this tree. The output may be binary, so it is handled as
 * bytes throughout; and the command may hang, so there is a deadline, because a paste
 * that never returns freezes the whole UI.
 */
final class Process
{
    /** How long to wait for a command by default. Long enough for a slow clipboard. */
    private const float DEFAULT_TIMEOUT = 2.0;

    /** How long to sleep between reads while waiting. */
    private const int POLL_MICROSECONDS = 5_000;

    /** Exit code for a command this killed rather than let finish. */
    public const int STOPPED = -1;

    /** How much to take off a pipe at a time, for the loop-driven reads. */
    private const int READ_CHUNK = 65536;

    /**
     * Wait for a process to be over, close it, and report what `$?` would have said.
     *
     * Two things about `proc_get_status()` decide the shape of this, and both were measured
     * rather than read — identically on 8.3 and 8.4:
     *
     * **A command finishes twice: its pipes close, and then it exits.** Waiting for the
     * first and then asking for the status is answered `running: true` about one time in
     * eight on a loaded machine, and a command that closes its own standard output first is
     * in that window for the whole of its remaining life. In that window `signaled` is false,
     * so a command something killed reads as one that chose its own exit code.
     *
     * **And the status is readable exactly once.** The first call that reports the process
     * gone carries `signaled: true, termsig: 15`; every later call says `signaled: false,
     * termsig: 0` and `exitcode: -1`, and `proc_close()` says -1 too, because that first
     * call is the one that reaped the child. So there is no asking again later: the answer
     * is kept from the read that carries it, and `proc_close()`'s own return is not used.
     *
     * Polled, because PHP offers no waiting that leaves the status readable afterwards —
     * `proc_close()` is the blocking wait and it is also the end of the resource. **On the
     * loop, so the wait is not a freeze**: by the time this is reached the pipes are shut,
     * so nothing else would wake the loop, and `proc_close()` would hold the one thread for
     * as long as the command had left. **With no fiber to suspend it sleeps**, for
     * `runAsync()`'s reason — blocking is correct where there is no loop being kept alive.
     *
     * There is no deadline here on purpose. Whoever is waiting has one — `Run::wait()`'s
     * timer and `runAsync()`'s are both still armed across this — and a second one
     * underneath would cut a command short on a rule nobody asked for.
     *
     * @param resource|null $process
     */
    public static function awaitFinish(mixed $process): int
    {
        if (!is_resource($process)) {
            return 0;
        }

        while (true) {
            $status = proc_get_status($process);

            if ($status['running'] !== true) {
                break;
            }

            if (Fiber::getCurrent() === null) {
                usleep(self::POLL_MICROSECONDS);

                continue;
            }

            Async::delay(self::POLL_MICROSECONDS / 1_000_000);
        }

        $exit = self::codeOf($status);
        proc_close($process);

        return $exit;
    }

    /**
     * What `$?` would have said, read off the one status that carries it.
     *
     * A command something killed is reported by PHP as the **signal number** — 9 for a
     * SIGKILL — where every shell reports 128 + the signal. 137 is the number a model has
     * seen a thousand times and reads as "something killed this, probably for memory"; told
     * "code 9" it goes looking for an exit code the program chose. Upstream has the opposite
     * bug and it is worse: Node reports `code: null` there, which its
     * `code !== 0 && code !== null` guard treats as **success**, so a segfaulting build comes
     * back as a command that worked.
     *
     * One implementation, because the answer has to be the same whichever door a command came
     * in by. This translation used to be in `runAsync()` and in `Tools\Run::close()` and
     * nowhere else, so `run()` — which is what `runAsync()` becomes with no fiber — reported 9
     * for the very command `runAsync()` reported 137 for.
     *
     * @param array<string, mixed> $status the first `proc_get_status()` that reported it gone
     */
    private static function codeOf(array $status): int
    {
        if (($status['signaled'] ?? false) === true && ($status['termsig'] ?? 0) > 0) {
            return 128 + (int) $status['termsig'];
        }

        return (int) ($status['exitcode'] ?? -1);
    }

    /**
     * Standard output, or null when the command failed, was not found, or timed out.
     *
     * Null rather than an exception: every caller here is asking whether a capability is
     * present, and "this machine has no `wl-paste`" is an answer, not an error.
     *
     * A caller that needs to tell "it ran and said nothing" from "it did not run" wants
     * `run()` instead — conflating the two turns a broken command into an empty result.
     *
     * @param list<string> $command the program and its arguments, unquoted
     */
    public static function capture(array $command, float $timeout = self::DEFAULT_TIMEOUT): ?string
    {
        [$exit, $output] = self::run($command, $timeout);

        return $exit === 0 ? $output : null;
    }

    /**
     * The same as `run()`, but it suspends the fiber instead of stopping the loop.
     *
     * `run()` polls with `usleep()`, which is right for the two-second probes it was written
     * for and wrong for anything a person waits through: while it sleeps there are no
     * keystrokes, no spinner and no escape, because the one thread is inside the sleep. A
     * hook that runs a linter is thirty seconds of a terminal that will not answer.
     *
     * So the pipes go on the loop — the same `select()` that waits on the model's socket —
     * and this parks on a `Deferred` until they close. Everything else is `run()`'s: the same
     * return shape, the same STOPPED for a timeout, the same single `proc_terminate()` that
     * kills the command and not its grandchildren (`Tools\Shell::killTree()` is the answer to
     * that and lives above this package).
     *
     * **With no fiber to suspend, this is `run()`.** Not a fallback papering over a failure:
     * blocking is the correct thing to do when there is no loop being kept alive — a hook
     * factory runs at startup, long before `Async::run()`, and a command there has nothing to
     * be polite to.
     *
     * **A signal kills it**, which is the other half of not freezing: the screen staying alive
     * is no use if escape then has nothing to stop. `STOPPED` covers both ends of that, since
     * "this was not allowed to finish" is what the caller acts on either way.
     *
     * @param list<string>     $command
     * @param string|null      $cwd    where to run it; null means wherever pig was started
     * @param AbortSignal|null $signal kills the command and returns STOPPED
     * @return array{0: int, 1: string, 2: string} STOPPED as the code when it timed out, was
     *         aborted, or could not be started; 128 + the signal when something killed it
     */
    public static function runAsync(
        array $command,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?string $cwd = null,
        ?AbortSignal $signal = null,
    ): array {
        if ($command === []) {
            throw new TuiError('Process::runAsync() needs a command');
        }

        if (Fiber::getCurrent() === null) {
            return self::run($command, $timeout, $cwd);
        }

        if ($signal?->aborted() ?? false) {
            return [self::STOPPED, '', ''];
        }

        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return [self::STOPPED, '', ''];
        }

        $collected = ['', ''];
        $open = 2;
        $watchers = [];
        $finished = new Deferred();

        $close = static function (int $fd) use (&$watchers, &$pipes, &$open, $finished): void {
            // Cancelled before the close, or `stream_select()` drops a closed stream and then
            // fails with an error that names nothing.
            if (isset($watchers[$fd])) {
                Loop::get()->cancel($watchers[$fd]);
                unset($watchers[$fd]);
            }

            if (is_resource($pipes[$fd] ?? null)) {
                fclose($pipes[$fd]);
            }

            unset($pipes[$fd]);
            $open--;

            if ($open <= 0 && !$finished->future->isComplete()) {
                $finished->complete(null);
            }
        };

        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
            $watchers[$fd] = Loop::get()->onReadable(
                $pipes[$fd],
                static function () use ($fd, &$collected, &$pipes, $close): void {
                    $pipe = $pipes[$fd] ?? null;

                    if (!is_resource($pipe)) {
                        return;
                    }

                    $chunk = fread($pipe, self::READ_CHUNK);

                    if ($chunk === false || $chunk === '') {
                        if (feof($pipe)) {
                            $close($fd);
                        }

                        return;
                    }

                    $collected[$fd - 1] .= $chunk;
                },
            );
        }

        $stopped = false;
        $kill = static function () use (&$stopped, $process, $finished): void {
            $stopped = true;

            if (is_resource($process)) {
                proc_terminate($process, 9);
            }

            // Stop waiting rather than waiting for the pipes to close, which is not the same
            // thing: `proc_terminate()` kills the command and not its children, so
            // `sh -c 'echo; sleep 5'` leaves `sleep` holding the pipe open and EOF never
            // comes. A command that was not allowed to finish has finished as far as the
            // caller is concerned, and what the watchers have already read is what there is.
            if (!$finished->future->isComplete()) {
                $finished->complete(null);
            }
        };

        $listener = $signal?->onAbort($kill);
        $timer = $timeout > 0
            ? Loop::get()->delay($timeout, $kill)
            : null;

        $exit = 0;

        try {
            $finished->future->await();
            // The pipes closing is not the command exiting, and the code is a fact about the
            // process — see `awaitFinish()`. Inside the try, so the timeout and the abort above
            // still reach a command that shut its own output and carried on.
            $exit = self::awaitFinish($process);
        } finally {
            if ($timer !== null) {
                // A pending timer keeps the loop from ever going idle, so `bin/pig` would
                // not exit.
                Loop::get()->cancel($timer);
            }

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            foreach (array_keys($watchers) as $fd) {
                $close($fd);
            }
        }

        return [$stopped ? self::STOPPED : $exit, $collected[0], $collected[1]];
    }

    /**
     * Exit code, standard output and standard error.
     *
     * Blocking: it polls with `usleep()`, so nothing else in this process runs while it
     * waits. Right for a two-second probe, wrong for anything a person waits through — see
     * `runAsync()`, which is the same thing on the loop.
     *
     * @param list<string> $command
     * @param string|null  $cwd     where to run it; null means wherever pig was started
     * @return array{0: int, 1: string, 2: string} STOPPED as the code when it timed out or
     *         could not be started; 128 + the signal when something killed it
     */
    public static function run(array $command, float $timeout = self::DEFAULT_TIMEOUT, ?string $cwd = null): array
    {
        if ($command === []) {
            throw new TuiError('Process::run() needs a command');
        }

        // The array form: PHP builds the argv itself, so an argument with a space in it
        // stays one argument and nothing has to be quoted.
        //
        // A missing program makes proc_open warn and return false. The warning is caught
        // rather than suppressed, because suppression would hide the other reasons it can
        // fail; the value is what decides, and here failure means "not on this machine".
        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return [self::STOPPED, '', ''];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $errors = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            // Read stderr too, or a command that writes a lot to it fills the pipe and
            // blocks forever with nothing on stdout to show for it.
            $errors .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                // 9 rather than SIGKILL: the constant comes from ext-pcntl, and a command runner
                // should not need an extension to give up on a command.
                proc_terminate($process, 9);

                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        $errors .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        // The status the loop already read is the answer: it is only carried by the one call
        // that found the process gone, and `proc_close()` after it reports -1 for a command
        // something killed. See `awaitFinish()`.
        $exit = self::codeOf($status);
        proc_close($process);

        return [$timedOut ? self::STOPPED : $exit, $output, $errors];
    }

    /**
     * Run a command, give it $input on standard input, and wait for it to finish.
     *
     * For the programs that take their argument that way rather than on the command line
     * — `pbcopy`, `wl-copy`, `xclip` — where passing the text as an argument would put it
     * in the process list for anyone to read, if the length limit allowed it at all.
     *
     * The write is looped and the pipe is closed before waiting: a large clipboard fills
     * the pipe buffer, and a program that has not been told the input ended will not exit.
     *
     * @param list<string> $command
     * @return bool whether it ran and exited cleanly
     */
    public static function feed(array $command, string $input, float $timeout = self::DEFAULT_TIMEOUT): bool
    {
        if ($command === []) {
            throw new TuiError('Process::feed() needs a command');
        }

        // Same reason as run(): a missing program makes proc_open warn, and the value is
        // what decides rather than the warning.
        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return false;
        }

        $written = 0;
        $length = strlen($input);
        $deadline = microtime(true) + $timeout;

        while ($written < $length && microtime(true) < $deadline) {
            $count = fwrite($pipes[0], substr($input, $written));

            if ($count === false) {
                break;
            }

            $written += $count;

            if ($count === 0) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        // Closed before the wait, because that is what says the input has ended.
        fclose($pipes[0]);

        // The status is kept rather than asked for twice: only the call that finds the process
        // gone carries the real answer — see `awaitFinish()`.
        while (true) {
            $status = proc_get_status($process);

            if (!$status['running'] || microtime(true) >= $deadline) {
                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $timedOut = (bool) $status['running'];

        if ($timedOut) {
            proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = self::codeOf($status);
        proc_close($process);

        return !$timedOut && $written === $length && $exit === 0;
    }

    /**
     * How often to look at a program that has the terminal, when asked to wait politely.
     *
     * Fifty milliseconds is imperceptible to whoever is typing in it and cheap enough that
     * the polling does not show up anywhere.
     */
    private const float TTY_POLL = 0.05;

    /**
     * Hand the terminal to a program and wait for it to give it back.
     *
     * For a full-screen program the person is going to use — `vim`, `nano`, `code --wait`.
     * All three streams go to `/dev/tty` rather than to pipes, because the whole point is
     * that it talks to the terminal itself; nothing here reads its output.
     *
     * **The caller must stop the TUI first**, or two programs draw over each other and raw
     * mode is left on while the editor is trying to set its own.
     *
     * **There is no timeout, and that is deliberate** — the one method here without one.
     * Every other has one because a command that hangs must not hang pig; this one is a
     * person editing, and a timeout would kill their editor mid-sentence. (Upstream has no
     * timeout either: `spawnSync` takes one and `openExternalEditor()` does not pass it.)
     *
     * $yield is how a caller that has an event loop keeps it turning while the person
     * types: without it this blocks until the program exits, and a model streaming into an
     * unread socket eventually gives up. It is a closure rather than a call into
     * `Pig\Async` because that would point this package at one it does not depend on — and
     * because how to yield is the caller's business, not this method's.
     *
     * @param list<string>         $command the program and its arguments, unquoted
     * @param Closure(): void|null $yield   called repeatedly while the program runs
     * @return int the exit code, or STOPPED when it could not be started
     */
    public static function interactive(array $command, ?Closure $yield = null): int
    {
        if ($command === []) {
            throw new TuiError('Process::interactive() needs a command');
        }

        $descriptors = [
            0 => ['file', '/dev/tty', 'r'],
            1 => ['file', '/dev/tty', 'w'],
            2 => ['file', '/dev/tty', 'w'],
        ];

        // Caught rather than suppressed, for the same reason as `run()`: a missing program
        // makes proc_open warn, and the value is what decides.
        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open($command, $descriptors, $pipes);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return self::STOPPED;
        }

        // Polled rather than waited on, when there is somewhere to yield to. `proc_close()`
        // waits, so reaching it once the program has already exited costs nothing.
        while ($yield !== null && proc_get_status($process)['running']) {
            $yield();
        }

        // The one exit code here that is not translated, and not an oversight: the caller asks
        // whether the person saved, so anything but 0 says the same thing. With a $yield the
        // loop above has already taken the status that carries the answer; without one there is
        // nothing to wait with, and `proc_close()` is the wait.
        return proc_close($process);
    }

    /** How long to wait between looks at a program that has the terminal. */
    public static function ttyPollSeconds(): float
    {
        return self::TTY_POLL;
    }

    /**
     * Run a command and hand its output to $onLine as the lines arrive.
     *
     * For a program that can produce far more than is wanted — a search across a large
     * repository — where waiting for it to finish means holding all of that in memory
     * for nothing. Returning false from $onLine kills it there and then.
     *
     * **Standard error comes back with the exit code**, as it does from `run()`, and for the
     * reason written on the `fd` trap: a command that failed has said why, and a caller left
     * with only a number reports "exited with code 2" where the program said `regex parse
     * error: …` and pointed at the character. It used to be read and dropped on the floor here
     * — read so the pipe could not fill and block, which is right, and dropped because nothing
     * asked for it, which is how `grep` came to tell the model a number.
     *
     * @param list<string>          $command
     * @param Closure(string): bool $onLine  false to stop reading and kill the command
     * @return array{0: int, 1: string} the exit code — STOPPED when $onLine asked to stop, and
     *         128 + the signal when something killed it — and whatever it wrote to standard error
     */
    public static function stream(array $command, Closure $onLine, float $timeout = self::DEFAULT_TIMEOUT): array
    {
        if ($command === []) {
            throw new TuiError('Process::stream() needs a command');
        }

        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return [self::STOPPED, ''];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $errors = '';
        $stopped = false;
        $deadline = microtime(true) + $timeout;
        // The one status read that carries the answer, kept because every later one says
        // `signaled: false` — see `awaitFinish()`. This loop goes round again after the process
        // has gone when there is still output to take off the pipe, so it cannot be the last.
        $final = null;

        while (true) {
            $chunk = stream_get_contents($pipes[1]);
            // Kept, not just drained: a command that fails has said why on this pipe.
            $errors .= (string) stream_get_contents($pipes[2]);
            $buffer .= (string) $chunk;

            // Only whole lines are delivered; the tail of a half-read line waits for the
            // rest, because a caller parsing JSON per line cannot do anything with half.
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                if (!$onLine($line)) {
                    $stopped = true;

                    break 2;
                }
            }

            $status = proc_get_status($process);

            if ($final === null && $status['running'] !== true) {
                $final = $status;
            }

            if (!$status['running'] && ($chunk === false || $chunk === '')) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $stopped = true;

                break;
            }

            if ($status['running']) {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        if ($stopped) {
            proc_terminate($process, 9);
        } elseif ($buffer !== '') {
            $onLine($buffer);
        }

        $errors .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        // Null only when this killed the command, which is reported as STOPPED either way.
        $exit = $final === null ? self::STOPPED : self::codeOf($final);
        proc_close($process);

        return [$stopped ? self::STOPPED : $exit, $errors];
    }
}
