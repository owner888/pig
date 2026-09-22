<?php

declare(strict_types=1);

namespace Pig\Ai;

/** A tool call opened at `$contentIndex`. Its name and arguments are not known yet. */
final readonly class ToolCallStartEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public AssistantMessage $partial,
    ) {
    }
}
