<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Working out what an edit changed, and drawing it.
 *
 * Upstream uses the `diff` npm package. Nothing general is needed here: an edit replaces
 * one contiguous run of text with another, so the lines before the change are identical
 * and so are the lines after it. Trimming the common head and tail gives the exact
 * minimal diff for that, in one pass and without an LCS table.
 *
 * It is not a general diff and does not pretend to be. Given two files that differ in
 * several places it produces one block covering all of them — correct, but not the
 * smallest description. If something ever needs a real diff, that is the point to write
 * one rather than to stretch this.
 */
final class EditDiff
{
    /** Lines of unchanged text shown either side of a change. */
    public const int CONTEXT = 4;

    /**
     * The line ending the file already uses.
     *
     * Whichever kind comes first wins: a file with one stray CRLF near the end is an LF
     * file, and rewriting all of it would turn a two-line edit into a whole-file diff in
     * the person's version control.
     */
    public static function lineEnding(string $content): string
    {
        $crlf = strpos($content, "\r\n");
        $lf = strpos($content, "\n");

        if ($lf === false || $crlf === false) {
            return "\n";
        }

        return $crlf < $lf ? "\r\n" : "\n";
    }

    public static function toLf(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function restore(string $text, string $ending): string
    {
        return $ending === "\r\n" ? str_replace("\n", "\r\n", $text) : $text;
    }

    /**
     * The byte-order mark, if the file has one, and the rest.
     *
     * Taken off before matching: it is invisible, so a model reproducing the first line
     * of a file will not have included it, and leaving it on makes that match fail for a
     * reason nothing in the error would explain.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitBom(string $content): array
    {
        return str_starts_with($content, "\u{FEFF}")
            ? ["\u{FEFF}", substr($content, 3)]
            : ['', $content];
    }

    /**
     * A diff with line numbers, and the line the change starts on in the new file.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function render(string $old, string $new, int $context = self::CONTEXT): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $prefix = self::commonPrefix($oldLines, $newLines);
        $suffix = self::commonSuffix($oldLines, $newLines, $prefix);

        $removed = array_slice($oldLines, $prefix, count($oldLines) - $suffix - $prefix);
        $added = array_slice($newLines, $prefix, count($newLines) - $suffix - $prefix);

        if ($removed === [] && $added === []) {
            return ['', null];
        }

        $width = strlen((string) max(count($oldLines), count($newLines)));
        $blank = str_repeat(' ', $width);
        $output = [];

        // Leading context: the last few unchanged lines, with an ellipsis for the rest.
        $leading = min($prefix, $context);

        if ($prefix > $leading) {
            $output[] = " {$blank} ...";
        }

        for ($index = $prefix - $leading; $index < $prefix; $index++) {
            $output[] = ' ' . self::number($index + 1, $width) . ' ' . $oldLines[$index];
        }

        foreach ($removed as $offset => $line) {
            $output[] = '-' . self::number($prefix + $offset + 1, $width) . ' ' . $line;
        }

        foreach ($added as $offset => $line) {
            $output[] = '+' . self::number($prefix + $offset + 1, $width) . ' ' . $line;
        }

        $trailing = min($suffix, $context);
        $start = count($newLines) - $suffix;

        for ($index = $start; $index < $start + $trailing; $index++) {
            $output[] = ' ' . self::number($index + 1, $width) . ' ' . $newLines[$index];
        }

        if ($suffix > $trailing) {
            $output[] = " {$blank} ...";
        }

        return [implode("\n", $output), $prefix + 1];
    }

    private static function number(int $line, int $width): string
    {
        return str_pad((string) $line, $width, ' ', STR_PAD_LEFT);
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     */
    private static function commonPrefix(array $old, array $new): int
    {
        $limit = min(count($old), count($new));
        $count = 0;

        while ($count < $limit && $old[$count] === $new[$count]) {
            $count++;
        }

        return $count;
    }

    /**
     * Matching lines at the end, never overlapping the prefix.
     *
     * Without that guard, a file of identical lines would count the same lines twice and
     * the middle would come out negative.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private static function commonSuffix(array $old, array $new, int $prefix): int
    {
        $limit = min(count($old), count($new)) - $prefix;
        $count = 0;

        while (
            $count < $limit
            && $old[count($old) - 1 - $count] === $new[count($new) - 1 - $count]
        ) {
            $count++;
        }

        return $count;
    }
}
