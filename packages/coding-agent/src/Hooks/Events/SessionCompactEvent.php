<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Session\CompactionSummary;

/**
 * The conversation has been compacted, and this is the summary that replaced it.
 *
 * `$fromHook` says the summary came from a `session_before_compact` handler rather than
 * from the model, so a hook that writes its own does not then react to its own work.
 */
final readonly class SessionCompactEvent implements HookEvent
{
    public function __construct(
        public CompactionSummary $summary,
        public bool $fromHook = false,
    ) {
    }

    public function type(): string
    {
        return 'session_compact';
    }
}
