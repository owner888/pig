<?php

declare(strict_types=1);

namespace Pig\Ai;

/** More reasoning for the block at `$contentIndex`. */
final readonly class ThinkingDeltaEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public string $delta,
        public AssistantMessage $partial,
    ) {
    }
}
