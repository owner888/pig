<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * Where a resolved resource came from — upstream's `PathMetadata`.
 *
 * `source` is the package's source string for a package resource, or `local` / `auto` / `builtin`
 * for a top-level one; `scope` is whose settings named it; `origin` says which of the two it is.
 * The ranks in `ResolvedPaths::rank()` read these three to decide who wins a name collision.
 */
final readonly class PathMetadata
{
    /**
     * @param 'user'|'project'|'temporary' $scope
     * @param 'package'|'top-level'        $origin
     */
    public function __construct(
        public string $source,
        public string $scope,
        public string $origin,
        public ?string $baseDir = null,
        public ?string $packageRoot = null,
    ) {
    }

    public function with(?string $baseDir = null, ?string $packageRoot = null): self
    {
        return new self($this->source, $this->scope, $this->origin, $baseDir ?? $this->baseDir, $packageRoot ?? $this->packageRoot);
    }
}
