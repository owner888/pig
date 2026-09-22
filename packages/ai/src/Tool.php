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
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {
    }
}
