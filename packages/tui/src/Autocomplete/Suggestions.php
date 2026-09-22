<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

/**
 * What to offer, and what the offer replaces.
 *
 * `prefix` is the text already typed that a chosen item stands in for — `"/mod"`, `"src/"`,
 * `"@read"`. Applying a completion cuts exactly that many characters back from the cursor,
 * so it has to be the literal text, not a description of it.
 */
final readonly class Suggestions
{
    /** @param non-empty-list<AutocompleteItem> $items */
    public function __construct(
        public array $items,
        public string $prefix,
    ) {
    }
}
