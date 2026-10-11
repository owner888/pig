<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Async\AbortSignal;
use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Session\CompactionPreparation;
use Pig\CodingAgent\Session\SessionEntry;

/**
 * The context is about to be summarised — upstream's `SessionBeforeCompactEvent`.
 *
 * Fired for `/compact` (and a hook's `$ctx->compact()`), for the threshold and for overflow
 * recovery. `$preparation` is what would be summarised and kept (`Compaction::prepareCompaction()`)
 * and `$branchEntries` the whole branch, for a handler that keeps state of its own in it.
 *
 * A handler answers with a `SessionBeforeCompactResult`: `cancel` to stop the compaction, or
 * `compaction` — a `CompactionResult`, typically naming `$preparation->firstKeptEntryId` and
 * `$preparation->tokensBefore` — to write its own summary instead of asking the model. Pass
 * `$signal` to any model call made here; it is aborted when the compaction is.
 */
final readonly class SessionBeforeCompactEvent implements HookEvent
{
    /**
     * @param list<SessionEntry>               $branchEntries every entry on the current branch, root first
     * @param 'manual'|'threshold'|'overflow' $reason        what started it
     * @param bool                             $willRetry     the turn that overflowed is sent again afterwards
     */
    public function __construct(
        public CompactionPreparation $preparation,
        public array $branchEntries,
        public ?string $customInstructions,
        public string $reason,
        public bool $willRetry,
        public AbortSignal $signal,
    ) {
    }

    public function type(): string
    {
        return 'session_before_compact';
    }
}
