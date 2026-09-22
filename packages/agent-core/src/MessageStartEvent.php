<?php

declare(strict_types=1);

namespace Pig\Agent;

/** A message has been added to the conversation. */
final readonly class MessageStartEvent implements AgentEvent
{
    public function __construct(
        public mixed $message,
    ) {
    }
}
