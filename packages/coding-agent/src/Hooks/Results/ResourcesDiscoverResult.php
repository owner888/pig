<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * Directories a `resources_discover` handler adds. A relative path is resolved against the
 * extension's own directory, as upstream resolves it.
 */
final readonly class ResourcesDiscoverResult
{
    /**
     * @param list<string> $skillPaths  scanned for `SKILL.md` folders, recursively
     * @param list<string> $promptPaths scanned for `.md` prompt templates (slash commands)
     * @param list<string> $themePaths  scanned for theme `.json` files
     */
    public function __construct(
        public array $skillPaths = [],
        public array $promptPaths = [],
        public array $themePaths = [],
    ) {
    }
}
