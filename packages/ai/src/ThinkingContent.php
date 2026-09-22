<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Reasoning the model emitted before its answer. */
final readonly class ThinkingContent implements AssistantContent
{
    /**
     * @param string|null $thinkingSignature opaque provider handle for this block —
     *        the reasoning item id, for the OpenAI responses API
     */
    public function __construct(
        public string $thinking,
        public ?string $thinkingSignature = null,
    ) {
    }
}
