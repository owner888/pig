<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * Where `PackageManager::resolve()` collects before ranking — upstream's `ResourceAccumulator`.
 * The first mention of a path wins, so a filter's verdict on a file is not overwritten by a
 * later, broader one.
 */
final class ResourceAccumulator
{
    /** @var array<string, array<string, array{PathMetadata, bool}>> type => path => [metadata, enabled] */
    private array $entries = ['extensions' => [], 'skills' => [], 'prompts' => [], 'themes' => []];

    public function add(string $type, string $path, PathMetadata $metadata, bool $enabled): void
    {
        if ($path === '' || isset($this->entries[$type][$path])) {
            return;
        }

        $this->entries[$type][$path] = [$metadata, $enabled];
    }

    /** Ranked by precedence, then deduplicated by real path — upstream's `toResolvedPaths()`. */
    public function toResolvedPaths(): ResolvedPaths
    {
        $lists = [];

        foreach (ResolvedPaths::TYPES as $type) {
            $resolved = [];

            foreach ($this->entries[$type] as $path => [$metadata, $enabled]) {
                $resolved[] = new ResolvedResource($path, $enabled, $metadata);
            }

            usort($resolved, static fn (ResolvedResource $a, ResolvedResource $b): int => ResolvedPaths::rank($a->metadata) <=> ResolvedPaths::rank($b->metadata));

            $seen = [];
            $lists[$type] = array_values(array_filter($resolved, static function (ResolvedResource $resource) use (&$seen): bool {
                $canonical = realpath($resource->path) ?: $resource->path;

                if (isset($seen[$canonical])) {
                    return false;
                }

                $seen[$canonical] = true;

                return true;
            }));
        }

        return new ResolvedPaths($lists['extensions'], $lists['skills'], $lists['prompts'], $lists['themes']);
    }
}
