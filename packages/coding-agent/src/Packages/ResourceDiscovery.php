<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * Which files in a directory are resources of a type — upstream's `collectResourceFiles()` and
 * the three walkers behind it, for PHP:
 *
 * - extensions: a `.php` file, or a directory that is one extension — `composer.json` naming
 *   `extra.pig.extensions`, else an `index.php` (upstream's `index.ts`). A directory with neither
 *   is walked for both.
 * - skills: a `SKILL.md` claims its directory and the walk stops there; a `.md` directly under
 *   the root is a skill on its own (upstream's `pi` mode; `autoSkills()` has the `agents` one).
 * - prompts: every `.md` under the root; themes: every `.json`. A conventional directory loads its
 *   direct children only, which is `autoFiles()`.
 *
 * Dot entries, `vendor` and `node_modules` are skipped. Upstream also honours `.gitignore`,
 * `.ignore` and `.fdignore` on the way down, with the `ignore` package; pig does not, on purpose:
 * a git-installed package is a clone and holds no gitignored file to hide, and a local-path
 * package with its author's drafts in it names what to leave out with the manifest's `!`
 * patterns. See docs/dev/decisions.md.
 */
final class ResourceDiscovery
{
    private const array SKIP = ['vendor', 'node_modules'];

    /** @return list<string> absolute paths */
    public static function collect(string $dir, string $type): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return match ($type) {
            'extensions' => self::extensions($dir),
            'skills' => self::skills($dir, $dir),
            'prompts' => self::files($dir, '.md'),
            'themes' => self::files($dir, '.json'),
            default => throw new PackageError("Unknown resource type: {$type}"),
        };
    }

    /**
     * Upstream's `resolveExtensionEntries()`: the entries a directory that *is* an extension
     * declares, or null when it is only a directory.
     *
     * @return list<string>|null
     */
    public static function extensionEntries(string $dir): ?array
    {
        $manifest = PackageManifest::read($dir);

        if ($manifest?->extensions !== null && $manifest->extensions !== []) {
            $entries = [];

            foreach ($manifest->extensions as $entry) {
                $path = "{$dir}/" . ltrim($entry, './');

                if (file_exists($path)) {
                    $entries[] = $path;
                }
            }

            if ($entries !== []) {
                return $entries;
            }
        }

        return is_file("{$dir}/index.php") ? ["{$dir}/index.php"] : null;
    }

    /** @return list<string> */
    private static function extensions(string $dir): array
    {
        $own = self::extensionEntries($dir);

        if ($own !== null) {
            return $own;
        }

        $entries = [];

        foreach (self::listing($dir) as $name => $path) {
            if (is_file($path)) {
                if (str_ends_with($name, '.php')) {
                    $entries[] = $path;
                }
            } elseif (is_dir($path)) {
                $entries = [...$entries, ...(self::extensionEntries($path) ?? [])];
            }
        }

        return $entries;
    }

    /**
     * Upstream's `collectAutoSkillEntries()`: a conventional skills directory. `pi` (the agent
     * directory's and `.pig/`'s) takes a `.md` directly under the root as a skill of its own; `agents`
     * (`.agents/skills`, the Agent Skills layout) takes one only below the root, in a directory with
     * no `SKILL.md`. Either way a `SKILL.md` claims its directory and the walk stops there.
     *
     * @param 'pi'|'agents' $mode
     * @return list<string>
     */
    public static function autoSkills(string $dir, string $mode): array
    {
        return is_dir($dir) ? self::skills($dir, $dir, $mode) : [];
    }

    /**
     * Upstream's `collectAutoPromptEntries()` and `collectAutoThemeEntries()`: the direct children of
     * a conventional directory with the suffix, not its subdirectories.
     *
     * @return list<string>
     */
    public static function autoFiles(string $dir, string $suffix): array
    {
        $entries = [];

        foreach (self::listing($dir) as $name => $path) {
            if (is_file($path) && str_ends_with($name, $suffix)) {
                $entries[] = $path;
            }
        }

        return $entries;
    }

    /**
     * Upstream's `collectAncestorAgentsSkillDirs()`: `<dir>/.agents/skills` for $cwd and each of its
     * ancestors, stopping at the repository root (the nearest directory with a `.git`) when there is
     * one, at `/` when there is not. Existing or not — the caller's walk skips what is not there.
     *
     * @return list<string>
     */
    public static function ancestorAgentsSkillDirs(string $cwd): array
    {
        $start = \Pig\CodingAgent\Tools\Paths::resolve($cwd, '/');
        $repoRoot = null;

        for ($dir = $start; ; $dir = dirname($dir)) {
            if (file_exists(rtrim($dir, '/') . '/.git')) {
                $repoRoot = $dir;

                break;
            }

            if (dirname($dir) === $dir) {
                break;
            }
        }

        $dirs = [];

        for ($dir = $start; ; $dir = dirname($dir)) {
            $dirs[] = rtrim($dir, '/') . '/.agents/skills';

            if (($repoRoot !== null && $dir === $repoRoot) || dirname($dir) === $dir) {
                break;
            }
        }

        return $dirs;
    }

    /**
     * Upstream's `collectSkillEntries()`.
     *
     * @param 'pi'|'agents' $mode
     * @return list<string>
     */
    private static function skills(string $dir, string $root, string $mode = 'pi'): array
    {
        if (is_file("{$dir}/SKILL.md")) {
            return ["{$dir}/SKILL.md"];
        }

        $entries = [];

        foreach (self::listing($dir) as $name => $path) {
            if (is_file($path)) {
                if (str_ends_with($name, '.md') && ($mode === 'pi' ? $dir === $root : $dir !== $root)) {
                    $entries[] = $path;
                }
            } elseif (is_dir($path)) {
                $entries = [...$entries, ...self::skills($path, $root, $mode)];
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private static function files(string $dir, string $suffix): array
    {
        $entries = [];

        foreach (self::listing($dir) as $name => $path) {
            if (is_file($path)) {
                if (str_ends_with($name, $suffix)) {
                    $entries[] = $path;
                }
            } elseif (is_dir($path)) {
                $entries = [...$entries, ...self::files($path, $suffix)];
            }
        }

        return $entries;
    }

    /** @return iterable<string, string> name => path, sorted, without the skipped names */
    private static function listing(string $dir): iterable
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return;
        }

        $names = scandir($dir);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || in_array($name, self::SKIP, true)) {
                continue;
            }

            yield $name => "{$dir}/{$name}";
        }
    }
}
