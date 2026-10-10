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

    /** Lowercase terms, split at camelCase boundaries and non-alphanumerics, without stop words. */
    public static function tokenize(string $text): array
    {
        $text = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        $text = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text);
        $terms = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];

        foreach ($terms as $term) {
            if (!in_array($term, self::STOP_WORDS, true)) {
                $out[] = self::stem($term);
            }
        }

        return $out;
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
     * Search text of a tool: the name, the name with `_` as spaces, the description, schema
     * descriptions and property names, and the server's name.
     *
     * @param array<string, mixed> $tool an MCP tool as listed, plus the pig name it goes by
     * @return array{name: string, text: string}
     */
    public static function document(string $name, array $tool, ?string $namespace = null, ?string $namespaceDescription = null): array
    {
        $parts = [$name, str_replace('_', ' ', $name), (string) ($tool['description'] ?? '')];
        self::schemaText($tool['inputSchema'] ?? null, $parts);

        if ($namespace !== null) {
            $parts[] = $namespace;
            $parts[] = $namespaceDescription ?? '';
        }

        return ['name' => $name, 'text' => implode(' ', array_filter($parts, static fn (string $p): bool => trim($p) !== ''))];
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
     * Okapi BM25 with the usual parameters (k1 = 1.2, b = 0.75). Ties keep document order.
     *
     * @param list<array{name: string, text: string}> $documents
     * @return list<array{name: string, score: float}>
     */
    public static function rank(string $query, array $documents, int $limit, float $k1 = 1.2, float $b = 0.75): array
    {
        $queryTerms = array_values(array_unique(self::tokenize($query)));

        if ($queryTerms === [] || $documents === [] || $limit <= 0) {
            return [];
        }

        $termCounts = [];
        $lengths = [];

        foreach ($documents as $document) {
            $counts = [];

            foreach (self::tokenize($document['text']) as $term) {
                $counts[$term] = ($counts[$term] ?? 0) + 1;
            }

            $termCounts[] = $counts;
            $lengths[] = array_sum($counts);
        }

        $count = count($documents);
        $averageLength = (array_sum($lengths) / $count) ?: 1;

        $idf = [];

        foreach ($queryTerms as $term) {
            $frequency = count(array_filter($termCounts, static fn (array $c): bool => isset($c[$term])));
            $idf[$term] = log(1 + ($count - $frequency + 0.5) / ($frequency + 0.5));
        }

        $matches = [];

        foreach ($documents as $index => $document) {
            $score = 0.0;

            foreach ($queryTerms as $term) {
                $occurrences = $termCounts[$index][$term] ?? 0;

                if ($occurrences === 0) {
                    continue;
                }

                $norm = $k1 * (1 - $b + ($b * $lengths[$index]) / $averageLength);
                $score += $idf[$term] * (($occurrences * ($k1 + 1)) / ($occurrences + $norm));
            }

            if ($score > 0) {
                $matches[] = ['name' => $document['name'], 'score' => $score];
            }
        }

        // usort is stable: equal scores keep document order, as upstream's sort does.
        usort($matches, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return array_slice($matches, 0, $limit);
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
