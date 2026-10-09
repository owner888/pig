<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * A package that is a directory (or one extension file) already on this machine — loaded from
 * where it is, never copied. Upstream's `LocalSource`.
 */
final readonly class LocalSource
{
    /** @param string $path as written in the settings; relative to the settings file's directory */
    public function __construct(public string $path)
    {
    }
}
