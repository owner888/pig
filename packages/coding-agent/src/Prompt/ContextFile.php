<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/** A project's instructions to the agent, and where they came from. */
final readonly class ContextFile
{
    public function __construct(public string $path, public string $content)
    {
    }
}
