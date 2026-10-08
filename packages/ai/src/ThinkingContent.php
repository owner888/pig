<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Reasoning the model emitted before its answer. */
final readonly class ThinkingContent implements AssistantContent
{
    /**
     * @param string|null $thinkingSignature opaque provider handle for this block —
     *        the reasoning item id, for the OpenAI responses API
     * @param bool|null   $redacted the provider withheld the reasoning (Anthropic's
     *        `redacted_thinking`). Then `thinking` is only a placeholder for the screen and the
     *        encrypted payload is in `thinkingSignature`, which has to go back to the same model
     *        for the conversation to continue. Null rather than false when nobody said, as
     *        upstream leaves the field out.
     */
    public function __construct(
        public string $thinking,
        public ?string $thinkingSignature = null,
        public ?bool $redacted = null,
    ) {
    }
}
