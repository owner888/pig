<?php

declare(strict_types=1);

namespace Pig\Ai;

/** More text for the block at `$contentIndex`. */
final readonly class TextDeltaEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public string $delta,
        public AssistantMessage $partial,
    ) {
    }
}
