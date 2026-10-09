<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;
use Pig\Ai\Model;

/**
 * The model ran out of quota, and the turn is going again on the next one in `fallbackModels`.
 *
 * pig's own event, with no upstream counterpart — upstream's session stops at a limit error.
 * Announced after the switch and before the turn is resent, like `RetryStartEvent`, because the
 * thing a person needs explaining is why the footer suddenly names a different model.
 */
final readonly class ModelFallbackEvent implements AgentEvent
{
    public function __construct(
        public Model $from,
        public Model $to,
        /** What the provider said, as it said it. */
        public string $error,
    ) {
    }
}
