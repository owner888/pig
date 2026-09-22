<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The reasoning block at `$contentIndex` is closed; `$content` is the whole of it. */
final readonly class ThinkingEndEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public string $content,
        public AssistantMessage $partial,
    ) {
    }
}
