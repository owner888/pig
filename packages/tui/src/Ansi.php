<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Escape sequences, found and removed.
 *
 * Only the two kinds that show up in rendered output are recognised: CSI sequences ending
 * in `m` (colour and style) or `G`/`K`/`H`/`J` (cursor and clearing), and OSC 8 hyperlinks.
 * Anything else is left in the string and counted as visible, which is wrong but loud —
 * a stray sequence shows up as a misaligned line rather than as silently dropped text.
 */
final class Ansi
{
    /** Terminators that close the CSI sequences this file understands. */
    private const string TERMINATORS = 'mGKHJ';

    /**
     * The escape sequence starting at byte offset $pos, if one starts there.
     *
     * @return array{0: string, 1: int}|null the sequence and its byte length
     */
    public static function at(string $text, int $pos): ?array
    {
        if ($pos >= strlen($text) || $text[$pos] !== "\x1b" || ($text[$pos + 1] ?? '') !== '[') {
            return null;
        }

        $end = $pos + 2;
        $length = strlen($text);

        while ($end < $length && !str_contains(self::TERMINATORS, $text[$end])) {
            $end++;
        }

        if ($end >= $length) {
            return null;
        }

        $code = substr($text, $pos, $end + 1 - $pos);

        return [$code, strlen($code)];
    }

    /** The same text with the sequences this file understands taken out. */
    public static function strip(string $text): string
    {
        if (!str_contains($text, "\x1b")) {
            return $text;
        }

        $stripped = preg_replace(
            ['/\x1b\[[0-9;]*[' . self::TERMINATORS . ']/', '/\x1b\]8;;[^\x07]*\x07/'],
            '',
            $text,
        );

        if ($stripped === null) {
            throw new TuiError('Stripping escape codes failed: ' . preg_last_error_msg());
        }

        return $stripped;
    }

    /**
     * Split into escape sequences and grapheme clusters, in order.
     *
     * @return list<array{0: bool, 1: string}> true for an escape sequence, false for a grapheme
     */
    public static function segment(string $text): array
    {
        $segments = [];
        $pos = 0;
        $length = strlen($text);

        while ($pos < $length) {
            $code = self::at($text, $pos);

            if ($code !== null) {
                $segments[] = [true, $code[0]];
                $pos += $code[1];

                continue;
            }

            // Everything up to the next escape sequence is text, split into graphemes.
            $end = $pos;

            while ($end < $length && self::at($text, $end) === null) {
                $end++;
            }

            foreach (Graphemes::split(substr($text, $pos, $end - $pos)) as $grapheme) {
                $segments[] = [false, $grapheme];
            }

            $pos = $end;
        }

        return $segments;
    }
}
