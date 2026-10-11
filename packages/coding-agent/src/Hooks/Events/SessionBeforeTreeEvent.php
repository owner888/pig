<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Async\AbortSignal;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * About to jump to another point in the session tree — `/tree`. Upstream's
 * `SessionBeforeTreeEvent`.
 *
 * Fired whether or not a summary was asked for (`$preparation->userWantsSummary`). A handler
 * answers with a `SessionBeforeTreeResult`: `cancel` to stop the jump, `summary` to supply the
 * handover itself (used only when a summary was asked for), or new `customInstructions`,
 * `replaceInstructions` or `label` for the default summariser. `$signal` is aborted when the
 * summary is.
 */
final readonly class SessionBeforeTreeEvent implements HookEvent
{
    public function __construct(
        public TreePreparation $preparation,
        public AbortSignal $signal,
    ) {
    }

    public function type(): string
    {
        return 'session_before_tree';
    }
}
