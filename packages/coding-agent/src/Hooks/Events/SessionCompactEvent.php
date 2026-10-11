<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Session\CompactionSummary;

/**
 * The conversation has been compacted — upstream's `SessionCompactEvent`.
 *
 * `$compactionEntry` is the entry as it was written. `$fromExtension` says its content came from a
 * `session_before_compact` handler rather than from the model, so a hook that writes its own does
 * not then react to its own work.
 */
final readonly class SessionCompactEvent implements HookEvent
{
    /**
     * @param 'manual'|'threshold'|'overflow' $reason    what started it
     * @param bool                             $willRetry the turn that overflowed is sent again now
     */
    public function __construct(
        public CompactionSummary $compactionEntry,
        public bool $fromExtension,
        public string $reason,
        public bool $willRetry,
    ) {
    }

    public function type(): string
    {
        return 'session_compact';
    }
}
