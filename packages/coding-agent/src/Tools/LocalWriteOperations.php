<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/** `WriteOperations` on this machine's file system. */
final readonly class LocalWriteOperations implements WriteOperations
{
    #[\Override]
    public function writeFile(string $absolutePath, string $content): void
    {
        if (is_dir($absolutePath)) {
            throw new FileAccessError(FileAccessError::IS_DIRECTORY, "{$absolutePath} is a directory");
        }

        [$written, $reason] = FileAccessError::capture(static fn (): int|false => file_put_contents($absolutePath, $content));

        if ($written === false) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not write {$absolutePath}", $reason);
        }
    }

    #[\Override]
    public function mkdir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        [$made, $reason] = FileAccessError::capture(static fn (): bool => mkdir($dir, 0o755, true));

        // Another process may have made it in between, which is not a failure.
        if (!$made && !is_dir($dir)) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not create directory {$dir}", $reason);
        }
    }
}
