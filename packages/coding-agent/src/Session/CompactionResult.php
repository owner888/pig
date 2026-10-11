<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Usage;

/**
 * A compaction's content, before it is written down — upstream's `CompactionResult`
 * (`compaction/compaction.ts`).
 *
 * What `Compaction::compact()` returns, and what a `session_before_compact` handler answers with
 * (`SessionBeforeCompactResult::$compaction`) to supply its own. The session writes it as a
 * `CompactionSummary` entry: `$summary`, `$firstKeptEntryId`, `$tokensBefore` and `$usage` as given.
 *
 * **One difference from upstream, and it is in `$summary`.** Upstream's `compact()` appends the
 * `<read-files>`/`<modified-files>` tags to the summary text and also records the lists in
 * `details`. pig's entry keeps the lists apart from the prose and adds the tags when the model is
 * shown it (`CompactionSummary::toText()`), so the summary here is the prose alone and the lists
 * are `$details['readFiles']` and `$details['modifiedFiles']` — which is where the entry reads them
 * from, an extension's result included. Other keys of `$details` are not stored: pig's compaction
 * entry has nowhere for them yet.
 */
final readonly class CompactionResult
{
    /**
     * @param array{readFiles?: list<string>, modifiedFiles?: list<string>}|null $details
     */
    public function __construct(
        public string $summary,
        public string $firstKeptEntryId,
        public int $tokensBefore,
        public ?int $estimatedTokensAfter = null,
        /** What generating the summary cost, when it is known — it goes into the session's totals. */
        public ?Usage $usage = null,
        public ?array $details = null,
    ) {
    }
}
