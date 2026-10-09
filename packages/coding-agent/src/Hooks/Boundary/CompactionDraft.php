<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/**
 * A compaction the extension wrote itself. `firstKeptEntryId` names where the kept part of the
 * conversation starts; null keeps nothing before the summary.
 */
final readonly class CompactionDraft implements SessionBoundaryDraft
{
    public function __construct(
        public string $summary,
        public ?string $firstKeptEntryId = null,
    ) {
    }
}
