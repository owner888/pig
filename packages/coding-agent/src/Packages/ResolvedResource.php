<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/** One file a loader may load, and whether the settings left it on — upstream's `ResolvedResource`. */
final readonly class ResolvedResource
{
    public function __construct(
        public string $path,
        public bool $enabled,
        public PathMetadata $metadata,
    ) {
    }
}
