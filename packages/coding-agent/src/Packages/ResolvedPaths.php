<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * What `PackageManager::resolve()` hands the four loaders — upstream's `ResolvedPaths`.
 *
 * Each list is sorted by precedence and deduplicated by real path, so a loader that takes the
 * first of two resources with one name takes the right one.
 */
final readonly class ResolvedPaths
{
    public const array TYPES = ['extensions', 'skills', 'prompts', 'themes'];

    /**
     * @param list<ResolvedResource> $extensions
     * @param list<ResolvedResource> $skills
     * @param list<ResolvedResource> $prompts
     * @param list<ResolvedResource> $themes
     * @param list<PackageInstallFailure> $installFailures configured packages left out because
     *        installing them failed — for the caller to warn about, as nothing else did
     */
    public function __construct(
        public array $extensions = [],
        public array $skills = [],
        public array $prompts = [],
        public array $themes = [],
        public array $installFailures = [],
    ) {
    }

    /** @param list<PackageInstallFailure> $failures */
    public function withInstallFailures(array $failures): self
    {
        return new self($this->extensions, $this->skills, $this->prompts, $this->themes, $failures);
    }

    /**
     * Without the packages one of whose extensions is named — every resource they bring, their
     * skills, prompts and themes with the extension. `pig web`'s custom session: an extension left
     * unticked there is a package left out, so nothing of it reaches the conversation.
     *
     * @param list<string> $extensionNames as `ExtensionLoader::nameOf()` names them
     */
    public function withoutPackagesOf(array $extensionNames): self
    {
        $sources = [];

        foreach ($this->extensions as $resource) {
            if ($resource->metadata->origin === 'package' && in_array(\Pig\CodingAgent\Extensions\ExtensionLoader::nameOf($resource->path), $extensionNames, true)) {
                $sources[$resource->metadata->source] = true;
            }
        }

        if ($sources === []) {
            return $this;
        }

        $keep = static fn (array $resources): array => array_values(array_filter(
            $resources,
            static fn (ResolvedResource $resource): bool => $resource->metadata->origin !== 'package' || !isset($sources[$resource->metadata->source]),
        ));

        return new self($keep($this->extensions), $keep($this->skills), $keep($this->prompts), $keep($this->themes), $this->installFailures);
    }

    /** @return list<ResolvedResource> */
    public function of(string $type): array
    {
        return match ($type) {
            'extensions' => $this->extensions,
            'skills' => $this->skills,
            'prompts' => $this->prompts,
            'themes' => $this->themes,
            default => throw new PackageError("Unknown resource type: {$type}"),
        };
    }

    /** The enabled paths of one type, in precedence order — what a loader usually wants. */
    public function enabled(string $type): array
    {
        $paths = [];

        foreach ($this->of($type) as $resource) {
            if ($resource->enabled) {
                $paths[] = $resource->path;
            }
        }

        return $paths;
    }

    /**
     * Upstream's `resourcePrecedenceRank()`: lower wins a name collision.
     *
     *   0  project settings entry   1  project auto-discovered
     *   2  user settings entry      3  user auto-discovered
     *   4  a package's resource     5  a built-in extension
     */
    public static function rank(PathMetadata $metadata): int
    {
        if ($metadata->source === 'builtin') {
            return 5;
        }

        if ($metadata->origin === 'package') {
            return 4;
        }

        return ($metadata->scope === 'project' ? 0 : 2) + ($metadata->source === 'local' ? 0 : 1);
    }
}
