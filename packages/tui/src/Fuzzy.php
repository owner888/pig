<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * Fuzzy matching: a query matches when its characters appear in order, not necessarily together.
 *
 * Upstream's `fuzzy.ts`, arithmetic and all, because the arithmetic *is* the feature — what a
 * picker feels like to type into is decided entirely by which of two matches scores lower, and a
 * rewritten scoring function is a different picker wearing the same name. Lower is better, which
 * is upstream's direction and worth saying twice: every comparison here reads backwards.
 *
 * Measured in characters and not bytes. Upstream counts UTF-16 units, which for everything a
 * model id or a menu label contains is the same number; bytes are not, and `strpos()` looking for
 * one byte of a three-byte character finds the middle of the character before it.
 */
final class Fuzzy
{
    /** Reward for each further character matched with no gap since the last one. */
    private const int CONSECUTIVE_BONUS = 5;

    /** Penalty per character skipped over between two matches. */
    private const int GAP_PENALTY = 2;

    /** Reward for landing at the start of a word — the run of characters below counts as one. */
    private const int WORD_BOUNDARY_BONUS = 10;

    /** What separates words, for the bonus above. */
    private const string WORD_SEPARATORS = " \t\n\r\f\x0B-_./:";

    /**
     * Whitespace, as JavaScript means it.
     *
     * PCRE's `\s` under `/u` is the White_Space property, which leaves out `﻿` because it is
     * a format character rather than a separator. JavaScript's `\s` includes it.
     */
    private const string SPACE = '\s\x{FEFF}';

    /** Reward for the query being the whole text rather than part of it. */
    private const int EXACT_BONUS = 100;

    /**
     * Charged for matching only once the query's letters and digits are swapped.
     *
     * So that `codex52` finds `gpt-5.2-codex` while still ranking below anything the query
     * matched as typed.
     */
    private const int SWAPPED_PENALTY = 5;

    /**
     * How well $query matches $text, and whether it does at all.
     *
     * @return array{bool, float} matched, and the score when it did — lower being better
     */
    public static function match(string $query, string $text): array
    {
        $needle = mb_strtolower($query, 'UTF-8');
        $haystack = mb_strtolower($text, 'UTF-8');

        [$matched, $score] = self::scoreOf($needle, $haystack);

        if ($matched) {
            return [true, $score];
        }

        // Digits typed before the letters they follow, or after the letters they precede: one
        // swap, tried once. Upstream's two patterns, and both anchored — a query with anything
        // else in it is not a near-miss of this kind and gets no second chance. `a-z` and not
        // `\p{L}`, also upstream's: the query is already lowercased, and a script whose letters
        // are not these has no digit-order convention this would be fixing.
        if (preg_match('/^(?<letters>[a-z]+)(?<digits>[0-9]+)$/', $needle, $parts) === 1) {
            $swapped = $parts['digits'] . $parts['letters'];
        } elseif (preg_match('/^(?<digits>[0-9]+)(?<letters>[a-z]+)$/', $needle, $parts) === 1) {
            $swapped = $parts['letters'] . $parts['digits'];
        } else {
            return [false, $score];
        }

        [$swappedMatched, $swappedScore] = self::scoreOf($swapped, $haystack);

        return $swappedMatched ? [true, $swappedScore + self::SWAPPED_PENALTY] : [false, $score];
    }

    /**
     * The items $query matches, best first.
     *
     * Whitespace and slashes separate tokens and every token has to match, which is what makes
     * `openai-codex/gpt-5.5` find a model whose text names the provider and the id in the other
     * order.
     *
     * @template T
     *
     * @param list<T>            $items
     * @param Closure(T): string $textOf
     *
     * @return list<T>
     */
    public static function filter(array $items, string $query, Closure $textOf): array
    {
        $tokens = preg_split('/[' . self::SPACE . '\/]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return $items;
        }

        $kept = [];

        foreach ($items as $item) {
            $text = $textOf($item);
            $total = 0.0;

            foreach ($tokens as $token) {
                [$matched, $score] = self::match($token, $text);

                if (!$matched) {
                    continue 2;
                }

                $total += $score;
            }

            $kept[] = [$total, $item];
        }

        // Stable since PHP 8.0, which matters and is not incidental: upstream relies on the same
        // guarantee in JavaScript, so items that score alike keep the order they were given in —
        // for the model picker that is the current model first, then the default one.
        usort($kept, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $row): mixed => $row[1], $kept);
    }

    /**
     * One token against one text, both already lowercased.
     *
     * @return array{bool, float}
     */
    private static function scoreOf(string $needle, string $haystack): array
    {
        if ($needle === '') {
            return [true, 0.0];
        }

        $wanted = mb_str_split($needle, 1, 'UTF-8');
        $characters = mb_str_split($haystack, 1, 'UTF-8');

        if (count($wanted) > count($characters)) {
            return [false, 0.0];
        }

        $score = 0.0;
        $last = -1;
        $consecutive = 0;

        foreach ($wanted as $character) {
            $at = self::indexOf($characters, $character, $last + 1);

            if ($at === null) {
                return [false, 0.0];
            }

            if ($last === $at - 1) {
                $consecutive++;
                $score -= $consecutive * self::CONSECUTIVE_BONUS;
            } else {
                $consecutive = 0;

                if ($last >= 0) {
                    $score += ($at - $last - 1) * self::GAP_PENALTY;
                }
            }

            if (
                $at === 0
                || str_contains(self::WORD_SEPARATORS, $characters[$at - 1])
                || ($characters[$at - 1] > "\x7f" && preg_match('/^[' . self::SPACE . ']$/u', $characters[$at - 1]) === 1)
            ) {
                $score -= self::WORD_BOUNDARY_BONUS;
            }

            // A thumb on the scale for matching early, small enough not to outweigh any of the
            // above on its own.
            $score += $at * 0.1;

            $last = $at;
        }

        return [true, $needle === $haystack ? $score - self::EXACT_BONUS : $score];
    }

    /**
     * Where $character first appears at or after $from.
     *
     * @param list<string> $characters
     */
    private static function indexOf(array $characters, string $character, int $from): ?int
    {
        for ($at = $from, $end = count($characters); $at < $end; $at++) {
            if ($characters[$at] === $character) {
                return $at;
            }
        }

        return null;
    }
}
