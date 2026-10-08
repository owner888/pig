<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * The run is over, for any reason: nothing left to do, an error, or an abort.
 * Emitted once, and always — the UI can rely on it to stop showing a spinner.
 */
final readonly class AgentEndEvent implements AgentEvent
{
    /**
     * @param list<mixed> $messages everything this run added to the conversation
     * @param bool        $willRetry whether the session will send the failed turn again — upstream's
     *        `agent_end` as `AgentSession` re-emits it (`{...event, willRetry}`). The loop always
     *        says false; the coding agent's session fills it in for its own listeners, so a UI can
     *        leave its spinner up across the retry.
     */
    public function __construct(
        public array $messages,
        public bool $willRetry = false,
    ) {
    }
}
