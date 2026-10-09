<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\Boundary\BoundaryContextPreview;
use Pig\CodingAgent\Hooks\Boundary\SessionBoundaryDraft;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Fired when a prompt has nothing left to do by itself — no retry, no overflow compaction, nothing
 * queued — and before `agent_settled` says it is over. A handler may append entries to the session
 * (`entries`) and ask for one more provider request (`continue`), which is how an extension gets
 * the last word in before the run ends. Upstream's `AgentBeforeSettleEvent`.
 *
 * Each handler sees what the ones before it settled on, and a preview of the context as it would
 * then be sent. A `continue` the context cannot carry — one that would end on the assistant's own
 * turn with nothing queued — is refused and reported rather than sent.
 */
final readonly class AgentBeforeSettleEvent implements HookEvent
{
    /**
     * @param list<SessionBoundaryDraft> $entries
     * @param 'completed'|'aborted'|'error' $outcome how the last turn ended
     */
    public function __construct(
        public array $entries,
        public bool $continue,
        public BoundaryContextPreview $context,
        public string $outcome,
    ) {
    }

    public function type(): string
    {
        return 'agent_before_settle';
    }
}
