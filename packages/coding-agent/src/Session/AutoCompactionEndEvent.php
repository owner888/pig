<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * The summarising is over.
 *
 * `willRetry` is the useful one: after a successful summary the session sends the turn again
 * by itself, so a UI that took its loader down here would put it straight back up.
 */
final readonly class AutoCompactionEndEvent implements AgentEvent
{
    public function __construct(
        public bool $succeeded,
        public bool $willRetry,
        public ?CompactionSummary $summary = null,
        public ?string $error = null,
    ) {
    }
}
