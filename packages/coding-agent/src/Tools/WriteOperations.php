<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Upstream's `WriteOperations` (`tools/write.ts`): where `write` puts a file. `LocalWriteOperations`
 * is this machine's file system, the default. Synchronous, as `ReadOperations` says why.
 */
interface WriteOperations
{
    /** @throws FileAccessError `IS_DIRECTORY` for a directory at the path, `FAILED` otherwise */
    public function writeFile(string $absolutePath, string $content): void;

    /**
     * Create the directory and its parents; one already there is not an error.
     *
     * @throws FileAccessError when it could not be created
     */
    public function mkdir(string $dir): void;
}
