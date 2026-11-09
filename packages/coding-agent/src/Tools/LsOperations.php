<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Upstream's `LsOperations` (`tools/ls.ts`): where `ls` looks. `LocalLsOperations` is this
 * machine's file system, the default.
 */
interface LsOperations
{
    public function exists(string $absolutePath): bool;

    /** @throws FileAccessError when nothing is there */
    public function stat(string $absolutePath): FileStat;

    /**
     * The directory's entries, without `.` and `..`, in no particular order.
     *
     * @return list<string>
     * @throws FileAccessError when it could not be read
     */
    public function readdir(string $absolutePath): array;
}
