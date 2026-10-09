<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The prompt is over: no retry, recovery compaction or queued message will carry it on.
 *
 * Upstream's `agent_settled` session event, sent once per prompt from `emitAgentSettled()` after
 * the hooks have heard theirs. `AgentEndEvent` is the end of a *run*, and a prompt can be several.
 */
final readonly class AgentSettledEvent implements AgentEvent
{
    public function __construct(
        /** Whether the run ended because it was aborted, for example with Escape. */
        public bool $aborted,
    ) {
    }
}
