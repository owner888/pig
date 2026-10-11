<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

use Closure;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\Paths;
use Pig\CodingAgent\Version;
use Pig\Tui\Process;
use stdClass;

/**
 * Packages: extensions, skills, prompts and themes installed as one unit — upstream's
 * `DefaultPackageManager`, for Composer packages, git repositories and local directories.
 *
 * Three sources, as upstream has three, with `composer:` in `npm:`'s place. A Composer package is
 * installed into one shared Composer project per scope — `<home>/composer`, `.pig/composer` for a
 * project one, `<home>/tmp/extensions/composer/<hash>` for `-e` — exactly where upstream keeps its
 * npm root, and lands in its `vendor/<vendor>/<name>`. That project's `composer.json` replaces
 * `pigagent/pig`, upstream's `--omit=peer`: a package that requires pig is not handed a second
 * copy of it, whose autoloader would otherwise win for every `Pig\` class the host had not loaded
 * yet. A git package is cloned under `<home>/git/<host>/<path>` — `.pig/git/…` for a project one
 * (`-l`), which is read only once the project is trusted — and reconciled by `update`: a pinned
 * ref is fetched and reset to, an unpinned one follows its upstream branch. A local package is
 * loaded from where it is.
 *
 * pig runs no package manager inside a git or local package. Upstream runs `npm install` in a
 * cloned package that has a `package.json`; here such a package ships its own `vendor/`, which
 * `ExtensionLoader` requires when it is there, and `Pig\*` comes from the host. A Composer
 * package's libraries are the shared project's `vendor/`, which `ExtensionLoader` finds by walking
 * up from the extension.
 *
 * `resolve()` reads the `packages` of both scopes, reconciles them by identity (a project entry
 * replaces the user's; with `autoload: false` it is a delta over it), installs what is missing
 * when allowed to, and hands back every resource with its metadata, ranked. The other half of
 * upstream's `resolve()` — the top-level `skills` / `prompts` / `themes` settings and the
 * auto-discovered directories — is `resolveLocalResources()`, kept apart so `pig config` (which
 * switches package resources) and a caller that must not install anything can each have the half
 * they need; the top-level `extensions` setting stays `ExtensionLoader`'s. A package's resources
 * rank *after* the local ones (`ResolvedPaths::rank()`), which is upstream's order too.
 */
final class PackageManager
{
    /** Upstream's `NETWORK_TIMEOUT_MS`, for the commands that reach a remote. */
    private const float NETWORK_TIMEOUT = 10.0;

    /** A clone or a fetch of something large: long, and still not forever. */
    private const float CLONE_TIMEOUT = 600.0;

    /** @var (Closure(string, string, string, ?string): void)|null type, action, source, message */
    private ?Closure $onProgress = null;

    public function __construct(
        private readonly string $cwd,
        private readonly Settings $settings,
        private readonly ?string $home = null,
    ) {
    }

    /**
     * Told what is happening, for a CLI that prints it — upstream's `setProgressCallback()`.
     *
     * @param (Closure(string, string, string, ?string): void)|null $callback type (`start` /
     *        `complete` / `error`), action (`install` / `remove` / `update` / `pull`), source, message
     */
    public function setProgressCallback(?Closure $callback): void
    {
        $this->onProgress = $callback;
    }

    // ---- resolving ------------------------------------------------------------------------------

    /**
     * Every resource the configured packages provide — upstream's `resolve()`.
     *
     * @param (Closure(string): 'install'|'skip'|'error')|null $onMissing asked about a package
     *        that is not installed; with no callback it is installed. Offline, it is skipped.
     */
    public function resolve(?Closure $onMissing = null): ResolvedPaths
    {
        $all = [];

        foreach ($this->settings->packages('project') as $package) {
            $all[] = ['package' => $package, 'scope' => 'project'];
        }

        foreach ($this->settings->packages('user') as $package) {
            $all[] = ['package' => $package, 'scope' => 'user'];
        }

        $accumulator = new ResourceAccumulator();
        $this->resolvePackageSources($this->dedupe($all), $accumulator, $onMissing);

        return $accumulator->toResolvedPaths();
    }

    /**
     * Resources from sources named on the command line (`-e`), not in any settings — upstream's
     * `resolveExtensionSources()`. Temporary ones are cloned under `<home>/tmp/extensions`.
     *
     * @param list<string> $sources
     */
    public function resolveExtensionSources(array $sources, bool $local = false, bool $temporary = false): ResolvedPaths
    {
        $scope = $temporary ? 'temporary' : ($local ? 'project' : 'user');
        $accumulator = new ResourceAccumulator();
        $entries = [];

        foreach ($sources as $source) {
            $entries[] = ['package' => $source, 'scope' => $scope];
        }

        $this->resolvePackageSources($entries, $accumulator, null);

        return $accumulator->toResolvedPaths();
    }

    /**
     * The skills, prompt templates and themes the settings and the conventional directories give —
     * the second half of upstream's `resolve()`: `resolveLocalEntries()` over each scope's
     * `skills` / `prompts` / `themes` setting (project first, so a project's resource wins a name),
     * then `addAutoDiscoveredResources()`. Ranked as upstream ranks them (`ResolvedPaths::rank()`):
     * project setting, project directory, user setting, user directory.
     *
     * The directories are upstream's: `<agent-dir>/skills|prompts|themes`, the project's
     * `.pig/skills|prompts|themes` once it is trusted, `~/.agents/skills`, and — trusted projects
     * only — `.agents/skills` in the working directory and each ancestor up to the repository root.
     * A conventional prompt or theme directory loads its direct children only; a directory named in
     * a setting is walked (`ResourceDiscovery::collect()`). Every auto-discovered file answers to
     * the same scope's `!pattern` / `+path` / `-path` entries (`isEnabledByOverrides()`).
     *
     * Not here, and why: `extensions` stays `ExtensionLoader`'s, which reads the setting and the
     * directories itself, so a top-level extension is not loaded twice; and nothing is installed —
     * this reads only what is on disk.
     *
     * @param bool|null $projectTrusted whether the project's half is read; the settings' own answer
     *        when null
     */
    public function resolveLocalResources(?bool $projectTrusted = null): ResolvedPaths
    {
        $projectTrusted ??= $this->settings->isProjectTrusted();
        $accumulator = new ResourceAccumulator();
        $globalBaseDir = $this->home();
        $projectBaseDir = rtrim($this->cwd, '/') . '/.pig';

        foreach (Settings::RESOURCE_LISTS as $type) {
            if ($projectTrusted) {
                $this->resolveLocalEntries($this->settings->resourceEntries($type, 'project'), $type, $accumulator, new PathMetadata('local', 'project', 'top-level'), $projectBaseDir);
            }

            $this->resolveLocalEntries($this->settings->resourceEntries($type, 'user'), $type, $accumulator, new PathMetadata('local', 'user', 'top-level'), $globalBaseDir);
        }

        $this->addAutoDiscoveredResources($accumulator, $projectTrusted, $globalBaseDir, $projectBaseDir);

        return $accumulator->toResolvedPaths();
    }

    /**
     * Upstream's `resolveLocalEntries()`: the plain entries resolved from $baseDir and expanded to
     * files, each added on or off by the patterns among the entries (`applyPatterns()`). An entry
     * that does not exist is skipped, as upstream skips it.
     *
     * @param list<string> $entries
     */
    private function resolveLocalEntries(array $entries, string $type, ResourceAccumulator $accumulator, PathMetadata $metadata, string $baseDir): void
    {
        if ($entries === []) {
            return;
        }

        $plain = array_values(array_filter($entries, static fn (string $entry): bool => !PackageFilter::isPattern($entry)));
        $patterns = array_values(array_filter($entries, PackageFilter::isPattern(...)));
        $files = [];

        foreach ($plain as $entry) {
            $path = Paths::resolve($entry, $baseDir);

            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $files = [...$files, ...ResourceDiscovery::collect($path, $type)];
            }
        }

        $enabled = PackageFilter::apply($files, $patterns, $baseDir);

        foreach ($files as $file) {
            $accumulator->add($type, $file, $metadata, in_array($file, $enabled, true));
        }
    }

    /** Upstream's `addAutoDiscoveredResources()`, for skills, prompts and themes — see `resolveLocalResources()`. */
    private function addAutoDiscoveredResources(ResourceAccumulator $accumulator, bool $projectTrusted, string $globalBaseDir, string $projectBaseDir): void
    {
        $userMetadata = new PathMetadata('auto', 'user', 'top-level', $globalBaseDir);
        $projectMetadata = new PathMetadata('auto', 'project', 'top-level', $projectBaseDir);
        $override = fn (string $type, string $scope): array => $this->settings->resourceEntries($type, $scope);
        $add = static function (string $type, array $paths, PathMetadata $metadata, array $overrides, string $baseDir) use ($accumulator): void {
            foreach ($paths as $path) {
                $accumulator->add($type, $path, $metadata, PackageFilter::isEnabledByOverrides($path, $overrides, $baseDir));
            }
        };

        $userAgentsSkillsDir = Config::userHome() . '/.agents/skills';
        $projectAgentsSkillDirs = $projectTrusted
            ? array_values(array_filter(
                ResourceDiscovery::ancestorAgentsSkillDirs($this->cwd),
                static fn (string $dir): bool => Paths::resolve($dir, '/') !== Paths::resolve($userAgentsSkillsDir, '/'),
            ))
            : [];

        if ($projectTrusted) {
            $add('skills', ResourceDiscovery::autoSkills("{$projectBaseDir}/skills", 'pi'), $projectMetadata, $override('skills', 'project'), $projectBaseDir);
        }

        // Each `.agents` directory is its own base, so a `!pattern` names a skill relative to it.
        foreach ($projectAgentsSkillDirs as $agentsSkillsDir) {
            $agentsBaseDir = dirname($agentsSkillsDir);
            $add('skills', ResourceDiscovery::autoSkills($agentsSkillsDir, 'agents'), $projectMetadata->with($agentsBaseDir), $override('skills', 'project'), $agentsBaseDir);
        }

        if ($projectTrusted) {
            $add('prompts', ResourceDiscovery::autoFiles("{$projectBaseDir}/prompts", '.md'), $projectMetadata, $override('prompts', 'project'), $projectBaseDir);
            $add('themes', ResourceDiscovery::autoFiles("{$projectBaseDir}/themes", '.json'), $projectMetadata, $override('themes', 'project'), $projectBaseDir);
        }

        $add('skills', ResourceDiscovery::autoSkills("{$globalBaseDir}/skills", 'pi'), $userMetadata, $override('skills', 'user'), $globalBaseDir);

        $userAgentsBaseDir = dirname($userAgentsSkillsDir);
        $add('skills', ResourceDiscovery::autoSkills($userAgentsSkillsDir, 'agents'), $userMetadata->with($userAgentsBaseDir), $override('skills', 'user'), $userAgentsBaseDir);

        $add('prompts', ResourceDiscovery::autoFiles("{$globalBaseDir}/prompts", '.md'), $userMetadata, $override('prompts', 'user'), $globalBaseDir);
        $add('themes', ResourceDiscovery::autoFiles("{$globalBaseDir}/themes", '.json'), $userMetadata, $override('themes', 'user'), $globalBaseDir);
    }

    /**
     * @param list<array{package: string|array<string, mixed>, scope: string}> $sources
     * @param (Closure(string): string)|null $onMissing
     */
    private function resolvePackageSources(array $sources, ResourceAccumulator $accumulator, ?Closure $onMissing): void
    {
        foreach ($sources as ['package' => $package, 'scope' => $scope]) {
            $sourceString = self::sourceOf($package);
            $filter = is_array($package) ? $package : null;
            $deltaBase = $this->autoloadDeltaBase($package, $scope, $sources);
            $resolvedSource = $deltaBase['source'] ?? $sourceString;
            $resolvedScope = $deltaBase['scope'] ?? $scope;
            $parsed = PackageSource::parse($resolvedSource);
            $metadata = new PathMetadata($sourceString, $scope, 'package');

            if ($parsed instanceof LocalSource) {
                $this->resolveLocal($parsed, $accumulator, $filter, $metadata, $this->baseDirFor($resolvedScope));

                continue;
            }

            // Upstream's `installMissing()`: false when the package is to be left out.
            $installMissing = function () use ($onMissing, $resolvedSource, $parsed, $resolvedScope): bool {
                if (self::offline()) {
                    return false;
                }

                $action = $onMissing === null ? 'install' : $onMissing($resolvedSource);

                if ($action === 'skip') {
                    return false;
                }

                if ($action === 'error') {
                    throw new PackageError("Missing source: {$resolvedSource}");
                }

                $parsed instanceof ComposerSource
                    ? $this->installComposer($parsed, $resolvedScope)
                    : $this->installGit($parsed, $resolvedScope);

                return true;
            };

            if ($parsed instanceof ComposerSource) {
                $installedPath = $this->composerInstallPath($parsed, $resolvedScope);

                if ((!is_dir($installedPath) || !$this->composerMatchesConfigured($parsed, $resolvedScope)) && !$installMissing()) {
                    continue;
                }

                $this->collectPackageResources($installedPath, $accumulator, $filter, $metadata->with($installedPath, $installedPath));

                continue;
            }

            $installedPath = $this->gitInstallPath($parsed, $resolvedScope);

            if (!is_dir($installedPath)) {
                if (!$installMissing()) {
                    continue;
                }
            } elseif ($resolvedScope === 'temporary' && !$parsed->pinned() && !self::offline()) {
                $this->refreshTemporary($parsed, $resolvedSource);
            }

            $this->collectPackageResources($installedPath, $accumulator, $filter, $metadata->with($installedPath, $installedPath));
        }
    }

    /**
     * Upstream's `findAutoloadDeltaBase()`: a project entry with `autoload: false` filters the
     * user's entry for the same package rather than replacing it, so its resources are the
     * user's installation.
     *
     * @param string|array<string, mixed> $package
     * @param list<array{package: string|array<string, mixed>, scope: string}> $sources
     * @return array{source: string, scope: string}|null
     */
    private function autoloadDeltaBase(string|array $package, string $scope, array $sources): ?array
    {
        if ($scope !== 'project' || !is_array($package) || ($package['autoload'] ?? null) !== false) {
            return null;
        }

        $identity = $this->identity($package['source'], $scope);

        foreach ($sources as $entry) {
            if ($entry['scope'] === 'user' && $this->identity(self::sourceOf($entry['package']), 'user') === $identity) {
                return ['source' => self::sourceOf($entry['package']), 'scope' => 'user'];
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $filter */
    private function resolveLocal(LocalSource $source, ResourceAccumulator $accumulator, ?array $filter, PathMetadata $metadata, string $baseDir): void
    {
        $resolved = Paths::resolve($source->path, $baseDir);

        if (is_file($resolved)) {
            $accumulator->add('extensions', $resolved, $metadata->with(dirname($resolved)), true);

            return;
        }

        if (!is_dir($resolved)) {
            return;
        }

        $metadata = $metadata->with($resolved, $resolved);

        if (!$this->collectPackageResources($resolved, $accumulator, $filter, $metadata)) {
            // A directory with none of the package shapes is one extension, as a `-e ./dir` is.
            $accumulator->add('extensions', $resolved, $metadata, true);
        }
    }

    /**
     * Upstream's `collectPackageResources()`: with a filter, each type as the filter says; else
     * the manifest; else the conventional directories. False when the directory has none of it.
     *
     * @param array<string, mixed>|null $filter
     */
    private function collectPackageResources(string $root, ResourceAccumulator $accumulator, ?array $filter, PathMetadata $metadata): bool
    {
        if ($filter !== null) {
            foreach (ResolvedPaths::TYPES as $type) {
                $patterns = $filter[$type] ?? null;
                $patterns = is_array($patterns) ? array_values(array_filter($patterns, is_string(...))) : null;

                if (($filter['autoload'] ?? null) === false) {
                    $this->applyDeltaFilter($root, $patterns ?? [], $type, $accumulator, $metadata);
                } elseif ($patterns !== null) {
                    $this->applyFilter($root, $patterns, $type, $accumulator, $metadata);
                } else {
                    $this->collectDefault($root, $type, $accumulator, $metadata);
                }
            }

            return true;
        }

        $manifest = PackageManifest::read($root);

        if ($manifest !== null) {
            foreach (ResolvedPaths::TYPES as $type) {
                $this->addManifestEntries($manifest->of($type), $root, $type, $accumulator, $metadata);
            }

            return true;
        }

        $any = false;

        foreach (ResolvedPaths::TYPES as $type) {
            $dir = "{$root}/{$type}";

            if (is_dir($dir)) {
                foreach (ResourceDiscovery::collect($dir, $type) as $file) {
                    $accumulator->add($type, $file, $metadata, true);
                }

                $any = true;
            }
        }

        return $any;
    }

    private function collectDefault(string $root, string $type, ResourceAccumulator $accumulator, PathMetadata $metadata): void
    {
        $entries = PackageManifest::read($root)?->of($type);

        if ($entries !== null) {
            $this->addManifestEntries($entries, $root, $type, $accumulator, $metadata);

            return;
        }

        foreach (ResourceDiscovery::collect("{$root}/{$type}", $type) as $file) {
            $accumulator->add($type, $file, $metadata, true);
        }
    }

    /** @param list<string> $patterns */
    private function applyFilter(string $root, array $patterns, string $type, ResourceAccumulator $accumulator, PathMetadata $metadata): void
    {
        $all = $this->manifestFiles($root, $type);

        // `[]` is "none of this type": every file is listed, and off.
        $enabled = $patterns === [] ? [] : PackageFilter::apply($all, $patterns, $root);

        foreach ($all as $file) {
            $accumulator->add($type, $file, $metadata, in_array($file, $enabled, true));
        }
    }

    /** @param list<string> $patterns */
    private function applyDeltaFilter(string $root, array $patterns, string $type, ResourceAccumulator $accumulator, PathMetadata $metadata): void
    {
        if ($patterns === []) {
            return;
        }

        foreach (PackageFilter::applyDelta($this->manifestFiles($root, $type), $patterns, $root) as $file => $enabled) {
            $accumulator->add($type, $file, $metadata, $enabled);
        }
    }

    /**
     * Upstream's `collectManifestFiles()`: the files of one type a package declares — the
     * manifest's entries narrowed by the manifest's own override patterns, or the conventional
     * directory.
     *
     * @return list<string>
     */
    private function manifestFiles(string $root, string $type): array
    {
        $entries = PackageManifest::read($root)?->of($type);

        if ($entries !== null && $entries !== []) {
            $all = $this->filesFromManifestEntries($entries, $root, $type);
            $overrides = array_values(array_filter($entries, PackageFilter::isOverride(...)));

            return $overrides === [] ? $all : PackageFilter::apply($all, $overrides, $root);
        }

        return ResourceDiscovery::collect("{$root}/{$type}", $type);
    }

    /** @param list<string>|null $entries */
    private function addManifestEntries(?array $entries, string $root, string $type, ResourceAccumulator $accumulator, PathMetadata $metadata): void
    {
        if ($entries === null) {
            return;
        }

        $all = $this->filesFromManifestEntries($entries, $root, $type);
        $overrides = array_values(array_filter($entries, PackageFilter::isOverride(...)));

        foreach (PackageFilter::apply($all, $overrides, $root) as $file) {
            $accumulator->add($type, $file, $metadata, true);
        }
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    private function filesFromManifestEntries(array $entries, string $root, string $type): array
    {
        $paths = [];

        foreach ($entries as $entry) {
            if (PackageFilter::isOverride($entry)) {
                continue;
            }

            if (Glob::isPattern($entry)) {
                $paths = [...$paths, ...Glob::expand($entry, $root)];
            } else {
                $paths[] = Paths::resolve($entry, $root);
            }
        }

        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $files = [...$files, ...ResourceDiscovery::collect($path, $type)];
            }
        }

        return $files;
    }

    // ---- the settings ---------------------------------------------------------------------------

    /**
     * Upstream's `listConfiguredPackages()`.
     *
     * @return list<array{source: string, scope: 'user'|'project', filtered: bool, installedPath: string|null}>
     */
    public function listConfiguredPackages(): array
    {
        $configured = [];

        foreach (['user', 'project'] as $scope) {
            foreach ($this->settings->packages($scope) as $package) {
                $source = self::sourceOf($package);
                $configured[] = [
                    'source' => $source,
                    'scope' => $scope,
                    'filtered' => is_array($package),
                    'installedPath' => $this->installedPath($source, $scope),
                ];
            }
        }

        return $configured;
    }

    /** Upstream's `getInstalledPath()`: where the package is, or null when it is not there. */
    public function installedPath(string $source, string $scope): ?string
    {
        $parsed = PackageSource::parse($source);
        $path = match (true) {
            $parsed instanceof GitSource => $this->gitInstallPath($parsed, $scope),
            $parsed instanceof ComposerSource => $this->composerInstallPath($parsed, $scope),
            default => Paths::resolve($parsed->path, $this->baseDirFor($scope)),
        };

        return file_exists($path) ? $path : null;
    }

    /** Upstream's `addSourceToSettings()`: true when the file changed. */
    public function addSourceToSettings(string $source, bool $local = false): bool
    {
        $scope = $local ? 'project' : 'user';
        $packages = $this->settings->packages($scope);
        $normalized = $this->normalizeForSettings($source, $scope);

        foreach ($packages as $index => $existing) {
            if (!$this->sourcesMatch($existing, $source, $scope)) {
                continue;
            }

            if (self::sourceOf($existing) === $normalized) {
                return false;
            }

            $packages[$index] = is_string($existing) ? $normalized : ['source' => $normalized] + $existing;
            $this->settings->setPackages($packages, $scope);

            return true;
        }

        $packages[] = $normalized;
        $this->settings->setPackages($packages, $scope);

        return true;
    }

    /** Upstream's `removeSourceFromSettings()`: true when something was taken out. */
    public function removeSourceFromSettings(string $source, bool $local = false): bool
    {
        $scope = $local ? 'project' : 'user';
        $packages = $this->settings->packages($scope);
        $kept = array_values(array_filter($packages, fn (string|array $existing): bool => !$this->sourcesMatch($existing, $source, $scope)));

        if (count($kept) === count($packages)) {
            return false;
        }

        $this->settings->setPackages($kept, $scope);

        return true;
    }

    // ---- install / remove / update --------------------------------------------------------------

    /** Upstream's `install()`: `composer require`, a clone for git, an existence check for a path. */
    public function install(string $source, bool $local = false): void
    {
        $parsed = PackageSource::parse($source);
        $scope = $local ? 'project' : 'user';
        $this->assertProjectTrusted($scope);

        $this->withProgress('install', $source, "Installing {$source}...", function () use ($parsed, $scope): void {
            if ($parsed instanceof ComposerSource) {
                $this->installComposer($parsed, $scope);

                return;
            }

            if ($parsed instanceof GitSource) {
                $this->installGit($parsed, $scope);

                return;
            }

            $resolved = Paths::resolve($parsed->path, $this->cwd);

            if (!file_exists($resolved)) {
                throw new PackageError("Path does not exist: {$resolved}");
            }
        });
    }

    public function installAndPersist(string $source, bool $local = false): void
    {
        $this->install($source, $local);
        $this->addSourceToSettings($source, $local);
    }

    /** Upstream's `remove()`: `composer remove`, the checkout deleted for git, nothing for a path. */
    public function remove(string $source, bool $local = false): void
    {
        $parsed = PackageSource::parse($source);
        $scope = $local ? 'project' : 'user';
        $this->assertProjectTrusted($scope);

        $this->withProgress('remove', $source, "Removing {$source}...", function () use ($parsed, $scope): void {
            if ($parsed instanceof ComposerSource) {
                $this->removeComposer($parsed, $scope);
            }

            if ($parsed instanceof GitSource) {
                $this->removeGit($parsed, $scope);
            }
        });
    }

    public function removeAndPersist(string $source, bool $local = false): bool
    {
        $this->remove($source, $local);

        return $this->removeSourceFromSettings($source, $local);
    }

    /**
     * Upstream's `update()`: every configured git package, or the one named. A pinned ref is
     * reconciled — the checkout moved to it if the setting changed — and never advanced.
     *
     * @throws PackageError when a named source matches nothing
     */
    public function update(?string $source = null): void
    {
        $identity = $source !== null ? $this->identity($source) : null;
        $targets = [];
        $all = [];

        foreach (['user', 'project'] as $scope) {
            foreach ($this->settings->packages($scope) as $package) {
                $sourceString = self::sourceOf($package);
                $all[] = $sourceString;

                if ($identity !== null && $this->identity($sourceString, $scope) !== $identity) {
                    continue;
                }

                $targets[] = ['source' => $sourceString, 'scope' => $scope];
            }
        }

        if ($source !== null && $targets === []) {
            throw new PackageError($this->noMatchingPackage($source, $all));
        }

        if (self::offline()) {
            return;
        }

        foreach ($targets as ['source' => $sourceString, 'scope' => $scope]) {
            $parsed = PackageSource::parse($sourceString);

            // Pinned Composer versions are fixed, as upstream's pinned npm versions are. Unpinned,
            // `composer require` again is upstream's `npm install name@latest`: within a
            // constraint when there is one, the newest release when there is not.
            if ($parsed instanceof ComposerSource) {
                if (!$parsed->pinned()) {
                    $this->withProgress('update', $sourceString, "Updating {$sourceString}...", function () use ($parsed, $scope): void {
                        $this->installComposer($parsed, $scope);
                    });
                }

                continue;
            }

            if (!$parsed instanceof GitSource) {
                continue;
            }

            $this->withProgress('update', $sourceString, "Updating {$sourceString}...", function () use ($parsed, $scope): void {
                $this->updateGit($parsed, $scope);
            });
        }
    }

    /**
     * Whether `origin` has moved past the checkout — upstream's `gitHasAvailableUpdate()`, for
     * the startup check. False offline, and false for anything that could not be asked.
     */
    public function hasAvailableUpdate(string $installedPath): bool
    {
        if (self::offline()) {
            return false;
        }

        $local = $this->capture(['git', 'rev-parse', 'HEAD'], $installedPath);

        if ($local === null) {
            return false;
        }

        $remote = $this->remoteHead($installedPath);

        return $remote !== null && trim($local) !== $remote;
    }

    /**
     * Whether `pig update` would move a Composer package — upstream's `npmHasAvailableUpdate()`,
     * for the startup check. With a constraint, what `composer update --dry-run` would upgrade
     * within it; without one, whether a newer release exists at all, since `update` requires the
     * name again with no constraint. False offline, and for anything that could not be asked.
     */
    public function composerHasAvailableUpdate(string $source, string $scope): bool
    {
        $parsed = PackageSource::parse($source);

        if (self::offline() || !$parsed instanceof ComposerSource || !is_dir($this->composerInstallPath($parsed, $scope))) {
            return false;
        }

        $root = $this->composerInstallRoot($scope);

        if ($parsed->version !== null) {
            $answer = $this->captureComposer(['update', $parsed->name, '--dry-run'], $root);

            return $answer !== null && preg_match('/- Upgrading ' . preg_quote($parsed->name, '/') . ' \(/', $answer) === 1;
        }

        $answer = $this->captureComposer(['outdated', $parsed->name, '--direct', '--format=json'], $root, false);
        $json = null;
        if ($answer !== null) {
            $first = strpos($answer, '{');
            $last = strrpos($answer, '}');
            if ($first !== false && $last !== false && $last >= $first) {
                $json = substr($answer, $first, $last - $first + 1);
            }
        }
        $report = $json === null ? null : json_decode($json, true);

        return is_array($report)
            && isset($report['latest'], $report['versions'][0])
            && is_string($report['latest'])
            && $report['latest'] !== $report['versions'][0];
    }

    // ---- composer -------------------------------------------------------------------------------

    /**
     * Upstream's `installNpm()`: `composer require` into the scope's shared project, which writes
     * the constraint into that project's `composer.json` — so `composerMatchesConfigured()` can
     * tell when the settings ask for another one.
     */
    private function installComposer(ComposerSource $source, string $scope): void
    {
        $root = $this->composerInstallRoot($scope);
        $this->ensureComposerProject($root);
        $this->composer(['require', $source->requirement(), '--update-no-dev'], $root);
    }

    /** Upstream's `uninstallNpm()`: nothing to do when the scope never installed anything. */
    private function removeComposer(ComposerSource $source, string $scope): void
    {
        $root = $this->composerInstallRoot($scope);

        if (is_file("{$root}/composer.json")) {
            $this->composer(['remove', $source->name, '--update-no-dev'], $root);
        }
    }

    /**
     * Upstream's `installedNpmMatchesConfiguredVersion()`, by what the project was asked for
     * rather than by what is installed: Composer resolved the constraint when it installed, so a
     * constraint that is still the one written in `require` is satisfied. Comparing an installed
     * version against a range here would be a second constraint solver beside Composer's.
     */
    private function composerMatchesConfigured(ComposerSource $source, string $scope): bool
    {
        if ($source->version === null) {
            return true;
        }

        $path = $this->composerInstallRoot($scope) . '/composer.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($manifest) && ($manifest['require'][$source->name] ?? null) === $source->version;
    }

    /**
     * Upstream's `ensureNpmProject()`. `replace` is what keeps a second pig out of `vendor/`
     * (see the class docblock); `allow-plugins: false` keeps a package's Composer plugin from
     * running on install, since pig runs nothing a package brings; `version` only stops Composer
     * warning that a project with no git history has none.
     */
    private function ensureComposerProject(string $root): void
    {
        $this->ensureGitIgnore($root);

        if (is_file("{$root}/composer.json")) {
            return;
        }

        $manifest = [
            'name' => 'pig/extensions',
            'description' => 'The packages pig installed from composer: sources.',
            'version' => '1.0.0',
            'require' => new stdClass(),
            'replace' => [Version::PACKAGE => '*'],
            'config' => ['allow-plugins' => false],
        ];

        if (file_put_contents("{$root}/composer.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new PackageError("Could not write {$root}/composer.json");
        }
    }

    /** `<root>/vendor/<vendor>/<name>` — upstream's `getManagedNpmInstallPath()`. */
    private function composerInstallPath(ComposerSource $source, string $scope): string
    {
        return $this->managed($this->composerInstallRoot($scope) . '/vendor', $source->name);
    }

    /** Upstream's `getNpmInstallRoot()`. */
    private function composerInstallRoot(string $scope): string
    {
        if ($scope === 'temporary') {
            return $this->temporaryDir('composer', '', null);
        }

        if ($scope === 'project') {
            $this->assertProjectTrusted($scope);

            return rtrim($this->cwd, '/') . '/.pig/composer';
        }

        return $this->home() . '/composer';
    }

    /**
     * Run Composer in $root, and throw with its words when it fails. A machine with no Composer
     * is told so by name: `proc_open` answers STOPPED with nothing said for a program that is not
     * on the PATH, which would otherwise read as "composer failed" with no reason.
     *
     * @param list<string> $arguments
     */
    private function composer(array $arguments, string $root): void
    {
        [$exit, , $stderr] = Process::run(['composer', ...$arguments, '--no-interaction', '--no-progress', "--working-dir={$root}"], self::CLONE_TIMEOUT);

        if ($exit === Process::STOPPED && trim($stderr) === '') {
            throw new PackageError('composer was not found on the PATH; install Composer, or use a git or local source.');
        }

        if ($exit !== 0) {
            $detail = trim($stderr);

            throw new PackageError('composer ' . implode(' ', $arguments) . ' failed' . ($detail !== '' ? ": {$detail}" : ''));
        }
    }

    /**
     * @param list<string> $arguments
     * @return string|null standard output, or null when Composer failed or is not there
     */
    private function captureComposer(array $arguments, string $root, bool $includeStderr = true): ?string
    {
        [$exit, $stdout, $stderr] = Process::run(['composer', ...$arguments, '--no-interaction', "--working-dir={$root}"], self::NETWORK_TIMEOUT * 6);

        // `--dry-run` reports its plan on standard error, `outdated --format=json` on standard output.
        if ($exit !== 0) {
            return null;
        }

        return $includeStderr ? $stdout . $stderr : $stdout;
    }

    // ---- git ------------------------------------------------------------------------------------

    private function installGit(GitSource $source, string $scope): void
    {
        $target = $this->gitInstallPath($source, $scope);

        if (is_dir($target)) {
            $this->reconcile($source, $target);

            return;
        }

        $root = $this->gitInstallRoot($scope);

        if ($root !== null) {
            $this->ensureGitIgnore($root);
        }

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o755, true) && !is_dir(dirname($target))) {
            throw new PackageError('Could not create ' . dirname($target));
        }

        try {
            $this->git(['clone', $source->repo, $target], null, self::CLONE_TIMEOUT);

            if ($source->ref !== null) {
                $this->git(['checkout', $source->ref], $target);
            }
        } catch (PackageError $error) {
            self::removeTree($target);
            $this->pruneEmptyParents($target, $root);

            throw $error;
        }
    }

    private function updateGit(GitSource $source, string $scope): void
    {
        $target = $this->gitInstallPath($source, $scope);

        if (!is_dir($target)) {
            $this->installGit($source, $scope);

            return;
        }

        $this->reconcile($source, $target);
    }

    /** A pinned ref: fetch it and reset to it. Unpinned: follow the upstream branch. */
    private function reconcile(GitSource $source, string $target): void
    {
        if ($source->ref !== null) {
            $this->ensureGitRef($target, ['fetch', 'origin', $source->ref], 'FETCH_HEAD');

            return;
        }

        ['fetch' => $fetch, 'ref' => $ref] = $this->localUpdateTarget($target);
        $this->ensureGitRef($target, $fetch, $ref);
    }

    /**
     * Upstream's `ensureGitRef()`: fetch only what will be reset to, and reset only when it
     * moved — a checkout that is already there is left alone, uncommitted changes included.
     *
     * @param list<string> $fetch
     */
    private function ensureGitRef(string $target, array $fetch, string $ref): void
    {
        $this->git($fetch, $target, self::CLONE_TIMEOUT);

        $localHead = trim($this->capture(['git', 'rev-parse', 'HEAD'], $target) ?? '');
        $commit = "{$ref}^{commit}";
        $targetHead = trim($this->capture(['git', 'rev-parse', $commit], $target) ?? '');

        if ($targetHead === '' || $localHead === $targetHead) {
            return;
        }

        $this->git(['reset', '--hard', $commit], $target);
        $this->git(['clean', '-fdx'], $target);
    }

    /**
     * Upstream's `getLocalGitUpdateTarget()`: the upstream branch when the checkout has one,
     * else `origin/HEAD`, with the fetch refspec that brings exactly that.
     *
     * @return array{fetch: list<string>, ref: string}
     */
    private function localUpdateTarget(string $target): array
    {
        $upstream = trim($this->capture(['git', 'rev-parse', '--abbrev-ref', '@{upstream}'], $target) ?? '');

        if (str_starts_with($upstream, 'origin/') && strlen($upstream) > 7) {
            $branch = substr($upstream, 7);

            return [
                'fetch' => ['fetch', '--prune', '--no-tags', 'origin', "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"],
                'ref' => '@{upstream}',
            ];
        }

        $this->capture(['git', 'remote', 'set-head', 'origin', '-a'], $target, self::NETWORK_TIMEOUT);
        $originHead = trim($this->capture(['git', 'symbolic-ref', 'refs/remotes/origin/HEAD'], $target) ?? '');
        $branch = (string) preg_replace('#^refs/remotes/origin/#', '', $originHead);

        return [
            'fetch' => $branch !== ''
                ? ['fetch', '--prune', '--no-tags', 'origin', "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"]
                : ['fetch', '--prune', '--no-tags', 'origin', '+HEAD:refs/remotes/origin/HEAD'],
            'ref' => 'origin/HEAD',
        ];
    }

    /** Upstream's `getRemoteGitHead()`: the upstream branch's tip at `origin`, else `origin`'s HEAD. */
    private function remoteHead(string $target): ?string
    {
        $upstream = trim($this->capture(['git', 'rev-parse', '--abbrev-ref', '@{upstream}'], $target) ?? '');

        if (str_starts_with($upstream, 'origin/') && strlen($upstream) > 7) {
            $answer = $this->capture(['git', 'ls-remote', 'origin', 'refs/heads/' . substr($upstream, 7)], $target, self::NETWORK_TIMEOUT);

            if ($answer !== null && preg_match('/^([0-9a-f]{40})\s/m', $answer, $match) === 1) {
                return $match[1];
            }
        }

        $answer = $this->capture(['git', 'ls-remote', 'origin', 'HEAD'], $target, self::NETWORK_TIMEOUT);

        return $answer !== null && preg_match('/^([0-9a-f]{40})\s+HEAD$/m', $answer, $match) === 1 ? $match[1] : null;
    }

    private function refreshTemporary(GitSource $source, string $sourceString): void
    {
        try {
            $this->withProgress('pull', $sourceString, "Refreshing {$sourceString}...", function () use ($source): void {
                $this->updateGit($source, 'temporary');
            });
        } catch (PackageError) {
            // The cached checkout is kept when a refresh fails, as upstream keeps it.
        }
    }

    private function removeGit(GitSource $source, string $scope): void
    {
        $target = $this->gitInstallPath($source, $scope);
        self::removeTree($target);
        $this->pruneEmptyParents($target, $this->gitInstallRoot($scope));
    }

    /** Upstream's `pruneEmptyGitParents()`: `<root>/<host>` goes when its last package did. */
    private function pruneEmptyParents(string $target, ?string $root): void
    {
        if ($root === null) {
            return;
        }

        $current = dirname($target);

        while (str_starts_with($current, $root . '/') && $current !== $root) {
            if (is_dir($current) && count(scandir($current) ?: []) > 2) {
                break;
            }

            if (is_dir($current)) {
                $removed = false;
                set_error_handler(static fn (): bool => true);
                try {
                    $removed = rmdir($current);
                } finally {
                    restore_error_handler();
                }

                if (!$removed) {
                    break;
                }
            }

            $current = dirname($current);
        }
    }

    /** Upstream's `ensureGitIgnore()`: the install root ignores itself, so a project's `.pig/git` never gets committed. */
    private function ensureGitIgnore(string $root): void
    {
        if (!is_dir($root) && !mkdir($root, 0o755, true) && !is_dir($root)) {
            throw new PackageError("Could not create {$root}");
        }

        if (!is_file("{$root}/.gitignore")) {
            file_put_contents("{$root}/.gitignore", "*\n!.gitignore\n");
        }
    }

    /** Where a git package goes: `<root>/<host>/<path>`, and never anywhere else — upstream's `resolveManagedPath()`. */
    private function gitInstallPath(GitSource $source, string $scope): string
    {
        if ($scope === 'temporary') {
            return $this->managed($this->temporaryDir('git', "{$source->host}/{$source->path}", $source->ref), $source->host, $source->path);
        }

        return $this->managed($this->gitInstallRoot($scope) ?? throw new PackageError('Missing git install root'), $source->host, $source->path);
    }

    private function gitInstallRoot(string $scope): ?string
    {
        if ($scope === 'temporary') {
            return null;
        }

        if ($scope === 'project') {
            $this->assertProjectTrusted($scope);

            return rtrim($this->cwd, '/') . '/.pig/git';
        }

        return $this->home() . '/git';
    }

    /** Upstream's `getTemporaryDir()`: `<home>/tmp/extensions/<prefix>/<hash>/<suffix>`. */
    private function temporaryDir(string $prefix, string $suffix, ?string $ref): string
    {
        $hash = substr(hash('sha256', "{$prefix}-{$suffix}" . ($ref !== null ? "@{$ref}" : '')), 0, 8);

        return $this->managed($this->home() . "/tmp/extensions/{$prefix}", $hash);
    }

    /** A path built from parts that must stay under its root. */
    private function managed(string $root, string ...$parts): string
    {
        $resolved = Paths::resolve(implode('/', $parts), $root);

        if ($resolved !== $root && !str_starts_with($resolved, rtrim($root, '/') . '/')) {
            throw new PackageError("Refusing to use path outside package install root: {$resolved}");
        }

        return $resolved;
    }

    /** Upstream's `getBaseDirForScope()`: what a relative local path is relative to. */
    private function baseDirFor(string $scope): string
    {
        if ($scope === 'project') {
            $this->assertProjectTrusted($scope);

            return rtrim($this->cwd, '/') . '/.pig';
        }

        return $scope === 'user' ? $this->home() : $this->cwd;
    }

    private function assertProjectTrusted(string $scope): void
    {
        if ($scope === 'project' && !$this->settings->isProjectTrusted()) {
            throw new PackageError('Project is not trusted; refusing to access project package storage');
        }
    }

    // ---- identity -------------------------------------------------------------------------------

    /**
     * Upstream's `getPackageIdentity()`: what two declarations of one package share — the name
     * for Composer, so two constraints are one package; the host and path for git, so SSH and
     * HTTPS spellings are one package; the resolved path for local.
     */
    private function identity(string $source, ?string $scope = null): string
    {
        $parsed = PackageSource::parse($source);

        if ($parsed instanceof ComposerSource) {
            return "composer:{$parsed->name}";
        }

        if ($parsed instanceof GitSource) {
            return "git:{$parsed->host}/{$parsed->path}";
        }

        return 'local:' . Paths::resolve($parsed->path, $scope !== null ? $this->baseDirFor($scope) : $this->cwd);
    }

    /** @param string|array<string, mixed> $existing */
    private function sourcesMatch(string|array $existing, string $input, string $scope): bool
    {
        return $this->identity(self::sourceOf($existing), $scope) === $this->identity($input);
    }

    /**
     * Upstream's `normalizePackageSourceForSettings()`: a local path written relative to the
     * settings file's directory, as Node's `path.relative()` writes it — so a project's
     * `.pig/settings.json` names `../tools`, which is the same directory on every checkout of
     * the project, where an absolute path is one machine's.
     */
    private function normalizeForSettings(string $source, string $scope): string
    {
        $parsed = PackageSource::parse($source);

        if (!$parsed instanceof LocalSource) {
            return $source;
        }

        $relative = Paths::relativeTo($this->baseDirFor($scope), Paths::resolve($parsed->path, $this->cwd));

        return $relative === '' ? '.' : $relative;
    }

    /**
     * Upstream's `dedupePackages()`: one entry per identity, the project's winning — unless it is
     * an `autoload: false` delta, which is kept beside the user's.
     *
     * @param list<array{package: string|array<string, mixed>, scope: string}> $packages
     * @return list<array{package: string|array<string, mixed>, scope: string}>
     */
    private function dedupe(array $packages): array
    {
        $result = [];
        $seen = [];

        foreach ($packages as $entry) {
            $identity = $this->identity(self::sourceOf($entry['package']), $entry['scope']);

            if (!isset($seen[$identity])) {
                $seen[$identity] = count($result);
                $result[] = $entry;

                continue;
            }

            $existing = $result[$seen[$identity]];

            if ($existing['scope'] === 'project' && $entry['scope'] === 'user') {
                if (is_array($existing['package']) && ($existing['package']['autoload'] ?? null) === false) {
                    $result[] = $entry;
                }
            } elseif ($entry['scope'] === 'project') {
                $result[$seen[$identity]] = $entry;
            }
        }

        return $result;
    }

    /** @param list<string> $configured */
    private function noMatchingPackage(string $source, array $configured): string
    {
        $trimmed = trim($source);

        foreach ($configured as $candidate) {
            $parsed = PackageSource::parse($candidate);

            // Upstream's `findSuggestedConfiguredSource()`: the bare name, or name and constraint.
            if ($parsed instanceof ComposerSource) {
                $spec = $parsed->version === null ? $parsed->name : "{$parsed->name}@{$parsed->version}";

                if ($trimmed === $parsed->name || $trimmed === $spec) {
                    return "No matching package found for {$source}. Did you mean {$candidate}?";
                }

                continue;
            }

            if (!$parsed instanceof GitSource) {
                continue;
            }

            $shorthand = "{$parsed->host}/{$parsed->path}";

            if ($trimmed === $shorthand || ($parsed->ref !== null && $trimmed === "{$shorthand}@{$parsed->ref}")) {
                return "No matching package found for {$source}. Did you mean {$candidate}?";
            }
        }

        return "No matching package found for {$source}";
    }

    // ---- plumbing -------------------------------------------------------------------------------

    /** @param string|array<string, mixed> $package */
    private static function sourceOf(string|array $package): string
    {
        return is_string($package) ? $package : (string) $package['source'];
    }

    /** Upstream's `isOfflineModeEnabled()`: `PIG_OFFLINE` (upstream's `PI_OFFLINE`) is `1`, `true` or `yes`. */
    private static function offline(): bool
    {
        return in_array(strtolower((string) getenv('PIG_OFFLINE')), ['1', 'true', 'yes'], true);
    }

    private function home(): string
    {
        return $this->home ?? Config::home();
    }

    /** @param Closure(): void $operation */
    private function withProgress(string $action, string $source, string $message, Closure $operation): void
    {
        $this->progress('start', $action, $source, $message);

        try {
            $operation();
            $this->progress('complete', $action, $source, null);
        } catch (PackageError $error) {
            $this->progress('error', $action, $source, $error->getMessage());

            throw $error;
        }
    }

    private function progress(string $type, string $action, string $source, ?string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($type, $action, $source, $message);
        }
    }

    /**
     * Run git, and throw with its words when it fails. `GIT_TERMINAL_PROMPT=0` as upstream sets
     * it: a repository that wants a password fails rather than waiting on a prompt nobody sees.
     *
     * @param list<string> $arguments
     */
    private function git(array $arguments, ?string $cwd, float $timeout = self::NETWORK_TIMEOUT * 6): void
    {
        [$exit, , $stderr] = Process::run(['env', 'GIT_TERMINAL_PROMPT=0', 'git', ...$arguments], $timeout, $cwd);

        if ($exit !== 0) {
            $detail = trim($stderr);

            throw new PackageError('git ' . implode(' ', $arguments) . ' failed' . ($detail !== '' ? ": {$detail}" : ''));
        }
    }

    /**
     * @param list<string> $command
     * @return string|null standard output, or null when the command failed or is not there
     */
    private function capture(array $command, ?string $cwd, float $timeout = self::NETWORK_TIMEOUT): ?string
    {
        [$exit, $stdout] = Process::run(['env', 'GIT_TERMINAL_PROMPT=0', ...$command], $timeout, $cwd);

        return $exit === 0 ? $stdout : null;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("{$path}/{$entry}");
            }
        }

        if (is_dir($path)) {
            set_error_handler(static fn (): bool => true);
            try {
                rmdir($path);
            } finally {
                restore_error_handler();
            }
        }
    }
}
