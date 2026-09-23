<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The session is summarising itself, without having been asked.
 *
 * Only for the compaction the session starts on its own, after a provider said the prompt was
 * too long. `/compact` and the check before a turn are the mode's doing and the mode already
 * knows it is happening.
 */
final readonly class AutoCompactionStartEvent implements AgentEvent
{
    public function __construct(
        /** What the provider said, which is the evidence that this was needed. */
        public string $error,
    ) {
    }
}
