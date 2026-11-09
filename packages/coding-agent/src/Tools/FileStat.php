<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/** Upstream's `{ isDirectory: () => boolean }`, what `LsOperations::stat()` answers. */
final readonly class FileStat
{
    public function __construct(private bool $directory)
    {
    }

    public function isDirectory(): bool
    {
        return $this->directory;
    }
}
