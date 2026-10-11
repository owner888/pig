<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The summarising is over — upstream's `compaction_end`, for all three reasons.
 *
 * `result` is upstream's `CompactionResult`, with `estimatedTokensAfter` filled in from the rebuilt
 * conversation; null when it was cancelled or failed. `aborted` is escape or a hook's `cancel`, which
 * is somebody deciding and not an error, so `errorMessage` is null then. `errorMessage` is upstream's
 * wording by reason: `Compaction failed: …`, `Auto-compaction failed: …`,
 * `Context overflow recovery failed: …`.
 *
 * `willRetry` is the useful one for a loader: after a successful overflow summary the session sends
 * the turn again by itself, so a UI that took its loader down here would put it straight back up.
 *
 * `summary` is pig's, and in-process only — `RpcEvents` does not put it on the wire, where upstream's
 * `result` is the shape. It is the entry as the conversation now holds it, which is what the terminal
 * draws (`CompactionComponent`) and what the result alone cannot rebuild: the replaced-message count
 * and the timestamp are the projection's.
 */
final readonly class CompactionEndEvent implements AgentEvent
{
    /** @param 'manual'|'threshold'|'overflow' $reason */
    public function __construct(
        public string $reason,
        public ?CompactionResult $result,
        public bool $aborted,
        public bool $willRetry,
        public ?string $errorMessage = null,
        public ?CompactionSummary $summary = null,
    ) {
    }
}
