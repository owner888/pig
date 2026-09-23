<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The turn failed with something worth waiting out, and the wait has started.
 *
 * Sent before the sleep rather than after it, because the sleep is the part a person needs
 * explaining: eight seconds of nothing happening is a hang unless somebody says why.
 */
final readonly class RetryStartEvent implements AgentEvent
{
    public function __construct(
        public int $attempt,
        public int $maxAttempts,
        public float $delaySeconds,
        /** What the provider said, as it said it. */
        public string $error,
    ) {
    }
}
