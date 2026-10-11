<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Utils\Utf8;

/**
 * The first prompt, put together from what arrived three ways: piped standard input, `@file`
 * arguments, and the first message.
 *
 * Upstream's `cli/initial-message.ts` (`buildInitialMessage()`) and `main.ts`'s
 * `readPipedStdin()`: stdin first, then the files' text, then the first message, joined with
 * nothing — `git diff | pig -p "review this"` asks about the diff with the diff above the
 * question. With none of the three there is no first prompt, and the rest of the messages follow
 * it as they were given.
 */
final class InitialMessage
{
    /**
     * Upstream's `readPipedStdin()`: all of standard input when it is not a terminal, trimmed;
     * null for a terminal, or for nothing but whitespace.
     *
     * @param resource $stdin
     */
    public static function readPipedStdin($stdin = STDIN): ?string
    {
        if (stream_isatty($stdin)) {
            return null;
        }

        $data = trim((string) stream_get_contents($stdin));

        // Bytes, as an argument is: a pipe hands over whatever the program before it wrote.
        return $data === '' ? null : Utf8::sanitize($data);
    }

    /**
     * Upstream's `buildInitialMessage()`, with the rest of the messages after it.
     *
     * @param list<string> $messages what was said on the command line, in order
     * @return list<string> the prompts to send, the composed first one first
     */
    public static function build(?string $stdin, string $fileText, array $messages): array
    {
        $parts = [];

        if ($stdin !== null) {
            $parts[] = $stdin;
        }

        if ($fileText !== '') {
            $parts[] = $fileText;
        }

        if ($messages !== []) {
            $parts[] = array_shift($messages);
        }

        $initial = implode('', $parts);

        // Upstream's `if (initialMessage)`: an empty one is not sent.
        return $initial === '' ? $messages : [$initial, ...$messages];
    }
}
