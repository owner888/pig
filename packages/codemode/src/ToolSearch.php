<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * Tool discovery — upstream's `extensions/tool-search/tool.js`: a BM25 ranker over tool metadata,
 * shared by the `tool_search` tool (`pig-tool-search`) and `search_tools()` in codemode scripts,
 * which is why it lives in this package and not in either extension.
 *
 * Upstream keeps a loadout with an active set per tool and records activation in the transcript,
 * so a loaded tool survives `/tree` and resume. pig has no exposure in its loadout: a `deferred`
 * tool is simply **not registered** until `tool_search` names it — it waits in `DeferredTools` —
 * and then it is registered like any other, which reaches the agent through the same door a
 * connecting server uses. The cost is that a
 * resumed session starts with the deferred tools unloaded again, and the model searches once more.
 * That is the smaller mechanism and the one with no second copy of what the agent's tools are.
 */
final class ToolSearch
{
    public const string TOOL_NAME = 'tool_search';

    public const int DEFAULT_LIMIT = 8;

    private const array STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in', 'is', 'it', 'of', 'on',
        'or', 'that', 'the', 'this', 'to', 'with',
    ];

    /**
     * A query term in the name counts this many times its IDF — upstream's `NAME_WEIGHT`. The text
     * part of a term is at most `k1 + 1` (2.2) times its IDF and about 1 for a single mention, so a
     * name match beats any number of mentions in the description.
     */
    private const float NAME_WEIGHT = 3.0;

    /**
     * A query term in the namespace's name or description counts this many times its IDF —
     * upstream's `NAMESPACE_WEIGHT`. Every tool of the server shares them, so they help find the
     * server but not the tool in it.
     */
    private const float NAMESPACE_WEIGHT = 0.5;

    /**
     * Unicode words, split at identifier boundaries, lowercased, without English stop words and
     * with English plurals singular — upstream's `tokenize()`.
     *
     * NFC first, so `cafe\u{301}` and `café` are one word. Plain ASCII is split at spaces, as
     * upstream splits it; any other text goes through a word segmenter, so Chinese or Thai written
     * without spaces comes apart into words. Upstream's is `Intl.Segmenter`; PHP's is
     * `IntlBreakIterator`, which is ext-intl — not required (decisions.md, "Extensions"), so without
     * it the text is split at spaces as ASCII is, and NFC is skipped: a run of a script written
     * without spaces is then one term, which still matches the same run.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $text = mb_scrub($text, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        $text = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        $text = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text);
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8'));
        $words = preg_match('/^[a-z0-9 ]*$/', $text) === 1 || !class_exists(\IntlBreakIterator::class)
            ? explode(' ', $text)
            : self::segment($text);
        $out = [];

        foreach ($words as $word) {
            if ($word !== '' && !in_array($word, self::STOP_WORDS, true)) {
                $out[] = preg_match('/^[a-z0-9]+$/', $word) === 1 ? self::stem($word) : $word;
            }
        }

        return $out;
    }

    /**
     * The word-like segments of $text, `Intl.Segmenter`'s `isWordLike`: ICU's rule status at or
     * above `WORD_NONE_LIMIT` (letters, numbers, kana, ideographs).
     *
     * @return list<string>
     */
    private static function segment(string $text): array
    {
        $iterator = \IntlBreakIterator::createWordInstance();
        $iterator->setText($text);
        $words = [];
        $start = $iterator->first();

        for ($end = $iterator->next(); $end !== \IntlBreakIterator::DONE; $start = $end, $end = $iterator->next()) {
            if ($iterator->getRuleStatus() >= \IntlBreakIterator::WORD_NONE_LIMIT) {
                $words[] = substr($text, $start, $end - $start);
            }
        }

        return $words;
    }

    /** Naive singular form, so `issues` matches `issue` and `searches` matches `search`. */
    private static function stem(string $term): string
    {
        $length = strlen($term);

        if ($length > 4 && str_ends_with($term, 'ies')) {
            return substr($term, 0, -3) . 'y';
        }

        if ($length > 4 && preg_match('/(ches|shes|sses|xes|zes)$/', $term) === 1) {
            return substr($term, 0, -2);
        }

        if ($length > 3 && str_ends_with($term, 's') && !str_ends_with($term, 'ss')) {
            return substr($term, 0, -1);
        }

        return $term;
    }

    /**
     * Search text of a tool — upstream's `createToolSearchDocument()`: `text` is the description and
     * the schema's descriptions and property names, `namespaceText` the server's name and description
     * (pig's namespace description is the server's instructions). The ranker reads the name from
     * `name`, and weighs the three apart.
     *
     * @param array<string, mixed> $tool an MCP tool as listed, plus the pig name it goes by
     * @return array{name: string, text: string, namespaceText: string}
     */
    public static function document(string $name, array $tool, ?string $namespace = null, ?string $namespaceDescription = null): array
    {
        $parts = [(string) ($tool['description'] ?? '')];
        self::schemaText($tool['inputSchema'] ?? null, $parts);

        return [
            'name' => $name,
            'text' => implode(' ', array_filter($parts, static fn (string $p): bool => trim($p) !== '')),
            'namespaceText' => $namespace !== null ? "{$namespace} " . ($namespaceDescription ?? '') : '',
        ];
    }

    /** @param list<string> $parts */
    private static function schemaText(mixed $schema, array &$parts): void
    {
        if (!is_array($schema) || array_is_list($schema)) {
            return;
        }

        if (is_string($schema['description'] ?? null)) {
            $parts[] = $schema['description'];
        }

        if (is_array($schema['properties'] ?? null) && !array_is_list($schema['properties'])) {
            foreach ($schema['properties'] as $property => $definition) {
                $parts[] = (string) $property;
                self::schemaText($definition, $parts);
            }
        }

        self::schemaText($schema['items'] ?? null, $parts);

        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                foreach ($schema[$key] as $variant) {
                    self::schemaText($variant, $parts);
                }
            }
        }
    }

    /**
     * Okapi BM25 over the description, with the name and the namespace as fields of their own —
     * upstream's `Bm25Ranker` (k1 = 1.2, b = 0.75):
     * - a tool that matches more of the query's terms, in any field, ranks above one that matches
     *   fewer, whatever their scores: for `screenshot window`, a tool whose description has both
     *   words beats `window`, whose name has one;
     * - a query term in the name scores more than any number of mentions in the description;
     * - a tool whose whole local name is in the query (`app` in `peekaboo app` for
     *   `mcp__peekaboo__app`) gets a bonus worth one more match of every query term;
     * - a query that *names* a tool — its full name, or what follows a `__` (`thread_read` for
     *   `mcp__app__thread_read`) — ranks that tool first, before coverage (upstream #11331).
     *
     * Only the description is length-normalized, so a long name or namespace does not lower a
     * tool's score. Ties keep document order.
     *
     * @param list<array{name: string, text: string, namespaceText?: string}> $documents
     * @return list<array{name: string, score: float}>
     */
    public static function rank(string $query, array $documents, int $limit, float $k1 = 1.2, float $b = 0.75): array
    {
        $queryTerms = array_values(array_unique(self::tokenize($query)));

        if ($queryTerms === [] || $documents === [] || $limit <= 0) {
            return [];
        }

        $indexed = [];

        foreach ($documents as $document) {
            $counts = [];

            foreach (self::tokenize($document['text']) as $term) {
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }

            $segments = explode('__', $document['name']);
            $indexed[] = [
                'name' => $document['name'],
                'nameTerms' => array_flip(self::tokenize($document['name'])),
                'localNameTerms' => array_values(array_unique(self::tokenize($segments[count($segments) - 1]))),
                'counts' => $counts,
                'length' => array_sum($counts),
                'namespaceTerms' => array_flip(self::tokenize($document['namespaceText'] ?? '')),
            ];
        }

        $count = count($indexed);
        $averageLength = (array_sum(array_column($indexed, 'length')) / $count) ?: 1;
        $idf = [];

        foreach ($queryTerms as $term) {
            $frequency = count(array_filter($indexed, static fn (array $d): bool => isset($d['nameTerms'][$term]) || isset($d['counts'][$term]) || isset($d['namespaceTerms'][$term])));
            $idf[$term] = log(1 + ($count - $frequency + 0.5) / ($frequency + 0.5));
        }

        $fullNameBonus = array_sum($idf);
        $nameQuery = self::normalizeName($query);
        $matches = [];

        foreach ($indexed as $document) {
            $norm = $k1 * (1 - $b + ($b * $document['length']) / $averageLength);
            $score = 0.0;
            $covered = 0;

            foreach ($queryTerms as $term) {
                $occurrences = $document['counts'][$term] ?? 0;
                $weight = (isset($document['nameTerms'][$term]) ? self::NAME_WEIGHT : 0.0)
                    + (isset($document['namespaceTerms'][$term]) ? self::NAMESPACE_WEIGHT : 0.0)
                    + ($occurrences * ($k1 + 1)) / ($occurrences + $norm);

                if ($weight > 0) {
                    $covered++;
                }

                $score += $idf[$term] * $weight;
            }

            if ($score === 0.0) {
                continue;
            }

            $local = $document['localNameTerms'];

            if ($local !== [] && array_diff($local, $queryTerms) === []) {
                $score += $fullNameBonus;
            }

            $matches[] = ['name' => $document['name'], 'score' => $score, 'covered' => $covered, 'exact' => self::names($nameQuery, $document['name'])];
        }

        // usort is stable: equal keys keep document order, as upstream's sort does.
        usort($matches, static fn (array $x, array $y): int => [$y['exact'], $y['covered'], $y['score']] <=> [$x['exact'], $x['covered'], $x['score']]);

        return array_map(
            static fn (array $match): array => ['name' => $match['name'], 'score' => $match['score']],
            array_slice($matches, 0, $limit),
        );
    }

    /** Lowercased, with what a script identifier cannot hold as `_`: `dev-app` is `dev_app`. */
    private static function normalizeName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_$]/', '_', strtolower(trim($name)));
    }

    /** Whether $query (normalized) is the tool's name, whole or after a `__`. */
    private static function names(string $query, string $name): bool
    {
        $normalized = self::normalizeName($name);

        return $normalized === $query || str_ends_with($normalized, "__{$query}");
    }

    /**
     * The `tool_search` description, listing the servers whose tools can be found.
     *
     * @param list<array{name: string, description: ?string}> $sources
     */
    public static function description(array $sources = []): string
    {
        if ($sources === []) {
            $listed = 'None currently enabled.';
        } else {
            $lines = [];

            foreach ($sources as $source) {
                $first = trim((string) strtok(trim((string) ($source['description'] ?? '')), "\r\n"));
                $lines[] = $first !== '' ? "- {$source['name']}: {$first}" : "- {$source['name']}";
            }

            $listed = implode("\n", $lines);
        }

        $name = self::TOOL_NAME;

        return "# Tool discovery\n\nSearches over deferred tool metadata with BM25 and exposes matching tools for the next model call.\n\n"
            . "You have access to tools from the following sources:\n{$listed}\n\n"
            . "Some of the tools may not have been provided to you upfront, and you should use this tool (`{$name}`) to search for the required tools. "
            . "For MCP tool discovery, always use `{$name}`.";
    }

    /** @return array<string, mixed> */
    public static function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query for deferred tools.'],
                'limit' => ['type' => 'number', 'description' => 'Maximum number of tools to return. Defaults to ' . self::DEFAULT_LIMIT . '.'],
            ],
            'required' => ['query'],
        ];
    }
}
