<?php

declare(strict_types=1);

namespace Pig\Agent;

/** An assistant message grew. Only assistant messages stream, so only they update. */
final readonly class MessageUpdateEvent implements AgentEvent
{
    /**
     * @param mixed                         $message               the message as it now stands — a snapshot, not a live view
     * @param \Pig\Ai\AssistantMessageEvent $assistantMessageEvent the provider event behind it
     */
    public function __construct(
        public mixed $message,
        public \Pig\Ai\AssistantMessageEvent $assistantMessageEvent,
    ) {
    }
}
