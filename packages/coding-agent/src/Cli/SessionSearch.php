<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Session\SessionInfo;
use Pig\Tui\Fuzzy;

/**
 * What the session picker's search box means — upstream's `session-selector-search.ts`.
 *
 * Three kinds of query, all upstream's:
 *
 * - **Words**, each matched fuzzily and all of them required: `tls hand`.
 * - **A "quoted phrase"**, matched exactly (case and runs of whitespace ignored): `"node cve"`.
 *   An unbalanced quote falls back to plain words, so a half-typed phrase still searches.
 * - **`re:<pattern>`**, a case-insensitive regular expression. One that does not compile matches
 *   nothing rather than everything, which is upstream's "treat query as non-matching".
 *
 * Matched against the id, the name, every word said and the working directory
 * (`getSessionSearchText()`), so a session can be found by any of them.
 *
 * And three orders (`SortMode`): `threaded` is the fork tree when nothing is typed, `recent` keeps
 * the newest-first order the list came in, and `relevance` (shown as "Fuzzy") ranks by score with
 * the most recently written breaking a tie. A search in `threaded` ranks as `relevance` does.
 */
final class SessionSearch
{
    public const string THREADED = 'threaded';
    public const string RECENT = 'recent';
    public const string RELEVANCE = 'relevance';

    /**
     * Upstream's `parseSearchQuery()`.
     *
     * @return array{mode: 'tokens'|'regex', tokens: list<array{kind: 'fuzzy'|'phrase', value: string}>, regex: string|null, error: string|null}
     */
    public static function parse(string $query): array
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return ['mode' => 'tokens', 'tokens' => [], 'regex' => null, 'error' => null];
        }

        if (str_starts_with($trimmed, 're:')) {
            $pattern = trim(substr($trimmed, 3));

            if ($pattern === '') {
                return ['mode' => 'regex', 'tokens' => [], 'regex' => null, 'error' => 'Empty regex'];
            }

            // A delimiter nobody types, so the pattern is the pattern and no slash needs escaping.
            $regex = "\x01{$pattern}\x01iu";
            $error = null;
            set_error_handler(static function (int $no, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });

            try {
                $compiled = preg_match($regex, '');
            } finally {
                restore_error_handler();
            }

            if ($compiled === false) {
                return ['mode' => 'regex', 'tokens' => [], 'regex' => null, 'error' => $error ?? preg_last_error_msg()];
            }

            return ['mode' => 'regex', 'tokens' => [], 'regex' => $regex, 'error' => null];
        }

        $tokens = [];
        $buffer = '';
        $inQuote = false;

        $flush = static function (string $kind) use (&$buffer, &$tokens): void {
            $value = trim($buffer);
            $buffer = '';

            if ($value !== '') {
                $tokens[] = ['kind' => $kind, 'value' => $value];
            }
        };

        foreach (mb_str_split($trimmed) as $char) {
            if ($char === '"') {
                $flush($inQuote ? 'phrase' : 'fuzzy');
                $inQuote = !$inQuote;

                continue;
            }

            if (!$inQuote && preg_match('/\s/u', $char) === 1) {
                $flush('fuzzy');

                continue;
            }

            $buffer .= $char;
        }

        if ($inQuote) {
            // Unbalanced: plain whitespace-separated words, quotes and all.
            $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'mode' => 'tokens',
                'tokens' => array_map(static fn (string $word): array => ['kind' => 'fuzzy', 'value' => $word], $words),
                'regex' => null,
                'error' => null,
            ];
        }

        $flush('fuzzy');

        return ['mode' => 'tokens', 'tokens' => $tokens, 'regex' => null, 'error' => null];
    }

    /**
     * Upstream's `matchSession()`: whether it matches and how well, lower being better.
     *
     * @param array{mode: string, tokens: list<array{kind: string, value: string}>, regex: string|null, error: string|null} $parsed
     * @return array{0: bool, 1: float}
     */
    public static function match(SessionInfo $session, array $parsed): array
    {
        $text = self::searchText($session);

        if ($parsed['mode'] === 'regex') {
            if ($parsed['regex'] === null || preg_match($parsed['regex'], $text, $found, PREG_OFFSET_CAPTURE) !== 1) {
                return [false, 0.0];
            }

            return [true, mb_strlen(substr($text, 0, $found[0][1])) * 0.1];
        }

        $total = 0.0;
        $normalized = null;

        foreach ($parsed['tokens'] as $token) {
            if ($token['kind'] === 'phrase') {
                $normalized ??= self::normalize($text);
                $phrase = self::normalize($token['value']);

                if ($phrase === '') {
                    continue;
                }

                $at = mb_strpos($normalized, $phrase);

                if ($at === false) {
                    return [false, 0.0];
                }

                $total += $at * 0.1;

                continue;
            }

            [$matched, $score] = Fuzzy::match($token['value'], $text);

            if (!$matched) {
                return [false, 0.0];
            }

            $total += $score;
        }

        return [true, $total];
    }

    /**
     * Upstream's `filterAndSortSessions()`, after the name filter: nothing typed keeps the list as
     * it is, `recent` filters in the order given, and anything else ranks.
     *
     * @param list<SessionInfo> $sessions
     * @return list<SessionInfo>
     */
    public static function filterAndSort(array $sessions, string $query, string $sortMode): array
    {
        if (trim($query) === '') {
            return $sessions;
        }

        $parsed = self::parse($query);

        if ($parsed['error'] !== null) {
            return [];
        }

        $scored = [];

        foreach ($sessions as $index => $session) {
            [$matched, $score] = self::match($session, $parsed);

            if ($matched) {
                $scored[] = [$score, $session, $index];
            }
        }

        if ($sortMode !== self::RECENT) {
            usort($scored, static fn (array $a, array $b): int
                => [$a[0], $b[1]->modified, $a[2]] <=> [$b[0], $a[1]->modified, $b[2]]);
        }

        return array_map(static fn (array $row): SessionInfo => $row[1], $scored);
    }

    /** Upstream's `hasSessionName()`. */
    public static function hasName(SessionInfo $session): bool
    {
        return $session->name !== null && trim($session->name) !== '';
    }

    /** Upstream's `getSessionSearchText()`: id, name, every word said, and the working directory. */
    private static function searchText(SessionInfo $session): string
    {
        return "{$session->id} " . ($session->name ?? '') . " {$session->text} {$session->cwd}";
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
