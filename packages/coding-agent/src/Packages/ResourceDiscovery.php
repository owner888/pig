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
 *   the root is a skill on its own (upstream's `pi` mode).
 * - prompts: every `.md` under the root; themes: every `.json`.
 *
 * Dot entries, `vendor` and `node_modules` are skipped. Upstream also honours `.gitignore`,
 * `.ignore` and `.fdignore` on the way down, with the `ignore` package; pig has no gitignore
 * matcher (see the `--ignore-file` trap) and does not read them.
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

    /** @return list<string> */
    private static function skills(string $dir, string $root): array
    {
        if (is_file("{$dir}/SKILL.md")) {
            return ["{$dir}/SKILL.md"];
        }

        $entries = [];

        foreach (self::listing($dir) as $name => $path) {
            if (is_file($path)) {
                if ($dir === $root && str_ends_with($name, '.md')) {
                    $entries[] = $path;
                }
            } elseif (is_dir($path)) {
                $entries = [...$entries, ...self::skills($path, $root)];
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
