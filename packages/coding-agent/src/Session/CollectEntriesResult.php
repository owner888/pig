<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What moving from one point of the tree to another leaves behind — upstream's
 * `CollectEntriesResult` (`compaction/branch-summarization.ts`).
 */
final readonly class CollectEntriesResult
{
    /**
     * @param list<SessionEntry> $entries          from just below the common ancestor to the old leaf, oldest first
     * @param string|null        $commonAncestorId the deepest entry on both paths, or null when they share none
     */
    public function __construct(
        public array $entries,
        public ?string $commonAncestorId,
    ) {
    }
}
