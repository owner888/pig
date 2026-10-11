<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * Everything worked out before the summariser is asked — upstream's `CompactionPreparation`
 * (`compaction/compaction.ts`), what `Compaction::prepareCompaction()` returns and what
 * `session_before_compact` hands an extension as `preparation`.
 *
 * An extension that writes its own summary reads it from here and answers with a
 * `CompactionResult` naming `$firstKeptEntryId` and `$tokensBefore`; one that wants pig's own
 * summary with something changed can call `Compaction::compact()` on it.
 */
final readonly class CompactionPreparation
{
    /**
     * @param string            $firstKeptEntryId    the entry the kept part of the conversation starts at
     * @param list<mixed>       $messagesToSummarize what will be summarised and dropped
     * @param list<mixed>       $turnPrefixMessages  the start of a turn the cut lands inside, summarised
     *                                               separately (only when `$isSplitTurn`)
     * @param bool              $isSplitTurn         the cut is in the middle of a turn, not at its start
     * @param int               $tokensBefore        the projected context's size before compacting
     * @param string|null       $previousSummary     the last compaction's summary, which this one updates
     * @param FileOperations    $fileOps             what the summarised messages did to which files,
     *                                               the last default compaction's lists included
     * @param CompactionSettings $settings           the effective settings, model overrides applied
     */
    public function __construct(
        public string $firstKeptEntryId,
        public array $messagesToSummarize,
        public array $turnPrefixMessages,
        public bool $isSplitTurn,
        public int $tokensBefore,
        public ?string $previousSummary,
        public FileOperations $fileOps,
        public CompactionSettings $settings,
    ) {
    }
}
