<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Plain text. The only block both a user and the assistant may send. */
final readonly class TextContent implements AssistantContent, UserContent
{
    /**
     * @param string|null $textSignature opaque provider handle for this block —
     *        the message id, for the OpenAI responses API
     */
    public function __construct(
        public string $text,
        public ?string $textSignature = null,
    ) {
    }
}
