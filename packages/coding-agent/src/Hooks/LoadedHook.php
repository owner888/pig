<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

/** One hook file that loaded, and what it asked for. */
final readonly class LoadedHook
{
    public function __construct(
        /** As it was written in the settings, or as it was found on disk. */
        public string $path,
        public string $resolvedPath,
        public HookApi $api,
    ) {
    }
}
