<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Text split the way a terminal draws it: one entry per user-perceived character.
 *
 * `👨‍👩‍👧‍👦` is seven codepoints, eleven bytes of UTF-8, and one thing on screen. Everything
 * the renderer measures or cuts has to agree with that, or a line ends up one column wide
 * in the buffer and four on the terminal.
 *
 * Upstream reaches for `Intl.Segmenter`, which the JS runtime ships. PHP's equivalent is
 * `IntlBreakIterator`, which needs ext-intl — but PCRE's `\X` already implements UAX #29
 * extended grapheme clusters, and PCRE is compiled into every PHP. So this is a wrapper
 * around one regex rather than a table of Unicode ranges.
 */
final class Graphemes
{
    /**
     * Split into grapheme clusters.
     *
     * @return list<string> empty for the empty string
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            throw new TuiError('Grapheme split failed: ' . preg_last_error_msg());
        }

        return $matches[0];
    }

    /** The first codepoint, as an integer. Null for the empty string. */
    public static function firstCodepoint(string $text): ?int
    {
        if ($text === '') {
            return null;
        }

        $codepoints = mb_str_split($text, 1, 'UTF-8');

        return mb_ord($codepoints[0], 'UTF-8') ?: null;
    }
}
