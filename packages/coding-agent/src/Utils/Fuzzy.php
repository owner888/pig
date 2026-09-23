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
 * One deliberate difference from upstream, and it is the only one: the walk is over
 * **characters, not bytes**. JavaScript indexes a string by UTF-16 code unit, which for
 * everything either project searches is one unit per character; PHP's `$text[$i]` is a byte,
 * so a Chinese query would compare thirds of characters against each other and score
 * nonsense. `mb_str_split()` makes the two agree.
 */
final class Fuzzy
{
    /** What counts as the start of a word, when the character before a match is one of these. */
    private const string BOUNDARY = '/[\s\-_.\/]/u';

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
        $tokens = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);

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
