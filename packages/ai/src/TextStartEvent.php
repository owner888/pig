<?php

declare(strict_types=1);

namespace Pig\Ai;

/** A text block opened at `$contentIndex`. */
final readonly class TextStartEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public AssistantMessage $partial,
    ) {
    }
}
