<?php

declare(strict_types=1);

namespace Pig\Ai;

/** A reasoning block opened at `$contentIndex`. */
final readonly class ThinkingStartEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public AssistantMessage $partial,
    ) {
    }
}
