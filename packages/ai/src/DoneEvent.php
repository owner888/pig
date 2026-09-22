<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The response finished. Exactly one DoneEvent or ErrorEvent ends every stream. */
final readonly class DoneEvent implements AssistantMessageEvent
{
    /**
     * @param StopReason $reason Stop, Length or ToolUse — a failure arrives as ErrorEvent instead
     */
    public function __construct(
        public StopReason $reason,
        public AssistantMessage $message,
    ) {
    }
}
