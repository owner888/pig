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
     * @param string|null          $namespace upstream's `namespace`: "OpenAI Responses namespace
     *        for calls to dynamically loaded or namespaced tools" — read off the `function_call` /
     *        `custom_tool_call` item and sent back on it, to the same model only
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $thoughtSignature = null,
        public ?string $namespace = null,
    ) {
    }
}
