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
     */
    public function __construct(
        public array $messages,
    ) {
    }
}
