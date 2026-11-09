<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Upstream's `GrepOperations` (`tools/grep.ts`): one question, whether the path is a directory.
 * `rg` itself still runs on this machine, as upstream's does. `LocalGrepOperations` is the default.
 */
interface GrepOperations
{
    /** @throws FileAccessError `NOT_FOUND` when nothing is there */
    public function isDirectory(string $absolutePath): bool;
}
