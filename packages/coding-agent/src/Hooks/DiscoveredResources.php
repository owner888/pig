<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

/**
 * What `resources_discover` handlers answered, each path paired with the extension that gave
 * it, so a warning about one can say whose it is.
 *
 * @phpstan-type Found array{path: string, extensionPath: string}
 */
final readonly class DiscoveredResources
{
    /**
     * @param list<Found> $skillPaths
     * @param list<Found> $promptPaths
     * @param list<Found> $themePaths
     */
    public function __construct(
        public array $skillPaths = [],
        public array $promptPaths = [],
        public array $themePaths = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->skillPaths === [] && $this->promptPaths === [] && $this->themePaths === [];
    }
}
