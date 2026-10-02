<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Word wrapping that survives colour.
 *
 * Wrapping is done on tokens rather than characters so words stay whole, and every token
 * carries the escape codes that preceded it, so a break never separates a colour from the
 * text it was meant for. Lines come back unpadded — padding is the caller's business,
 * because only the caller knows whether a background should run to the edge.
 */
final class TextWrap
{
    /**
     * Wrap to $width columns, keeping styles across the breaks.
     *
     * @return list<string> at least one line, even for empty input
     */
    public static function wrap(string $text, int $width): array
    {
        if ($text === '') {
            return [''];
        }

        $lines = [];
        $tracker = new AnsiTracker();

        foreach (explode("\n", $text) as $line) {
            // A literal newline does not reset the terminal, so styles carry over it.
            $prefix = $lines === [] ? '' : $tracker->activeCodes();

            foreach (self::wrapLine($prefix . $line, $width) as $wrapped) {
                $lines[] = $wrapped;
            }

            $tracker->processText($line);
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * How many rows `wrap()` would make, without making them.
     *
     * A line that fits is one row, which is the common case and costs one `Width::visible()`.
     * A line of plain printable ASCII that does not fit is counted by the same rule `wrapLine()`
     * breaks it by — words whole, a word wider than the line cut into pieces of the width — in
     * one pass over its bytes, with nothing built. Anything else is wrapped for real and counted,
     * because the escape codes and the grapheme walk are where the two could come to disagree.
     * `TextWrapTest` holds the fast count to the slow one over random lines.
     */
    public static function rows(string $text, int $width): int
    {
        if ($text === '') {
            return 1;
        }

        $rows = 0;

        foreach (explode("\n", $text) as $line) {
            if ($line === '' || Width::visible($line) <= $width) {
                $rows++;
            } elseif (preg_match('/^[\x20-\x7e]*\z/', $line) === 1) {
                $rows += self::countPlainRows($line, $width);
            } else {
                $rows += count(self::wrapLine($line, $width));
            }
        }

        return $rows;
    }

    /** `wrapLine()`'s arithmetic for a line of printable ASCII wider than $width, counting only. */
    private static function countPlainRows(string $line, int $width): int
    {
        $rows = 0;
        $current = 0;

        // Spaces are tokens too, and a line never starts with the space that pushed it over.
        foreach (preg_split('/( +)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
            $length = strlen($token);
            $isSpace = $token[0] === ' ';

            if ($length > $width && !$isSpace) {
                if ($current > 0) {
                    $rows++;
                }

                $rows += intdiv($length - 1, $width);
                $current = $length - intdiv($length - 1, $width) * $width;

                continue;
            }

            if ($current + $length > $width && $current > 0) {
                $rows++;
                $current = $isSpace ? 0 : $length;
            } else {
                $current += $length;
            }
        }

        return $rows + ($current > 0 ? 1 : 0);
    }

    /** @return list<string> */
    private static function wrapLine(string $line, int $width): array
    {
        if ($line === '') {
            return [''];
        }

        if (Width::visible($line) <= $width) {
            return [$line];
        }

        $lines = [];
        $tracker = new AnsiTracker();
        $current = '';
        $currentWidth = 0;

        foreach (self::tokenize($line) as $token) {
            $tokenWidth = Width::visible($token);
            $isWhitespace = trim($token) === '';

            // A single word wider than the line has to be cut mid-word.
            if ($tokenWidth > $width && !$isWhitespace) {
                if ($current !== '') {
                    $lines[] = $current . $tracker->lineEndReset();
                }

                $broken = self::breakWord($token, $width, $tracker);
                $current = array_pop($broken);

                foreach ($broken as $brokenLine) {
                    $lines[] = $brokenLine;
                }

                // breakWord already fed the tracker the codes inside the word.
                $currentWidth = Width::visible($current);

                continue;
            }

            if ($currentWidth + $tokenWidth > $width && $currentWidth > 0) {
                $lines[] = rtrim($current) . $tracker->lineEndReset();

                // A line never starts with the space that pushed it over.
                $current = $tracker->activeCodes() . ($isWhitespace ? '' : $token);
                $currentWidth = $isWhitespace ? 0 : $tokenWidth;
            } else {
                $current .= $token;
                $currentWidth += $tokenWidth;
            }

            $tracker->processText($token);
        }

        if ($current !== '') {
            // No reset on the last line: the caller may be continuing the same style.
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private const string CJK_PATTERN = '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Bopomofo}]\z/u';

    /**
     * Split into runs of spaces, words, and CJK characters with escape codes attached to what follows.
     *
     * Matching upstream pi's `splitIntoTokensWithAnsi` and `cjkBreakRegex`:
     * - CJK characters (Han, Hiragana, Katakana, Hangul, Bopomofo) can break naturally between any glyph,
     *   so each CJK cluster forms its own atomic token. This avoids prematurely slicing trailing English
     *   words (e.g. `请确认提交commit-id` cleanly wrapping `commit-id` as a whole unit to the next line).
     * - Spaces and Latin words group as usual.
     *
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        $current = '';
        $pending = '';
        $inWhitespace = false;
        $pos = 0;
        $length = strlen($text);

        while ($pos < $length) {
            $code = Ansi::at($text, $pos);

            if ($code !== null) {
                $pending .= $code[0];
                $pos += $code[1];

                continue;
            }

            $byte = ord($text[$pos]);
            if ($byte < 0x80) {
                $char = $text[$pos];
                $pos++;
                $isSpace = $char === ' ';

                if ($isSpace !== $inWhitespace && $current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }

                $current .= $pending;
                $pending = '';
                $inWhitespace = $isSpace;
                $current .= $char;

                continue;
            }

            $sub = substr($text, $pos);
            $graphemes = Graphemes::split($sub);
            $cluster = $graphemes[0] ?? $text[$pos];
            $pos += strlen($cluster);

            if (preg_match(self::CJK_PATTERN, $cluster) === 1) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                $tokens[] = $pending . $cluster;
                $pending = '';
                $inWhitespace = false;

                continue;
            }

            if ($inWhitespace && $current !== '') {
                $tokens[] = $current;
                $current = '';
            }

            $current .= $pending . $cluster;
            $pending = '';
            $inWhitespace = false;
        }

        $current .= $pending;

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Cut one over-long word into lines, grapheme by grapheme.
     *
     * $tracker is advanced as the word's own codes go by, so each new line reopens the
     * style that was in force at the point it was cut.
     *
     * @return non-empty-list<string>
     */
    private static function breakWord(string $word, int $width, AnsiTracker $tracker): array
    {
        // A word of plain printable ASCII — a minified line, a base64 blob, a long path — is one
        // column a byte, so it can be cut by bytes. The grapheme walk below gives the same bytes
        // (checked over 3,000 random words) and costs 10ms on a 46KB line; a transcript of build
        // logs has several of those, and they are wrapped again at every width. The tokenizer
        // attaches a line's opening style to its first word and its closing style to its last,
        // so a word is `codes, text, codes`: the opening ones ride on the first piece as they
        // came, the closing ones on the last, exactly as the walk below places them.
        $code = '(?:\x1b\[[0-9;]*[' . Ansi::TERMINATORS . '])*';

        if (preg_match('/^(' . $code . ')([\x20-\x7e]*)(' . $code . ')\z/', $word, $parts) === 1) {
            $before = $tracker->activeCodes();
            $tracker->processText($parts[1]);
            $open = $tracker->activeCodes();
            $pieces = str_split($parts[2], $width);
            $last = array_pop($pieces);
            $lines = [];

            foreach ($pieces as $index => $piece) {
                $lines[] = ($index === 0 ? $before . $parts[1] : $open) . $piece . $tracker->lineEndReset();
            }

            $tracker->processText($parts[3]);
            $lines[] = ($pieces === [] ? $before . $parts[1] : $open) . $last . $parts[3];

            return $lines;
        }

        $lines = [];
        $current = $tracker->activeCodes();
        $currentWidth = 0;

        foreach (Ansi::segment($word) as [$isCode, $value]) {
            if ($isCode) {
                $current .= $value;
                $tracker->process($value);

                continue;
            }

            $graphemeWidth = Width::visible($value);

            if ($currentWidth + $graphemeWidth > $width) {
                $lines[] = $current . $tracker->lineEndReset();
                $current = $tracker->activeCodes();
                $currentWidth = 0;
            }

            $current .= $value;
            $currentWidth += $graphemeWidth;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
