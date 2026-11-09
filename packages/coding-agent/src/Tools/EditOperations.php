<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Upstream's `EditOperations` (`tools/edit.ts`): where `edit` reads and writes a file.
 * `LocalEditOperations` is this machine's file system, the default.
 */
interface EditOperations
{
    /** @throws FileAccessError when it could not be read */
    public function readFile(string $absolutePath): string;

    /** @throws FileAccessError when it could not be written */
    public function writeFile(string $absolutePath, string $content): void;

    /**
     * Upstream's "Check if file is readable and writable (throw if not)".
     *
     * @throws FileAccessError `NOT_FOUND` when there is no file, `NOT_ALLOWED` when it may not be both
     */
    public function access(string $absolutePath): void;
}
