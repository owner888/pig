<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The assistant asking for a tool to be run. */
final readonly class ToolCall implements AssistantContent
{
    /**
     * @param array<string, mixed> $arguments      decoded JSON arguments
     * @param string|null          $thoughtSignature Google-specific opaque handle that
     *        carries thought context into the next request
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $thoughtSignature = null,
    ) {
    }
}
