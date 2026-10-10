<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\CodingAgent\Extensions\BuiltinExtensions;
use Pig\CodingAgent\Packages\LocalSource;
use Pig\CodingAgent\Packages\PackageSource;
use Pig\CodingAgent\Packages\ResolvedPaths;
use Pig\CodingAgent\Packages\ResolvedResource;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\Paths;
use Pig\Tui\Components\Input;
use Pig\Tui\Container;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * `pig config`: the packages' resources, grouped by package and type, each switchable on and
 * off — upstream's `config-selector.ts`.
 *
 * Two write scopes, swapped with Tab when the project is trusted. **Global** toggles a resource
 * and writes a `+path` / `-path` into the user's `packages` entry for that package. **Project**
 * cycles inherit → load/unload → … and writes the same into the project's entry, creating an
 * `autoload: false` delta for a user package the project has no entry for; a resource the
 * project only inherits is dimmed until it has a verdict of its own.
 *
 * Package resources and upstream's built-in extensions are here; `PackageManager::resolve()`
 * resolves only packages (the loaders own the top-level `extensions` / `skills` settings and the
 * auto-discovered directories — see `CLAUDE.md`), so the one top-level kind is the built-ins
 * (`BuiltinExtensions::resources()`), switched by `+builtin:<name>` / `-builtin:<name>` in the
 * `extensions` setting as upstream's `toggleTopLevelResource()` does.
 */
final class ConfigSelector extends Container implements Focusable, InputHandler
{
    public bool $focused = false;

    private const array TYPE_LABELS = ['extensions' => 'Extensions', 'skills' => 'Skills', 'prompts' => 'Prompts', 'themes' => 'Themes'];

    /** @var array{global: list<array<string, mixed>>, project: list<array<string, mixed>>} groups per write scope */
    private array $groupsByScope;

    /** @var list<array<string, mixed>> group / subgroup / item rows, in order */
    private array $flat = [];

    /** @var list<array<string, mixed>> the rows the search leaves */
    private array $filtered = [];

    private int $selected = 0;

    private readonly Input $search;

    private readonly int $maxVisible;

    /** @var array<string, bool> `type:realpath` => enabled in the global resolution, for the inherit state */
    private array $inheritedEnabled = [];

    /** @var 'global'|'project' */
    private string $writeScope;

    /** @var Closure(): void|null */
    private ?Closure $onClose = null;

    /** @var Closure(): void|null */
    private ?Closure $onExit = null;

    /** @var Closure(): void|null */
    private ?Closure $onChange = null;

    /**
     * @param ResolvedPaths $global  resolved with the project untrusted: the user's packages
     * @param ResolvedPaths $project resolved with both scopes, when the project is trusted
     * @param 'global'|'project' $writeScope
     */
    public function __construct(
        ResolvedPaths $global,
        ResolvedPaths $project,
        private readonly Settings $settings,
        private readonly string $cwd,
        private readonly string $home,
        string $writeScope = 'global',
        private readonly bool $projectModeAvailable = true,
        int $terminalHeight = 24,
    ) {
        $this->writeScope = $writeScope;
        $this->groupsByScope = ['global' => $this->groups($global), 'project' => $this->groups($project)];

        foreach ($this->groupsByScope['global'] as $group) {
            foreach ($group['subgroups'] as $subgroup) {
                foreach ($subgroup['items'] as $item) {
                    $this->inheritedEnabled[$this->itemKey($item)] = $item['enabled'];
                }
            }
        }

        $this->search = new Input();
        // Upstream's eight rows of chrome: the spacers, the rules and the two-line header.
        $this->maxVisible = max(5, $terminalHeight - 8);
        $this->rebuild();
    }

    /** @param Closure(): void $onClose */
    public function setCloseHandler(Closure $onClose): void
    {
        $this->onClose = $onClose;
    }

    /** @param Closure(): void $onExit */
    public function setExitHandler(Closure $onExit): void
    {
        $this->onExit = $onExit;
    }

    /** @param Closure(): void $onChange told after a toggle, so the screen redraws */
    public function setChangeHandler(Closure $onChange): void
    {
        $this->onChange = $onChange;
    }

    // ---- drawing --------------------------------------------------------------------------------

    #[\Override]
    public function render(int $width): array
    {
        $this->search->focused = $this->focused;
        $theme = Themes::theme();
        $lines = ['', $theme->fg('border', str_repeat('─', $width)), ''];

        // Upstream's `ConfigSelectorHeader`: the title left, the keys right, the file under them.
        $title = $theme->bold($this->writeScope === 'project' ? 'Project Local Resources' : 'Global Resources');
        $separator = $theme->fg('muted', ' · ');
        $hint = ($this->projectModeAvailable ? $theme->fg('dim', 'tab') . $theme->fg('muted', ' switch mode') . $separator : '')
            . $theme->fg('dim', 'space') . $theme->fg('muted', $this->writeScope === 'project' ? ' cycle inherit/+/-' : ' toggle')
            . $separator . $theme->fg('dim', 'esc') . $theme->fg('muted', ' close');
        $spacing = max(1, $width - Width::visible($title) - Width::visible($hint));
        $lines[] = Width::truncate($title . str_repeat(' ', $spacing) . $hint, $width, '');
        $lines[] = Width::truncate($theme->fg('muted', $this->writeScope === 'project'
            ? '.pig/settings.json · inherited global resources are dimmed'
            : '~/.pig/agent/settings.json'), $width, '');
        $lines[] = '';

        $lines = [...$lines, ...$this->search->render($width), ''];

        if ($this->filtered === []) {
            $lines[] = $theme->fg('muted', '  No resources found');
        } else {
            $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), count($this->filtered) - $this->maxVisible));
            $end = min($start + $this->maxVisible, count($this->filtered));

            for ($i = $start; $i < $end; $i++) {
                $entry = $this->filtered[$i];

                if ($entry['type'] === 'group') {
                    $inherited = $this->writeScope === 'project' && $entry['group']['scope'] === 'user';
                    $label = $theme->bold($entry['group']['label'] . ($inherited ? ' · inherited global' : ''));
                    $lines[] = Width::truncate('  ' . $theme->fg($inherited ? 'dim' : 'accent', $label), $width, '');
                } elseif ($entry['type'] === 'subgroup') {
                    $colour = $this->writeScope === 'project' && $entry['group']['scope'] === 'user' ? 'dim' : 'muted';
                    $lines[] = Width::truncate('    ' . $theme->fg($colour, $entry['subgroup']['label']), $width, '');
                } else {
                    $item = $entry['item'];
                    $cursor = $i === $this->selected ? '> ' : '  ';
                    $dimmed = $this->isDimmed($item);
                    $name = $i === $this->selected && !$dimmed ? $theme->bold($item['displayName']) : $item['displayName'];
                    $name = $dimmed ? $theme->fg('dim', $name) : $name;
                    $lines[] = Width::truncate("{$cursor}    " . $this->checkbox($item) . " {$name}" . $this->suffix($item), $width);
                }
            }

            if ($start > 0 || $end < count($this->filtered)) {
                $items = count(array_filter($this->filtered, static fn (array $e): bool => $e['type'] === 'item'));
                $current = count(array_filter(array_slice($this->filtered, 0, $this->selected), static fn (array $e): bool => $e['type'] === 'item')) + 1;
                $lines[] = $theme->fg('dim', "  ({$current}/{$items})");
            }
        }

        $lines[] = '';
        $lines[] = $theme->fg('border', str_repeat('─', $width));

        return $lines;
    }

    // ---- keys -----------------------------------------------------------------------------------

    #[\Override]
    public function handleInput(string $data): void
    {
        if (Keys::isArrowUp($data)) {
            $this->selected = $this->nextItem($this->selected, -1);
        } elseif (Keys::isArrowDown($data)) {
            $this->selected = $this->nextItem($this->selected, 1);
        } elseif (Keys::isPageUp($data)) {
            $target = max(0, $this->selected - $this->maxVisible);
            while ($target < count($this->filtered) && $this->filtered[$target]['type'] !== 'item') {
                $target++;
            }
            if ($target < count($this->filtered)) {
                $this->selected = $target;
            }
        } elseif (Keys::isPageDown($data)) {
            $target = min(count($this->filtered) - 1, $this->selected + $this->maxVisible);
            while ($target >= 0 && $this->filtered[$target]['type'] !== 'item') {
                $target--;
            }
            if ($target >= 0) {
                $this->selected = $target;
            }
        } elseif (Keys::isEscape($data)) {
            if ($this->onClose !== null) {
                ($this->onClose)();
            }
        } elseif (Keys::isCtrlC($data)) {
            if ($this->onExit !== null) {
                ($this->onExit)();
            }
        } elseif (Keys::isTab($data)) {
            if ($this->projectModeAvailable) {
                $this->writeScope = $this->writeScope === 'global' ? 'project' : 'global';
                $this->rebuild();
                $this->filter($this->search->value());
            }
        } elseif ($data === ' ' || Keys::isEnter($data)) {
            $entry = $this->filtered[$this->selected] ?? null;

            if ($entry !== null && $entry['type'] === 'item' && ($this->writeScope === 'project' || $entry['item']['metadata']->scope === 'user')) {
                $enabled = $this->toggle($entry['item']);

                if ($enabled !== null) {
                    $this->setEnabled($entry['item'], $enabled);

                    if ($this->onChange !== null) {
                        ($this->onChange)();
                    }
                }
            }
        } else {
            $this->search->handleInput($data);
            $this->filter($this->search->value());
        }
    }

    // ---- the toggles ----------------------------------------------------------------------------

    /** @param array<string, mixed> $item */
    private function toggle(array $item): ?bool
    {
        if ($this->writeScope === 'project') {
            $state = $this->nextOverrideState($item);

            if (!$this->setProjectOverride($item, $state)) {
                return null;
            }

            return $state === 'inherit' ? $this->inheritedEnabled($item) : $state === 'load';
        }

        $enabled = !$item['enabled'];

        if ($item['metadata']->source === 'builtin') {
            $this->writeBuiltinEntry($item, $enabled ? 'load' : 'unload', $item['metadata']->scope === 'project' ? 'project' : 'user');
        } else {
            $this->writeGlobalPattern($item, $enabled);
        }

        return $enabled;
    }

    /**
     * Upstream's `toggleTopLevelResource()` and `setProjectTopLevelOverride()` for a built-in:
     * that file's `extensions` lose every switch on `builtin:<name>`, then gain `+` or `-` for it —
     * or nothing, for a project going back to `inherit`. A built-in needs no plain entry first.
     *
     * @param array<string, mixed> $item
     * @param 'inherit'|'load'|'unload' $state
     */
    private function writeBuiltinEntry(array $item, string $state, string $scope): void
    {
        $updated = array_values(array_filter(
            $this->settings->extensionEntries($scope),
            fn (string $entry): bool => !(BuiltinExtensions::isOverride($entry) && $this->target($entry) === $item['path']),
        ));

        if ($state !== 'inherit') {
            $updated[] = ($state === 'load' ? '+' : '-') . $item['path'];
        }

        $this->settings->setExtensionEntries($updated, $scope);
    }

    /**
     * Upstream's `togglePackageResource()`: the user's entry for the package gains `+path` or
     * `-path` for this file, any earlier verdict on it removed; an entry left with no filters
     * goes back to its string form.
     *
     * @param array<string, mixed> $item
     */
    private function writeGlobalPattern(array $item, bool $enabled): void
    {
        $scope = $item['metadata']->scope === 'project' ? 'project' : 'user';
        $packages = $this->settings->packages($scope);
        $index = $this->findPackage($packages, $item['metadata']->source, $scope, $scope);

        if ($index === null) {
            return;
        }

        $package = is_string($packages[$index]) ? ['source' => $packages[$index]] : $packages[$index];
        $pattern = $this->packagePattern($item);
        $current = is_array($package[$item['resourceType']] ?? null) ? $package[$item['resourceType']] : [];
        $updated = array_values(array_filter($current, fn (string $entry): bool => $this->target($entry) !== $pattern));
        $updated[] = ($enabled ? '+' : '-') . $pattern;
        $package[$item['resourceType']] = $updated;
        $packages[$index] = $this->compact($package);
        $this->settings->setPackages($packages, $scope);
    }

    /**
     * Upstream's `setProjectPackageOverride()`: the project's entry for the package — made as an
     * `autoload: false` delta when the project has none — gains the verdict, or loses it on
     * `inherit`; an emptied delta is removed, an emptied own entry goes back to its string form.
     *
     * @param array<string, mixed> $item
     * @param 'inherit'|'load'|'unload' $state
     */
    private function setProjectOverride(array $item, string $state): bool
    {
        if ($item['metadata']->source === 'builtin') {
            $this->writeBuiltinEntry($item, $state, 'project');

            return true;
        }

        $packages = $this->settings->packages('project');
        $itemScope = $item['metadata']->scope === 'project' ? 'project' : 'user';
        $index = $this->findPackage($packages, $item['metadata']->source, $itemScope, 'project');

        if ($index === null) {
            if ($state === 'inherit') {
                return false;
            }

            $packages[] = $this->overrideSource($item);
            $index = count($packages) - 1;
        }

        $package = is_string($packages[$index]) ? ['source' => $packages[$index]] : $packages[$index];
        $pattern = $this->packagePattern($item);
        $current = is_array($package[$item['resourceType']] ?? null) ? $package[$item['resourceType']] : [];
        $updated = array_values(array_filter($current, fn (string $entry): bool => $this->target($entry) !== $pattern));

        if ($state !== 'inherit') {
            $updated[] = ($state === 'load' ? '+' : '-') . $pattern;
        }

        if ($updated === []) {
            unset($package[$item['resourceType']]);
        } else {
            $package[$item['resourceType']] = $updated;
        }

        $hasFilters = false;

        foreach (ResolvedPaths::TYPES as $type) {
            $hasFilters = $hasFilters || isset($package[$type]);
        }

        if (!$hasFilters && ($package['autoload'] ?? null) === false) {
            array_splice($packages, $index, 1);
        } else {
            $packages[$index] = $hasFilters ? $package : $package['source'];
        }

        $this->settings->setPackages($packages, 'project');

        return true;
    }

    /** @param array<string, mixed> $item */
    private function nextOverrideState(array $item): string
    {
        $state = $this->overrideState($item);
        $inherited = $this->inheritedEnabled($item);

        return match ($state) {
            'inherit' => $inherited ? 'unload' : 'load',
            'unload' => $inherited ? 'load' : 'inherit',
            default => $inherited ? 'inherit' : 'unload',
        };
    }

    /**
     * Upstream's `getProjectOverrideState()`: what the project's entry says about this file —
     * `inherit` when nothing, `load` for a `+`, `unload` for a `-` or `!` or an empty list on an
     * own (non-delta) entry.
     *
     * @param array<string, mixed> $item
     */
    private function overrideState(array $item): string
    {
        if ($this->writeScope !== 'project') {
            return 'inherit';
        }

        // Upstream's top-level arm: the project's last switch on this path.
        if ($item['metadata']->source === 'builtin') {
            $state = 'inherit';

            foreach ($this->settings->extensionEntries('project') as $entry) {
                if (BuiltinExtensions::isOverride($entry) && $this->target($entry) === $item['path']) {
                    $state = str_starts_with($entry, '+') ? 'load' : 'unload';
                }
            }

            return $state;
        }

        $packages = $this->settings->packages('project');
        $index = $this->findPackage($packages, $item['metadata']->source, $item['metadata']->scope === 'project' ? 'project' : 'user', 'project');
        $package = $index === null ? null : $packages[$index];

        if (!is_array($package) || !isset($package[$item['resourceType']]) || !is_array($package[$item['resourceType']])) {
            return 'inherit';
        }

        $entries = $package[$item['resourceType']];

        if ($entries === [] && ($package['autoload'] ?? null) !== false) {
            return 'unload';
        }

        $pattern = $this->packagePattern($item);
        $state = 'inherit';

        foreach ($entries as $entry) {
            if (!is_string($entry) || $this->target($entry) !== $pattern) {
                continue;
            }

            $state = str_starts_with($entry, '!') || str_starts_with($entry, '-') ? 'unload' : 'load';
        }

        return $state;
    }

    /** @param array<string, mixed> $item */
    private function inheritedEnabled(array $item): bool
    {
        return $this->inheritedEnabled[$this->itemKey($item)] ?? ($item['metadata']->scope === 'user' ? $item['enabled'] : true);
    }

    /** @param array<string, mixed> $item */
    private function isInheritedGlobal(array $item): bool
    {
        return $item['metadata']->scope === 'user' || isset($this->inheritedEnabled[$this->itemKey($item)]);
    }

    /** @param array<string, mixed> $item */
    private function isDimmed(array $item): bool
    {
        return $this->writeScope === 'project' && $this->isInheritedGlobal($item) && $this->overrideState($item) === 'inherit';
    }

    /** @param array<string, mixed> $item */
    private function checkbox(array $item): string
    {
        $theme = Themes::theme();

        if ($this->writeScope === 'project') {
            return match ($this->overrideState($item)) {
                'load' => $theme->fg('success', '[+]'),
                'unload' => $theme->fg('warning', '[-]'),
                default => $theme->fg('dim', $item['enabled'] ? '[x]' : '[ ]'),
            };
        }

        return $item['enabled'] ? $theme->fg('success', '[x]') : $theme->fg('dim', '[ ]');
    }

    /** @param array<string, mixed> $item */
    private function suffix(array $item): string
    {
        if ($this->writeScope !== 'project') {
            return '';
        }

        $theme = Themes::theme();

        return match ($this->overrideState($item)) {
            'load' => $theme->fg('muted', '  project load'),
            'unload' => $theme->fg('muted', '  project unload'),
            default => $this->isInheritedGlobal($item) ? $theme->fg('dim', '  inherited global') : '',
        };
    }

    // ---- packages in the settings ---------------------------------------------------------------

    /**
     * Upstream's `findMatchingPackageSource()`: the entry for this package in a scope's list, by
     * source string, or for local paths by where they resolve to from each scope's base.
     *
     * @param list<string|array<string, mixed>> $packages
     */
    private function findPackage(array $packages, string $source, string $sourceScope, string $listScope): ?int
    {
        foreach ($packages as $index => $package) {
            $candidate = is_string($package) ? $package : (string) $package['source'];

            if ($candidate === $source) {
                return $index;
            }

            if (PackageSource::isLocalPath($source) && PackageSource::isLocalPath($candidate)
                && Paths::resolve($source, $this->baseDir($sourceScope)) === Paths::resolve($candidate, $this->baseDir($listScope))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Upstream's `createPackageOverrideSource()`: a project delta over a user package, its local
     * path rewritten relative to `.pig/`.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function overrideSource(array $item): array
    {
        $source = $item['metadata']->source;

        if (!PackageSource::parse($source) instanceof LocalSource) {
            return ['source' => $source, 'autoload' => false];
        }

        $resolved = Paths::resolve($source, $this->baseDir($item['metadata']->scope === 'project' ? 'project' : 'user'));
        $relative = Paths::relativeTo($this->baseDir('project'), $resolved);

        return ['source' => $relative === '' ? '.' : $relative, 'autoload' => false];
    }

    /**
     * @param array<string, mixed> $package
     * @return string|array<string, mixed>
     */
    private function compact(array $package): string|array
    {
        foreach (ResolvedPaths::TYPES as $type) {
            if (isset($package[$type])) {
                return $package;
            }
        }

        return ($package['autoload'] ?? null) === false ? $package : (string) $package['source'];
    }

    private function baseDir(string $scope): string
    {
        return $scope === 'project' ? rtrim($this->cwd, '/') . '/.pig' : $this->home;
    }

    /**
     * Upstream's `getPackageResourcePattern()`: the file relative to the package root.
     *
     * @param array<string, mixed> $item
     */
    private function packagePattern(array $item): string
    {
        return Paths::relative($item['path'], $item['metadata']->baseDir ?? dirname($item['path']));
    }

    private function target(string $entry): string
    {
        return str_starts_with($entry, '!') || str_starts_with($entry, '+') || str_starts_with($entry, '-') ? substr($entry, 1) : $entry;
    }

    /** @param array<string, mixed> $item */
    private function itemKey(array $item): string
    {
        return $item['resourceType'] . ':' . (realpath($item['path']) ?: $item['path']);
    }

    // ---- the list -------------------------------------------------------------------------------

    /**
     * Upstream's `buildGroups()`: one group per package and scope, a subgroup per type, items by
     * display name.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(ResolvedPaths $resolved): array
    {
        $groups = [];

        foreach (ResolvedPaths::TYPES as $type) {
            foreach ($resolved->of($type) as $resource) {
                $metadata = $resource->metadata;
                $key = "{$metadata->origin}:{$metadata->scope}:{$metadata->source}:" . ($metadata->baseDir ?? '');
                // Upstream's `getGroupLabel()`: a built-in's group is "Built-in", or the project's.
                $label = $metadata->source === 'builtin'
                    ? ($metadata->scope === 'user' ? 'Built-in' : 'Built-in (project override)')
                    : "{$metadata->source} ({$metadata->scope})";
                $groups[$key] ??= ['key' => $key, 'label' => $label, 'scope' => $metadata->scope, 'source' => $metadata->source, 'subgroups' => []];
                $groups[$key]['subgroups'][$type] ??= ['type' => $type, 'label' => self::TYPE_LABELS[$type], 'items' => []];
                $groups[$key]['subgroups'][$type]['items'][] = [
                    'path' => $resource->path,
                    'enabled' => $resource->enabled,
                    'metadata' => $metadata,
                    'resourceType' => $type,
                    'displayName' => self::displayName($resource, $type),
                ];
            }
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => ($a['scope'] === 'user' ? 0 : 1) <=> ($b['scope'] === 'user' ? 0 : 1) ?: strcmp($a['source'], $b['source']));

        foreach ($groups as &$group) {
            $group['subgroups'] = array_values($group['subgroups']);

            foreach ($group['subgroups'] as &$subgroup) {
                usort($subgroup['items'], static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
            }
            unset($subgroup);
        }
        unset($group);

        return $groups;
    }

    private static function displayName(ResolvedResource $resource, string $type): string
    {
        if ($resource->metadata->source === 'builtin') {
            return substr($resource->path, strlen(BuiltinExtensions::PREFIX));
        }

        $file = basename($resource->path);
        $parent = basename(dirname($resource->path));

        if ($type === 'extensions' && $parent !== 'extensions') {
            return "{$parent}/{$file}";
        }

        if ($type === 'skills' && $file === 'SKILL.md') {
            return $parent;
        }

        return $file;
    }

    private function rebuild(): void
    {
        $this->flat = [];

        foreach ($this->groupsByScope[$this->writeScope] as $group) {
            $this->flat[] = ['type' => 'group', 'group' => $group];

            foreach ($group['subgroups'] as $subgroup) {
                $this->flat[] = ['type' => 'subgroup', 'subgroup' => $subgroup, 'group' => $group];

                foreach ($subgroup['items'] as $item) {
                    $this->flat[] = ['type' => 'item', 'item' => $item];
                }
            }
        }

        $this->filtered = $this->flat;
        $this->selectFirst();
    }

    private function filter(string $query): void
    {
        $query = strtolower(trim($query));

        if ($query === '') {
            $this->filtered = $this->flat;
            $this->selectFirst();

            return;
        }

        $matches = static fn (array $item): bool => str_contains(strtolower($item['displayName']), $query)
            || str_contains($item['resourceType'], $query)
            || str_contains(strtolower($item['path']), $query);

        $this->filtered = [];

        foreach ($this->groupsByScope[$this->writeScope] as $group) {
            $groupEntries = [];

            foreach ($group['subgroups'] as $subgroup) {
                $items = array_values(array_filter($subgroup['items'], $matches));

                if ($items !== []) {
                    $groupEntries[] = ['type' => 'subgroup', 'subgroup' => $subgroup, 'group' => $group];

                    foreach ($items as $item) {
                        $groupEntries[] = ['type' => 'item', 'item' => $item];
                    }
                }
            }

            if ($groupEntries !== []) {
                $this->filtered = [...$this->filtered, ['type' => 'group', 'group' => $group], ...$groupEntries];
            }
        }

        $this->selectFirst();
    }

    private function selectFirst(): void
    {
        foreach ($this->filtered as $index => $entry) {
            if ($entry['type'] === 'item') {
                $this->selected = $index;

                return;
            }
        }

        $this->selected = 0;
    }

    private function nextItem(int $from, int $direction): int
    {
        for ($index = $from + $direction; $index >= 0 && $index < count($this->filtered); $index += $direction) {
            if ($this->filtered[$index]['type'] === 'item') {
                return $index;
            }
        }

        return $from;
    }

    /** @param array<string, mixed> $item */
    private function setEnabled(array $item, bool $enabled): void
    {
        $same = static fn (array $candidate): bool => $candidate['path'] === $item['path'] && $candidate['resourceType'] === $item['resourceType'];

        foreach ($this->groupsByScope[$this->writeScope] as &$group) {
            foreach ($group['subgroups'] as &$subgroup) {
                foreach ($subgroup['items'] as &$candidate) {
                    if ($same($candidate)) {
                        $candidate['enabled'] = $enabled;
                    }
                }
                unset($candidate);
            }
            unset($subgroup);
        }
        unset($group);

        // Rebuilt from the groups, with the cursor where it was: upstream's `updateItem()`
        // edits in place and never moves it.
        $selected = $this->selected;
        $this->rebuild();
        $this->filter($this->search->value());
        $this->selected = min($selected, max(0, count($this->filtered) - 1));
    }
}
