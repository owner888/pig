<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * Where a compaction cuts — upstream's `CutPointResult` (`compaction/compaction.ts`).
 *
 * Indices into whatever list of entries was cut: `Compaction::findCutPoint()`'s session entries,
 * or the projected entries `prepareCompaction()` cuts.
 */
final readonly class CutPointResult
{
    /**
     * @param int  $firstKeptEntryIndex the first entry kept
     * @param int  $turnStartIndex      the entry that starts the turn being split, or -1
     * @param bool $isSplitTurn         the cut is inside a turn rather than at its start
     */
    public function __construct(
        public int $firstKeptEntryIndex,
        public int $turnStartIndex,
        public bool $isSplitTurn,
    ) {
    }
}
