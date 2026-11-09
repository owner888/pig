<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/** `GrepOperations` on this machine's file system. */
final readonly class LocalGrepOperations implements GrepOperations
{
    #[\Override]
    public function isDirectory(string $absolutePath): bool
    {
        if (!file_exists($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file or directory: {$absolutePath}");
        }

        return is_dir($absolutePath);
    }
}
