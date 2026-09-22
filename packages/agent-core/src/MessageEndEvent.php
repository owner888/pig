<?php

declare(strict_types=1);

namespace Pig\Agent;

/** A message is final and will not change again. */
final readonly class MessageEndEvent implements AgentEvent
{
    public function __construct(
        public mixed $message,
    ) {
    }
}
