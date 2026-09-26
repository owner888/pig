<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Keeping tool output down to something worth sending.
 *
 * Two limits, and whichever is reached first wins: a line count, because a model reading
 * eight thousand lines of a log has stopped doing the task, and a byte count, because one
 * minified file is a single line and a megabyte of it.
 *
 * Lines are never cut in half — a half-line of JSON is worse than no line, since the model
 * cannot tell which it got. The one exception is the tail case below.
 */
final class Truncate
{
    public const int MAX_LINES = 2000;
    public const int MAX_BYTES = 50 * 1024;

    /** Widest a single grep match is shown before it is cut. */
    public const int MAX_MATCH_CHARS = 500;

    /**
     * Keep the beginning.
     *
     * For reading a file, where the top is what tells you what it is.
     */
    public static function head(string $content, int $maxLines = self::MAX_LINES, int $maxBytes = self::MAX_BYTES): Truncation
    {
        $lines = explode("\n", $content);
        $totalLines = count($lines);
        $totalBytes = strlen($content);

        if ($totalLines <= $maxLines && $totalBytes <= $maxBytes) {
            return new Truncation(
                $content,
                false,
                null,
                $totalLines,
                $totalBytes,
                $totalLines,
                $totalBytes,
                $maxLines,
                $maxBytes,
            );
        }

        // Nothing whole fits. Saying so is more use than half a line, because the model
        // can act on it — the read tool turns this into "use bash and head -c".
        if (strlen($lines[0]) > $maxBytes) {
            return new Truncation(
                '',
                true,
                'bytes',
                $totalLines,
                $totalBytes,
                0,
                0,
                $maxLines,
                $maxBytes,
                firstLineExceedsLimit: true,
            );
        }

        $kept = [];
        $bytes = 0;
        $by = 'lines';

        foreach ($lines as $index => $line) {
            if ($index >= $maxLines) {
                break;
            }

            // The newline that would join this line to the last one counts too.
            $cost = strlen($line) + ($index > 0 ? 1 : 0);

            if ($bytes + $cost > $maxBytes) {
                $by = 'bytes';

                break;
            }

            $kept[] = $line;
            $bytes += $cost;
        }

        return self::result($kept, $by, $totalLines, $totalBytes, $maxLines, $maxBytes);
    }

    /**
     * Keep the end.
     *
     * For command output, where the interesting part is the error at the bottom and the
     * first nine hundred lines are a build log.
     */
    public static function tail(string $content, int $maxLines = self::MAX_LINES, int $maxBytes = self::MAX_BYTES): Truncation
    {
        $lines = explode("\n", $content);
        $totalLines = count($lines);
        $totalBytes = strlen($content);

        if ($totalLines <= $maxLines && $totalBytes <= $maxBytes) {
            return new Truncation(
                $content,
                false,
                null,
                $totalLines,
                $totalBytes,
                $totalLines,
                $totalBytes,
                $maxLines,
                $maxBytes,
            );
        }

        $kept = [];
        $bytes = 0;
        $by = 'lines';
        $partial = false;

        for ($index = $totalLines - 1; $index >= 0 && count($kept) < $maxLines; $index--) {
            $line = $lines[$index];
            $cost = strlen($line) + ($kept === [] ? 0 : 1);

            if ($bytes + $cost > $maxBytes) {
                $by = 'bytes';

                // The only place a line is cut in half: the last line of the output is
                // itself over the limit, and its end is the part that matters.
                if ($kept === []) {
                    $piece = self::lastBytes($line, $maxBytes);
                    $kept[] = $piece;
                    $bytes = strlen($piece);
                    $partial = true;
                }

                break;
            }

            array_unshift($kept, $line);
            $bytes += $cost;
        }

        return self::result($kept, $by, $totalLines, $totalBytes, $maxLines, $maxBytes, $partial);
    }

    /**
     * One line, cut to $maxChars with a note saying so.
     *
     * Used on grep matches: a match inside a minified bundle is one useful fragment
     * followed by fifty thousand characters of noise.
     *
     * @return array{0: string, 1: bool} the line, and whether anything was dropped
     */
    /**
     * One over-long match line, cut with a marker.
     *
     * **Counted in characters, where upstream counts UTF-16 code units** — `line.length` and
     * `line.slice()`. That is JavaScript's unit rather than a decision: the same line would be
     * cut somewhere else in any other language, and a family emoji is eleven units there and
     * seven characters here. Characters are the faithful reading of what the number is for, and
     * they are the only unit that is the same on both sides of a `mb_substr`; bytes would be
     * closer to "how much output is this" and would cut a character in half, which is worse for
     * text going to a model. So the two differ on where a 500-character line with non-ASCII in
     * it gets cut, and on nothing else.
     *
     * `head()`, `tail()` and `size()` were run against upstream's over 777 documents with varied
     * limits and agree field for field; this is the one function in the file that does not, and
     * it is the reason the run was worth doing rather than reading.
     *
     * @return array{0: string, 1: bool}
     */
    public static function line(string $line, int $maxChars = self::MAX_MATCH_CHARS): array
    {
        if (mb_strlen($line, 'UTF-8') <= $maxChars) {
            return [$line, false];
        }

        return [mb_substr($line, 0, $maxChars, 'UTF-8') . '... [truncated]', true];
    }

    /** Human-readable bytes, for a message the model reads. */
    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => "{$bytes}B",
            $bytes < 1024 * 1024 => sprintf('%.1fKB', $bytes / 1024),
            default => sprintf('%.1fMB', $bytes / (1024 * 1024)),
        };
    }

    /** @param list<string> $kept */
    private static function result(
        array $kept,
        string $by,
        int $totalLines,
        int $totalBytes,
        int $maxLines,
        int $maxBytes,
        bool $partial = false,
    ): Truncation {
        $content = implode("\n", $kept);
        $bytes = strlen($content);

        // Stopping exactly at the line limit while still under the byte limit means the
        // line limit is what did it, whatever the loop last decided.
        if (count($kept) >= $maxLines && $bytes <= $maxBytes) {
            $by = 'lines';
        }

        return new Truncation(
            $content,
            true,
            $by,
            $totalLines,
            $totalBytes,
            count($kept),
            $bytes,
            $maxLines,
            $maxBytes,
            lastLinePartial: $partial,
        );
    }

    /**
     * The last $maxBytes of a string, starting at a character boundary.
     *
     * Cutting mid-character would produce invalid UTF-8, which the JSON encoder on the
     * way to the model refuses outright.
     */
    private static function lastBytes(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $start = strlen($text) - $maxBytes;

        // 10xxxxxx is a continuation byte: walk forward until the start of a character.
        while ($start < strlen($text) && (ord($text[$start]) & 0xc0) === 0x80) {
            $start++;
        }

        return substr($text, $start);
    }
}
