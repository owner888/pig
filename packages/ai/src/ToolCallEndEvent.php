<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The tool call at `$contentIndex` is closed and its arguments are parsed. */
final readonly class ToolCallEndEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public ToolCall $toolCall,
        public AssistantMessage $partial,
    ) {
    }
}
