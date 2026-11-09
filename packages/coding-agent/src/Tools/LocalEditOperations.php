<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/** `EditOperations` on this machine's file system. */
final readonly class LocalEditOperations implements EditOperations
{
    #[\Override]
    public function readFile(string $absolutePath): string
    {
        [$raw, $reason] = FileAccessError::capture(static fn (): string|false => file_get_contents($absolutePath));

        if ($raw === false) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not read {$absolutePath}", $reason);
        }

        return $raw;
    }

    #[\Override]
    public function writeFile(string $absolutePath, string $content): void
    {
        [$written, $reason] = FileAccessError::capture(static fn (): int|false => file_put_contents($absolutePath, $content));

        if ($written === false) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not write {$absolutePath}", $reason);
        }
    }

    #[\Override]
    public function access(string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "File not found: {$absolutePath}");
        }

        if (!is_readable($absolutePath) || !is_writable($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_ALLOWED, "Not readable and writable: {$absolutePath}");
        }
    }
}
