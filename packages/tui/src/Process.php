<?php

declare(strict_types=1);

namespace Pig\Tui;

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

    /**
     * Standard output, or null when the command failed, was not found, or timed out.
     *
     * Null rather than an exception: every caller here is asking whether a capability is
     * present, and "this machine has no `wl-paste`" is an answer, not an error.
     *
     * @param list<string> $command the program and its arguments, unquoted
     */
    public static function capture(array $command, float $timeout = self::DEFAULT_TIMEOUT): ?string
    {
        if ($command === []) {
            throw new TuiError('Process::capture() needs a command');
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
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            // Read stderr too, or a command that writes a lot to it fills the pipe and
            // blocks forever with nothing on stdout to show for it.
            stream_get_contents($pipes[2]);

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
        stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return $timedOut || $exit !== 0 ? null : $output;
    }
}
