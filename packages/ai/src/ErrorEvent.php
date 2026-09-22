<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The response failed or was aborted. Exactly one DoneEvent or ErrorEvent ends
 * every stream.
 */
final readonly class ErrorEvent implements AssistantMessageEvent
{
    /**
     * @param StopReason       $reason Error or Aborted
     * @param AssistantMessage $error  a complete message carrying the failing stopReason and
     *        errorMessage; upstream names this field `error`, not `message`
     */
    public function __construct(
        public StopReason $reason,
        public AssistantMessage $error,
    ) {
    }
}
