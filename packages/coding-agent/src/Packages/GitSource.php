<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * A package that lives in a git repository — upstream's `GitSource` in `utils/git.ts`.
 *
 * `repo` is what `git clone` is given, without the ref; `host` and `path` are where the checkout
 * goes under the install root (`<root>/<host>/<path>`); `pinned` means a ref was named, so
 * `update` reconciles the checkout but never moves it.
 */
final readonly class GitSource
{
    public function __construct(
        public string $repo,
        public string $host,
        public string $path,
        public ?string $ref = null,
    ) {
    }

    public function pinned(): bool
    {
        return $this->ref !== null;
    }
}
