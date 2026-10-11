<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * Which files a span of conversation read, wrote and edited — upstream's `FileOperations`
 * (`compaction/utils.ts`), three sets of paths.
 *
 * Mutable on purpose, as upstream's `Set`s are: `Compaction::extractFileOpsFromMessage()` adds to
 * one as it walks, and `prepareCompaction()` hands the same object on in
 * `CompactionPreparation::$fileOps` for an extension to read or add to before
 * `Compaction::compact()` turns it into lists (`Compaction::computeFileLists()`).
 *
 * The sets are arrays keyed by path, which is what makes adding the same path twice a no-op.
 */
final class FileOperations
{
    /** @var array<string, true> */
    public array $read = [];

    /** @var array<string, true> */
    public array $written = [];

    /** @var array<string, true> */
    public array $edited = [];

    public function addRead(string $path): void
    {
        $this->read[$path] = true;
    }

    public function addWritten(string $path): void
    {
        $this->written[$path] = true;
    }

    public function addEdited(string $path): void
    {
        $this->edited[$path] = true;
    }
}
