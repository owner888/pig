<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Cache the searchable corpus and matches while rendered transcript lines remain unchanged —
 * upstream's `AltScreenSearchIndex`.
 *
 * @phpstan-import-type SearchCorpus from AltScreenSearch
 */
final class AltScreenSearchIndex
{
    /** @var list<string>|null */
    private ?array $sourceLines = null;

    /** @var SearchCorpus|null */
    private ?array $corpus = null;

    private ?string $normalizedQuery = null;

    /** @var list<AltScreenSearchMatch> */
    private array $matches = [];

    /** @param list<string> $lines */
    public function search(array $lines, string $query): AltScreenSearchResult
    {
        // PHP compares the lists element by element, which is upstream's loop.
        $sourceChanged = $this->sourceLines !== $lines;
        if ($sourceChanged || $this->corpus === null) {
            $this->sourceLines = $lines;
            $this->corpus = AltScreenSearch::buildSearchCorpus($lines);
        }

        $normalizedQuery = AltScreenSearch::normalizeQuery($query);
        $changed = $sourceChanged || $normalizedQuery !== $this->normalizedQuery;
        if ($changed) {
            $this->normalizedQuery = $normalizedQuery;
            $this->matches = AltScreenSearch::findSearchCorpusMatches($this->corpus, $normalizedQuery);
        }

        return new AltScreenSearchResult($this->matches, $changed);
    }
}
