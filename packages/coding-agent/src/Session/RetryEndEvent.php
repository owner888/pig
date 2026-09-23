<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The retrying is over, one way or the other.
 *
 * `succeeded` is false for both of the ways it can fail — the attempts ran out, or somebody
 * pressed escape — and `error` says which. Always sent, so anything that drew a "retrying"
 * line on `RetryStartEvent` has something to take it down on.
 */
final readonly class RetryEndEvent implements AgentEvent
{
    public function __construct(
        public bool $succeeded,
        public int $attempts,
        /** Why it stopped, when it did not succeed. */
        public ?string $error = null,
    ) {
    }
}
