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
 * Each field is matched on its own, so a token never matches across field boundaries (upstream
 * #11220): the name and the working directory fuzzily, the id and every word said exactly only —
 * a scattered subsequence in megabytes of messages or in a UUID is noise. Ranking is by tier
 * first: the token's text in the name, then in the id, the messages or the working directory,
 * then scattered through the name or the working directory.
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

    /** Upstream's match tiers, best first; a tier outweighs any fuzzy score within it. */
    private const int TIER_NAME = 0;

    private const int TIER_TEXT = 1;

    private const int TIER_FUZZY = 2;

    private const int TIER_WEIGHT = 1_000_000;

    /**
     * Lowercased message text per session: `text` holds every message and can be megabytes, so it
     * is lowercased once and not on every keystroke.
     *
     * @var \WeakMap<SessionInfo, array{source: string, lower: string, normalized?: string}>|null
     */
    private static ?\WeakMap $messageText = null;

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
        if ($parsed['mode'] === 'regex') {
            if ($parsed['regex'] === null) {
                return [false, 0.0];
            }

            $score = self::matchRegex($session, $parsed['regex']);

            return $score === null ? [false, 0.0] : [true, $score];
        }

        $total = 0.0;

        foreach ($parsed['tokens'] as $token) {
            if ($token['kind'] === 'phrase') {
                $phrase = self::normalize($token['value']);

                if ($phrase === '') {
                    continue;
                }

                $score = self::matchPhrase($session, $phrase);
            } else {
                $score = self::matchFuzzy($session, $token['value']);
            }

            if ($score === null) {
                return [false, 0.0];
            }

            $total += $score;
        }

        return [true, $total];
    }

    private static function tierScore(int $tier, float $score = 0.0): float
    {
        return $tier * self::TIER_WEIGHT + $score;
    }

    /** Upstream's `matchLiteralToken()`: the token's text, exactly, in one field. */
    private static function matchLiteral(SessionInfo $session, string $value): ?float
    {
        $name = mb_strtolower($session->name ?? '');

        if ($value !== '' && str_contains($name, $value)) {
            return self::tierScore(self::TIER_NAME, Fuzzy::match($value, $name)[1]);
        }

        if (str_contains(mb_strtolower($session->id), $value)
            || str_contains(self::messageText($session)['lower'], $value)
            || str_contains(mb_strtolower($session->cwd), $value)) {
            return self::tierScore(self::TIER_TEXT);
        }

        return null;
    }

    /** Upstream's `matchFuzzyToken()`: exact anywhere, else scattered in the name or the working directory. */
    private static function matchFuzzy(SessionInfo $session, string $value): ?float
    {
        $literal = self::matchLiteral($session, mb_strtolower($value));

        if ($literal !== null) {
            return $literal;
        }

        $best = null;

        foreach ([$session->name ?? '', $session->cwd] as $field) {
            [$matched, $score] = Fuzzy::match($value, $field);

            if ($matched && ($best === null || $score < $best)) {
                $best = $score;
            }
        }

        return $best === null ? null : self::tierScore(self::TIER_FUZZY, $best);
    }

    /** Upstream's `matchPhraseToken()`, with case and runs of whitespace ignored. */
    private static function matchPhrase(SessionInfo $session, string $phrase): ?float
    {
        $name = self::normalize($session->name ?? '');

        if (str_contains($name, $phrase)) {
            return self::tierScore(self::TIER_NAME, Fuzzy::match($phrase, $name)[1]);
        }

        $cache = self::messageText($session);
        $normalized = $cache['normalized'] ?? self::cacheNormalized($session);

        if (str_contains(mb_strtolower($session->id), $phrase)
            || str_contains($normalized, $phrase)
            || str_contains(self::normalize($session->cwd), $phrase)) {
            return self::tierScore(self::TIER_TEXT);
        }

        return null;
    }

    /** Upstream's `matchRegex()`: the name first, then the id, the messages or the working directory. */
    private static function matchRegex(SessionInfo $session, string $regex): ?float
    {
        if ($session->name !== null && $session->name !== '' && preg_match($regex, $session->name) === 1) {
            return self::tierScore(self::TIER_NAME);
        }

        foreach ([$session->id, $session->text, $session->cwd] as $field) {
            if (preg_match($regex, $field) === 1) {
                return self::tierScore(self::TIER_TEXT);
            }
        }

        return null;
    }

    /** @return array{source: string, lower: string, normalized?: string} */
    private static function messageText(SessionInfo $session): array
    {
        self::$messageText ??= new \WeakMap();
        $cached = self::$messageText[$session] ?? null;

        if ($cached === null || $cached['source'] !== $session->text) {
            $cached = ['source' => $session->text, 'lower' => mb_strtolower($session->text)];
            self::$messageText[$session] = $cached;
        }

        return $cached;
    }

    private static function cacheNormalized(SessionInfo $session): string
    {
        $cached = self::messageText($session);
        $cached['normalized'] = trim((string) preg_replace('/\s+/u', ' ', $cached['lower']));
        self::$messageText[$session] = $cached;

        return $cached['normalized'];
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

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
