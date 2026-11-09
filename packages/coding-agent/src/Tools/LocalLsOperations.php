<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/** `LsOperations` on this machine's file system. */
final readonly class LocalLsOperations implements LsOperations
{
    #[\Override]
    public function exists(string $absolutePath): bool
    {
        return file_exists($absolutePath);
    }

    #[\Override]
    public function stat(string $absolutePath): FileStat
    {
        // A dangling link is there, as an entry, and is not a directory.
        if (!file_exists($absolutePath) && !is_link($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file or directory: {$absolutePath}");
        }

        return new FileStat(is_dir($absolutePath));
    }

    #[\Override]
    public function readdir(string $absolutePath): array
    {
        [$entries, $reason] = FileAccessError::capture(static fn (): array|false => scandir($absolutePath));

        if ($entries === false) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not read directory: {$absolutePath}", $reason);
        }

        return array_values(array_diff($entries, ['.', '..']));
    }
}
