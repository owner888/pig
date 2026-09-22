<?php

declare(strict_types=1);

namespace Pig\Ai;

/** More of the tool call's argument JSON. */
final readonly class ToolCallDeltaEvent implements AssistantMessageEvent
{
    /**
     * @param string $delta a fragment of a JSON document, not valid JSON on its own
     */
    public function __construct(
        public int $contentIndex,
        public string $delta,
        public AssistantMessage $partial,
    ) {
    }
}
