<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The session has started summarising itself — upstream's `compaction_start`.
 *
 * For every compaction, whoever started it: `manual` for `/compact`, RPC's `compact` and a hook's
 * `$ctx->compact()`, `threshold` for the check before a turn, `overflow` for the summary the session
 * runs after a provider said the request was too long. Upstream announces all three from
 * `AgentSession`, and so does pig: a host watching the stream learns that a summary is running
 * however it began, which the overflow-only `auto_compaction_start` this replaces did not tell it.
 *
 * `manual` is announced as the compaction begins, as upstream's `compact()` does. `threshold` and
 * `overflow` are announced only once `prepareCompaction()` has found something to cut — upstream's
 * `_runAutoCompaction()` — so nothing at all is said when there is nothing to cut. Every start has
 * a `CompactionEndEvent`. See `AgentSession::runAutoCompaction()` and `compactNow()`.
 */
final readonly class CompactionStartEvent implements AgentEvent
{
    /** @param 'manual'|'threshold'|'overflow' $reason */
    public function __construct(
        public string $reason,
    ) {
    }
}
