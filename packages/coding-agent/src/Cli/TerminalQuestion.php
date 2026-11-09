<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * One line from the terminal without stopping the loop — upstream's `questionTerminal()`
 * (`utils/terminal-question.ts`), which `pig auth login` and `pig mcp login` share.
 *
 * Read off the loop rather than with a blocking `fgets()`: a sign-in asks for a pasted code while
 * its loopback server waits for the browser, and a blocked read would leave that server unanswered —
 * the browser's redirect would hang, and the paste would be the only way in. The answer is null when
 * `$signal` aborts (the browser came back first, the sign-in timed out, Ctrl+C) or input ends.
 */
final class TerminalQuestion
{
    /**
     * @param resource $output where the question is written: standard output, or standard error
     *        for a command whose standard output is somebody's pipe
     * @param bool $secret read without echoing it — an API key typed on a shared screen
     */
    public static function ask(string $message, AbortSignal $signal, mixed $output = STDOUT, bool $secret = false, mixed $input = STDIN): ?string
    {
        if ($signal->aborted()) {
            return null;
        }

        fwrite($output, $message);

        $answer = new Deferred();
        $listener = $signal->onAbort(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(null);
            }
        });

        // Not raw mode: with the line discipline left on, Ctrl+C is still SIGINT for the caller's
        // handler and backspace still edits, and `-echo` alone keeps the key off the screen.
        $hidden = $secret && self::isTerminal($input) && self::stty('-echo');
        $buffer = '';
        stream_set_blocking($input, false);
        $watcher = Loop::get()->onReadable($input, static function () use ($input, $answer, &$buffer): void {
            $chunk = fread($input, 8192);

            if ($chunk === false || ($chunk === '' && feof($input))) {
                // End of input: nobody is left to answer.
                if (!$answer->isComplete()) {
                    $answer->complete(null);
                }

                return;
            }

            $buffer .= $chunk;
            $newline = strpos($buffer, "\n");

            if ($newline !== false && !$answer->isComplete()) {
                $answer->complete(rtrim(substr($buffer, 0, $newline), "\r"));
            }
        });

        try {
            $line = $answer->future->await();
        } finally {
            Loop::get()->cancel($watcher);
            $signal->removeListener($listener);
            stream_set_blocking($input, true);

            if ($hidden) {
                self::stty('echo');
                // The Enter that ended it was not echoed either.
                fwrite($output, "\n");
            }
        }

        return is_string($line) ? $line : null;
    }

    /** @param resource $stream */
    public static function isTerminal(mixed $stream): bool
    {
        return function_exists('posix_isatty') && posix_isatty($stream);
    }

    private static function stty(string $mode): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        exec('stty ' . $mode . ' 2>/dev/null', result_code: $code);

        return $code === 0;
    }
}
