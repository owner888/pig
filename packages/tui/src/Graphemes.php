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

        $clusters = [];

        foreach ($matches[0] as $cluster) {
            // One codepoint is at most four bytes, and the bug needs two pictographs.
            if (strlen($cluster) > 4 && preg_match_all('/\p{Extended_Pictographic}/u', $cluster) > 1) {
                array_push($clusters, ...self::splitPictographs($cluster));
            } else {
                $clusters[] = $cluster;
            }
        }

        return $clusters;
    }

    /**
     * A cluster `\X` made of more than one emoji, cut back into them.
     *
     * **PCRE2 10.42 — the one PHP 8.3 bundles — joins adjacent pictographs into one cluster**:
     * `🙂🙂🙂` came back as one grapheme, two columns wide, where the terminal draws six, and every
     * cursor move after it landed short. UAX #29 joins two pictographs only across a ZWJ (GB11,
     * `👨‍👩‍👧`), so the cut goes before each pictograph that does not follow one. On a PCRE that
     * gets this right there is never such a cluster to cut.
     *
     * @return list<string>
     */
    private static function splitPictographs(string $cluster): array
    {
        $pieces = [];
        $piece = '';
        $previous = null;

        foreach (mb_str_split($cluster, 1, 'UTF-8') as $codepoint) {
            if ($piece !== '' && $previous !== "\u{200D}" && preg_match('/^\p{Extended_Pictographic}$/u', $codepoint) === 1) {
                $pieces[] = $piece;
                $piece = '';
            }

            $piece .= $codepoint;
            $previous = $codepoint;
        }

        $pieces[] = $piece;

        return $pieces;
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
