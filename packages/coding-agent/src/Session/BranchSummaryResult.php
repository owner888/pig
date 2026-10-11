<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Usage;

/**
 * What came of asking for a branch summary — upstream's `BranchSummaryResult`
 * (`compaction/branch-summarization.ts`).
 *
 * Exactly one of three: a `$summary` (with what it cost and the file lists), `$aborted`, or an
 * `$error`. As with `CompactionResult`, the summary is the prose without the file tags, which a
 * `BranchSummary` adds itself when the model is shown it.
 */
final readonly class BranchSummaryResult
{
    /**
     * @param list<string>|null $readFiles
     * @param list<string>|null $modifiedFiles
     */
    public function __construct(
        public ?string $summary = null,
        public ?Usage $usage = null,
        public ?array $readFiles = null,
        public ?array $modifiedFiles = null,
        public bool $aborted = false,
        public ?string $error = null,
    ) {
    }
}
