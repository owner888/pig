<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * What is waiting to be sent changed — upstream's `queue_update`, announced from `_emitQueueUpdate()`.
 *
 * The whole of both queues, not the change: a host showing "2 queued" redraws from this and needs
 * no state of its own. Announced when something is queued, when the queue is cleared, and when a
 * queued message is delivered and leaves it.
 */
final readonly class QueueUpdateEvent implements AgentEvent
{
    /**
     * @param list<string> $steering the steering messages, oldest first, as they were typed
     * @param list<string> $followUp the follow-ups, likewise
     */
    public function __construct(
        public array $steering,
        public array $followUp,
    ) {
    }
}
