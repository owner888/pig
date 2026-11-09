<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

/** One tool that loaded, and the file it came from. */
final readonly class RegisteredTool
{
    public function __construct(
        /** As it was written in the settings, or as it was found on disk. */
        public string $path,
        public string $resolvedPath,
        public ToolDefinition $tool,
    ) {
    }
}
