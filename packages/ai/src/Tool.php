<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A tool as the model sees it: a name, a description, and a schema for its arguments.
 *
 * Execution lives in `Pig\Agent\AgentTool`, which adds it on top. This package only
 * describes tools to providers.
 */
final readonly class Tool
{
    /**
     * @param array<string, mixed> $parameters JSON Schema for the arguments object.
     *        Upstream builds this with typebox; here it is the decoded schema itself.
     * @param array<string, mixed>|false|null $constrainedSampling upstream's
     *        `constrainedSampling?: false | ConstrainedSamplingConfig` — e.g.
     *        `['type' => 'json_schema', 'strict' => 'prefer']` asks for strict sampling where the
     *        provider has it, and `['type' => 'grammar', 'variants' => ['openai_lark' => '…']]` (or
     *        `openai_regex`) for an OpenAI custom grammar tool (`Utils\ConstrainedSampling`). Null and
     *        false both mean no.
     * @param bool $typeBox the schema stands for one upstream builds with TypeBox — pig's built-in
     *        tools, whose upstream schemas are `Type.Object(…)`. Upstream's `validateToolArguments()`
     *        runs TypeBox's `Value.Convert` over the arguments first, and that only converts nodes
     *        TypeBox made, so it does something for the built-ins and nothing for a plain JSON
     *        schema (an extension's, an MCP server's). `Agent\ToolArguments` reads this to take the
     *        same path. Never sent to a provider.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public array|false|null $constrainedSampling = null,
        public bool $typeBox = false,
    ) {
    }
}
