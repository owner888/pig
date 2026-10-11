<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What of an abandoned branch will be summarised — upstream's `BranchPreparation`
 * (`compaction/branch-summarization.ts`), what `BranchSummarization::prepareBranchEntries()`
 * returns.
 */
final readonly class BranchPreparation
{
    /**
     * @param list<mixed>    $messages    what fits the budget, oldest first
     * @param FileOperations $fileOps     what those messages (and nested pig branch summaries) did to files
     * @param int            $totalTokens the estimated size of `$messages`
     */
    public function __construct(
        public array $messages,
        public FileOperations $fileOps,
        public int $totalTokens,
    ) {
    }
}
