<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

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
     * Exit code, standard output and standard error.
     *
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string} STOPPED as the code when it timed out
     *         or could not be started
     */
    public static function run(array $command, float $timeout = self::DEFAULT_TIMEOUT): array
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
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

        $exit = proc_close($process);

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

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
        }

        $timedOut = proc_get_status($process)['running'];

        if ($timedOut) {
            proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return !$timedOut && $written === $length && $exit === 0;
    }

    /**
     * Run a command and hand its output to $onLine as the lines arrive.
     *
     * For a program that can produce far more than is wanted — a search across a large
     * repository — where waiting for it to finish means holding all of that in memory
     * for nothing. Returning false from $onLine kills it there and then.
     *
     * @param list<string>          $command
     * @param Closure(string): bool $onLine  false to stop reading and kill the command
     * @return int the exit code, or STOPPED when $onLine asked to stop
     */
    public static function stream(array $command, Closure $onLine, float $timeout = self::DEFAULT_TIMEOUT): int
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
            return self::STOPPED;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $stopped = false;
        $deadline = microtime(true) + $timeout;

        while (true) {
            $chunk = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
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

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return $stopped ? self::STOPPED : $exit;
    }
}
