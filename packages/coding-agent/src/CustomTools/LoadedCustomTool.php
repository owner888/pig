<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/** One tool that loaded, and the file it came from. */
final readonly class LoadedCustomTool
{
    public function __construct(
        /** As it was written in the settings, or as it was found on disk. */
        public string $path,
        public string $resolvedPath,
        public CustomTool $tool,
    ) {
    }
}
