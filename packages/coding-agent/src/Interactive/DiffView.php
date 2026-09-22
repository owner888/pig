<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Style;

/**
 * An edit shown as a diff, coloured, with the changed words picked out.
 *
 * `Tools\EditDiff` produces the diff; this paints it. Split that way because the tool
 * has to produce the same text whether or not anyone is watching, and because the colours
 * belong to the palette rather than to the tool.
 *
 * Ported from upstream's `components/diff.ts`.
 */
final class DiffView
{
    /** What a tab is worth here. Not 8: a diff is narrow enough already. */
    private const string TAB = '   ';

    /**
     * @param string $diff as `EditDiff::render()` produces it
     */
    public static function render(string $diff, Palette $palette): string
    {
        $lines = explode("\n", $diff);
        $output = [];
        $at = 0;

        while ($at < count($lines)) {
            $parsed = self::parse($lines[$at]);

            if ($parsed === null) {
                $output[] = $palette->fg('toolDiffContext', $lines[$at]);
                $at++;

                continue;
            }

            [$prefix, $number, $content] = $parsed;

            if ($prefix === '+') {
                $output[] = $palette->fg('toolDiffAdded', '+' . $number . ' ' . self::tabs($content));
                $at++;

                continue;
            }

            if ($prefix !== '-') {
                $output[] = $palette->fg('toolDiffContext', ' ' . $number . ' ' . self::tabs($content));
                $at++;

                continue;
            }

            $at = self::change($lines, $at, $palette, $output);
        }

        return implode("\n", $output);
    }

    /**
     * One run of removed lines and the added lines that replace it.
     *
     * @param list<string> $lines
     * @param list<string> $output written into
     * @return int where to carry on from
     */
    private static function change(array $lines, int $at, Palette $palette, array &$output): int
    {
        $removed = [];
        $added = [];

        while ($at < count($lines) && ($parsed = self::parse($lines[$at])) !== null && $parsed[0] === '-') {
            $removed[] = [$parsed[1], $parsed[2]];
            $at++;
        }

        while ($at < count($lines) && ($parsed = self::parse($lines[$at])) !== null && $parsed[0] === '+') {
            $added[] = [$parsed[1], $parsed[2]];
            $at++;
        }

        // One line replaced by one line is a line somebody edited, so it is worth saying
        // which words changed. Any other shape is a block that moved or was rewritten,
        // where word-level marks would be noise on every line.
        if (count($removed) === 1 && count($added) === 1) {
            [$was, $now] = self::words(self::tabs($removed[0][1]), self::tabs($added[0][1]));

            $output[] = $palette->fg('toolDiffRemoved', '-' . $removed[0][0] . ' ' . $was);
            $output[] = $palette->fg('toolDiffAdded', '+' . $added[0][0] . ' ' . $now);

            return $at;
        }

        foreach ($removed as [$number, $content]) {
            $output[] = $palette->fg('toolDiffRemoved', '-' . $number . ' ' . self::tabs($content));
        }

        foreach ($added as [$number, $content]) {
            $output[] = $palette->fg('toolDiffAdded', '+' . $number . ' ' . self::tabs($content));
        }

        return $at;
    }

    /**
     * The two lines with their differing words inverted.
     *
     * Upstream uses the `diff` package's `diffWords`. What that gives for a line someone
     * edited is a common head, a changed middle and a common tail — which is what this
     * finds directly, word by word. A rewritten line comes back fully marked either way.
     *
     * Leading whitespace is never marked: inverting an indent draws a solid block at the
     * start of the line and says nothing, since indentation is not what changed.
     *
     * @return array{0: string, 1: string}
     */
    private static function words(string $old, string $new): array
    {
        $oldWords = self::split($old);
        $newWords = self::split($new);

        $head = 0;
        $limit = min(count($oldWords), count($newWords));

        while ($head < $limit && $oldWords[$head] === $newWords[$head]) {
            $head++;
        }

        $tail = 0;

        while (
            $tail < $limit - $head
            && $oldWords[count($oldWords) - 1 - $tail] === $newWords[count($newWords) - 1 - $tail]
        ) {
            $tail++;
        }

        return [
            self::mark($oldWords, $head, $tail),
            self::mark($newWords, $head, $tail),
        ];
    }

    /**
     * @param list<string> $words
     * @param int $head how many at the front are unchanged
     * @param int $tail how many at the back are unchanged
     */
    private static function mark(array $words, int $head, int $tail): string
    {
        $middle = array_slice($words, $head, count($words) - $head - $tail);
        $changed = implode('', $middle);

        // The indent moves out of the inverted run rather than being painted with it.
        $indent = $head === 0 ? (preg_match('/^\s*/', $changed, $match) === 1 ? $match[0] : '') : '';
        $changed = substr($changed, strlen($indent));

        return implode('', array_slice($words, 0, $head))
            . $indent
            . ($changed === '' ? '' : Style::inverse($changed))
            . implode('', array_slice($words, count($words) - $tail));
    }

    /**
     * Into words, each carrying the whitespace that follows it.
     *
     * Kept together so that a word and its spacing move as one: splitting them apart
     * marks the gaps between unchanged words as changed.
     *
     * @return list<string>
     */
    private static function split(string $line): array
    {
        preg_match_all('/\s*\S+\s*|\s+/', $line, $matches);

        return $matches[0];
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null prefix, line number, content
     */
    private static function parse(string $line): ?array
    {
        if (preg_match('/^([+\-\s])(\s*\d*)\s(.*)$/s', $line, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2], $match[3]];
    }

    private static function tabs(string $text): string
    {
        return str_replace("\t", self::TAB, $text);
    }
}
