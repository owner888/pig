<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

use Closure;

/**
 * Fuzzy matching: every character of the query, in order, somewhere in the text.
 *
 * Upstream's `utils/fuzzy.ts`, arithmetic for arithmetic. It is what makes a list of thirty
 * conversations findable by typing `tls` and getting the one where the handshake was being
 * debugged — a substring match finds that only if somebody wrote the letters together.
 *
 * **The scoring is a pile of penalties, so lower is better.** Consecutive characters are
 * rewarded and the reward grows along the run, gaps are penalised, a match at the start of a
 * word is worth a lot, and a match late in the string costs a little — which together mean
 * `foo` prefers `foobar` to `f_o_o` and prefers a file called `foo.php` to one that merely
 * mentions it near the end.
 *
 * ### Where this differs from upstream, measured rather than reasoned about
 *
 * A corpus of 3,078 comparisons against `fuzzy.ts` — every query against every haystack, 600
 * random slices used as their own query, and the ordering both produce — leaves **36 score
 * differences and no disagreement about whether anything matches**, and every one of the 36 is
 * one of these two:
 *
 * **The walk is over characters, and upstream's is over UTF-16 code units.** `$text[$i]` in PHP
 * is a byte, so `mb_str_split()` is not optional — a Chinese query would otherwise compare thirds
 * of characters. This docblock used to add that code units and characters are the same thing "for
 * everything either project searches", and the corpus says otherwise: an **astral** character is
 * two code units and one character, so every position after one differs. `math 𝐀𝐁𝐂 astral
 * letters` searched for `math 𝐀𝐁` scores −241.4 upstream and −157.9 here. Emoji are astral and a
 * conversation's text is full of them. **pig's is the better answer** — the score is built out of
 * gaps and positions, and a family emoji inflating a gap by eight where a reader sees one
 * character is upstream measuring the encoding rather than the text — so this is kept and the
 * claim that used to hide it is gone.
 *
 * **U+FEFF is whitespace to JavaScript and not to PCRE.** The two classes differ by exactly three
 * codepoints, checked one by one over the whole BMP: `﻿` is in JavaScript's `\s` and not in
 * PCRE's, while U+0085 (NEL) and U+180E are in PCRE's and not in JavaScript's. Only the first is
 * a mistake to inherit — a zero-width no-break space is invisible, so a word after one is still
 * at the start of a word — so it is added here and the other two are left, which makes pig's
 * class the **union**. Same reasoning as `version_compare()` replacing upstream's arithmetic:
 * where JavaScript is the one missing a case, the port does not copy the gap.
 */
final class Fuzzy
{
    /**
     * Whitespace, as JavaScript means it.
     *
     * PCRE's `\s` under `/u` is the White_Space property, which leaves out `﻿` because it is
     * a format character rather than a separator. JavaScript's `\s` includes it, and for both
     * readers below that is the answer worth having — see the class docblock.
     */
    private const string SPACE = '\s\x{FEFF}';

    /** What counts as the start of a word, when the character before a match is one of these. */
    private const string BOUNDARY = '/[' . self::SPACE . '\-_.\/]/u';

    /**
     * Does the query match, and how well.
     *
     * An empty query matches everything with a score of 0 — it is not a filter yet. A query
     * longer than the text cannot match, and is answered before the walk rather than by it.
     */
    public static function match(string $query, string $text): FuzzyMatch
    {
        $needle = mb_str_split(mb_strtolower($query, 'UTF-8'));
        $haystack = mb_str_split(mb_strtolower($text, 'UTF-8'));

        if ($needle === []) {
            return new FuzzyMatch(true, 0.0);
        }

        if (count($needle) > count($haystack)) {
            return new FuzzyMatch(false, 0.0);
        }

        $wanted = 0;
        $score = 0.0;
        $lastMatch = -1;
        $consecutive = 0;
        $length = count($haystack);

        for ($i = 0; $i < $length && $wanted < count($needle); $i++) {
            if ($haystack[$i] !== $needle[$wanted]) {
                continue;
            }

            $atWordStart = $i === 0 || preg_match(self::BOUNDARY, $haystack[$i - 1]) === 1;

            if ($lastMatch === $i - 1) {
                // Reward a run, and reward it more the longer it gets: typing `foo` should
                // find `foobar` before `f_o_o`.
                $consecutive++;
                $score -= $consecutive * 5;
            } else {
                $consecutive = 0;

                if ($lastMatch >= 0) {
                    $score += ($i - $lastMatch - 1) * 2;
                }
            }

            if ($atWordStart) {
                // The start of a word is far more likely to be what was aimed at than the
                // middle of one, so this outweighs several characters of gap.
                $score -= 10;
            }

            // A small, steady preference for matching early in the string.
            $score += $i * 0.1;

            $lastMatch = $i;
            $wanted++;
        }

        return $wanted < count($needle) ? new FuzzyMatch(false, 0.0) : new FuzzyMatch(true, $score);
    }

    /**
     * The items that match, best first.
     *
     * The query is split on whitespace and **every** token has to match, which is what makes
     * a long haystack usable: `tls handshake` narrows where `tlshandshake` would match
     * nothing. The scores are added, so an item matching both tokens well comes first.
     *
     * A blank query gives the list back untouched — including its order, which for sessions is
     * newest first and is the answer somebody who has typed nothing is expecting.
     *
     * `usort` has been stable since PHP 8.0, as `Array.prototype.sort` is in JavaScript, so two
     * items scoring the same keep the order they arrived in. That is load-bearing rather than
     * incidental: a list of sessions is sorted by time before it gets here, and a search whose
     * ties came back shuffled would look like the list had lost its order.
     *
     * @template T
     * @param list<T> $items
     * @param Closure(T): string $text what to match against
     * @return list<T>
     */
    public static function filter(array $items, string $query, Closure $text): array
    {
        // **`/u`, and it is the whole entry in the traps about this.** This was
        // `preg_split('/\s+/', trim($query), …)` — an ASCII-only split after an ASCII-only trim —
        // so a space that is not U+0020 was a character in the query rather than a gap between
        // tokens. `tls　handshake` typed with a full-width IME, which is what pressing space on a
        // Chinese keyboard produces, became one token nothing could match, and the list somebody
        // was searching went empty. `PREG_SPLIT_NO_EMPTY` already drops the ends, so the `trim()`
        // that used to be here was doing nothing the split does not.
        $tokens = preg_split('/[' . self::SPACE . ']+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return $items;
        }

        $scored = [];

        foreach ($items as $item) {
            $haystack = $text($item);
            $score = 0.0;

            foreach ($tokens as $token) {
                $match = self::match($token, $haystack);

                if (!$match->matches) {
                    continue 2;
                }

                $score += $match->score;
            }

            $scored[] = [$item, $score];
        }

        usort($scored, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return array_map(static fn (array $pair): mixed => $pair[0], $scored);
    }
}
