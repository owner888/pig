<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Pig\CodingAgent\Cli\SelfUpdate;

/**
 * Upstream's built-in extensions (`extensions/index.ts`): `builtin:llama.cpp`, `builtin:codemode`,
 * `builtin:tool-search` and `builtin:mcp`, which load by default from pig itself — the folders of
 * the same purpose under `extensions/` in the package — after every other extension.
 *
 * - **Switched in the `extensions` setting**, as upstream's: `-builtin:mcp` (or `!builtin:mcp`)
 *   in the person's settings turns one off; a `+`, `-` or `!` entry for it in the project's
 *   settings decides instead (`enabled()`).
 * - **`-e builtin:<name>`** loads one for this run (`pathOf()`); `--no-extensions` loads none but
 *   those, and `--no-mcp` leaves `mcp` out.
 * - **Replaceable**: `codemode`, `tool-search` and `mcp` give way to another extension that
 *   registers one of their tools or commands (`ExtensionLoader::omitReplaced()`).
 * - **A copy of one elsewhere is not loaded**: the folders were once copied into
 *   `~/.pig/agent/extensions` by `pig update`, and such a copy goes stale (`isStaleCopy()`).
 */
final class BuiltinExtensions
{
    public const string PREFIX = 'builtin:';

    /** Upstream's names, in upstream's order, and pig's folder for each. */
    public const array FOLDERS = [
        'llama.cpp' => 'pig-llama',
        'codemode' => 'pig-codemode',
        'tool-search' => 'pig-tool-search',
        'mcp' => 'pig-mcp',
    ];

    /** Upstream's `replaceable: true`. */
    public const array REPLACEABLE = ['codemode', 'tool-search', 'mcp'];

    /** The entry file of `builtin:<name>`, or null when there is no such built-in. */
    public static function pathOf(string $source): ?string
    {
        $name = str_starts_with($source, self::PREFIX) ? substr($source, strlen(self::PREFIX)) : $source;
        $folder = self::FOLDERS[$name] ?? null;
        $root = SelfUpdate::coreExtensionsDir();

        return $folder === null || $root === null ? null : "{$root}/{$folder}/index.php";
    }

    /** The `builtin:<name>` a folder or entry file is, or null when it is none of them. */
    public static function nameOf(string $path): ?string
    {
        $real = realpath($path);

        foreach (array_keys(self::FOLDERS) as $name) {
            $entry = self::pathOf($name);

            if ($entry !== null && $real !== false && ($real === realpath($entry) || $real === realpath(dirname($entry)))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A folder named as a built-in's that is not the built-in itself — a copy `pig update` made, or
     * one somebody kept. Not loaded: the built-in is, and the copy is whatever version it was.
     */
    public static function isStaleCopy(string $path): bool
    {
        $folder = basename(basename($path) === 'index.php' ? dirname($path) : $path);

        return in_array($folder, self::FOLDERS, true) && self::nameOf($path) === null;
    }

    /**
     * Upstream's built-in resolution: each built-in on unless the person's `extensions` turn it off
     * (`isEnabledByOverrides()`), a matching project entry deciding over theirs
     * (`applyAutoloadDisabledPatterns()`, the last match winning). The entry files, in upstream's
     * order, less any in `$disabled` (built-in names).
     *
     * @param list<string> $userEntries the person's `extensions` setting
     * @param list<string> $projectEntries the project's, empty until it is trusted
     * @param list<string> $disabled
     * @return list<string>
     */
    public static function enabled(array $userEntries, array $projectEntries, array $disabled = []): array
    {
        $paths = [];

        foreach (array_keys(self::FOLDERS) as $name) {
            $source = self::PREFIX . $name;
            $enabled = self::projectDecision($source, $projectEntries) ?? self::enabledByOverrides($source, $userEntries);
            $path = self::pathOf($name);

            if ($enabled && $path !== null && !in_array($name, $disabled, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Every built-in as `pig config` lists it: `builtin:<name>`, on or off by the same rules as
     * `enabled()`, and scoped `project` when the project's entries decide it — upstream's
     * "Built-in (project override)" group.
     *
     * @param list<string> $userEntries
     * @param list<string> $projectEntries
     * @return list<\Pig\CodingAgent\Packages\ResolvedResource>
     */
    public static function resources(array $userEntries, array $projectEntries): array
    {
        $resources = [];

        foreach (array_keys(self::FOLDERS) as $name) {
            $source = self::PREFIX . $name;
            $project = self::projectDecision($source, $projectEntries);
            $resources[] = new \Pig\CodingAgent\Packages\ResolvedResource(
                $source,
                $project ?? self::enabledByOverrides($source, $userEntries),
                new \Pig\CodingAgent\Packages\PathMetadata('builtin', $project === null ? 'user' : 'project', 'top-level'),
            );
        }

        return $resources;
    }

    /** Whether an `extensions` entry is one of upstream's override patterns rather than a path. */
    public static function isOverride(string $entry): bool
    {
        return str_starts_with($entry, '!') || str_starts_with($entry, '+') || str_starts_with($entry, '-');
    }

    /** @param list<string> $entries */
    private static function projectDecision(string $source, array $entries): ?bool
    {
        $decision = null;

        foreach ($entries as $entry) {
            if (!self::isOverride($entry)) {
                continue;
            }

            $target = substr($entry, 1);
            $exact = $entry[0] === '+' || $entry[0] === '-';

            if ($exact ? $target === $source : fnmatch($target, $source)) {
                $decision = $entry[0] === '+';
            }
        }

        return $decision;
    }

    /**
     * Upstream's `isEnabledByOverrides()`: on, unless a `!` pattern matches; a `+` exact entry turns
     * it back on, and a `-` exact entry off, over both.
     *
     * @param list<string> $entries
     */
    private static function enabledByOverrides(string $source, array $entries): bool
    {
        $enabled = true;
        $of = static fn (string $sign): array => array_map(
            static fn (string $entry): string => substr($entry, 1),
            array_values(array_filter($entries, static fn (string $entry): bool => str_starts_with($entry, $sign))),
        );

        foreach ($of('!') as $pattern) {
            if (fnmatch($pattern, $source)) {
                $enabled = false;
            }
        }

        if (in_array($source, $of('+'), true)) {
            $enabled = true;
        }

        if (in_array($source, $of('-'), true)) {
            $enabled = false;
        }

        return $enabled;
    }
}
