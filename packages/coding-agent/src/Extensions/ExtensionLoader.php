<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Tools\Paths;
use Throwable;

/**
 * Finding and loading extension files.
 *
 * An extension is a PHP file that returns a callable. The callable receives an `ExtensionApi`
 * and can register slash commands, agent tools, lifecycle event handlers, message renderers,
 * and interact with the UI.
 *
 * Locations searched in order — the person's first, so they are loaded before the project is
 * trusted and can be asked `project_trust` about it:
 * 1. Global extensions: ~/.pig/agent/extensions/*.php and subdirectories with index.php
 * 2. Configured extra paths from the person's settings
 * 3. Explicit CLI paths passed via --extension
 * 4. Project extensions: <cwd>/.pig/extensions/*.php and subdirectories with index.php
 * 5. Project root extensions: <cwd>/extensions/*.php and subdirectories with index.php
 * 6. Configured extra paths from the project's settings
 */
final class ExtensionLoader
{
    /**
     * @param list<string> $configured extra paths from settings — the person's, since a project's
     *                                 settings wait for trust like its extensions do
     * @param list<string> $cliPaths    explicit paths passed on the command line
     * @param bool|Closure(list<LoadedExtension>): bool $projectTrusted whether the project roots
     *        may be loaded; a closure is asked once everything else has loaded and is handed it —
     *        upstream's `resolveProjectTrust({extensionsResult})`, which is how `project_trust`
     *        reaches the extensions that can answer it. Two phases of one load rather than
     *        upstream's two loads, because a factory run twice is an MCP server connected twice.
     * @param list<string> $disabled    extension names (their directory or file name) not to load
     * @param (Closure(): list<string>)|null $projectConfigured the project's own `extensions`
     *        setting, asked only once it is trusted
     * @param (Closure(bool): list<string>)|null $packageExtensions the extensions the configured
     *        packages provide (`PackageManager::resolve()`), asked once trust is decided and
     *        loaded last — a package's resource ranks after every local one, as upstream ranks it
     * @return array{0: list<LoadedExtension>, 1: list<ExtensionError>}
     */
    public static function load(
        string $cwd,
        array $configured = [],
        array $cliPaths = [],
        ?string $home = null,
        ?Auth $auth = null,
        bool|Closure $projectTrusted = true,
        array $disabled = [],
        ?Closure $projectConfigured = null,
        ?Closure $packageExtensions = null,
    ): array {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = self::discover($home . '/extensions');

        foreach ([...$configured, ...$cliPaths] as $path) {
            $paths[] = Paths::resolve($path, $cwd);
        }

        $extensions = [];
        $errors = [];
        $seen = [];
        $events = new EventBus();

        $loadAll = static function (array $paths) use (&$extensions, &$errors, &$seen, $events, $cwd, $auth, $disabled): void {
            foreach ($paths as $path) {
                $real = realpath($path) ?: $path;

                if (isset($seen[$real])) {
                    continue;
                }

                // `--no-mcp`: upstream's `disabledBuiltinExtensions: ["mcp"]`. By name, which is
                // the directory's, so the same switch reaches the copy under
                // `~/.pig/agent/extensions` and one in the project. Not loaded at all, rather than
                // loaded and told to do nothing — an extension that connects servers in a fiber
                // has no "do nothing" to be told.
                if (in_array(self::nameOf($path), $disabled, true)) {
                    continue;
                }

                $seen[$real] = true;

                [$loaded, $error] = self::one($path, $real, $cwd, $auth, $events);

                if ($error !== null) {
                    $errors[] = $error;
                }

                if ($loaded !== null) {
                    $extensions[$loaded->name] = $loaded;
                }
            }
        };

        $loadAll($paths);

        if ($projectTrusted instanceof Closure) {
            $projectTrusted = $projectTrusted(array_values($extensions));
        }

        // Both project roots only for a project somebody said yes to — see `ProjectTrust`. An
        // extension is `require`d into this process, which is the whole reason the question exists.
        if ($projectTrusted) {
            $project = [
                ...self::discover($cwd . '/.pig/extensions'),
                ...self::discover($cwd . '/extensions'),
            ];

            foreach ($projectConfigured === null ? [] : $projectConfigured() as $path) {
                $project[] = Paths::resolve($path, $cwd);
            }

            $loadAll($project);
        }

        if ($packageExtensions !== null) {
            $loadAll(self::entryFiles($packageExtensions($projectTrusted)));
        }

        return [array_values($extensions), $errors];
    }

    /**
     * A package may name a directory as one extension (a local path with none of the package
     * shapes, as `-e ./dir` does); its entry is the `index.php` inside, as upstream's is `index.ts`.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function entryFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                if (is_file("{$path}/index.php")) {
                    $files[] = "{$path}/index.php";
                }
            } else {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Scan a directory for *.php files and subdirectories with index.php.
     *
     * @return list<string>
     */
    public static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.php', GLOB_NOSORT) ?: [];
        $dirs = glob($directory . '/*/index.php', GLOB_NOSORT) ?: [];

        $all = [...$files, ...$dirs];
        sort($all);

        return $all;
    }

    /**
     * Derive a human-friendly name for an extension.
     */
    public static function nameOf(string $path): string
    {
        $base = basename($path);
        if ($base === 'index.php') {
            return basename(dirname($path));
        }

        return preg_replace('/\.php$/', '', $base) ?? $base;
    }

    /**
     * Load a single extension file.
     *
     * @return array{0: LoadedExtension|null, 1: ExtensionError|null}
     */
    private static function one(string $path, string $resolved, string $cwd, ?Auth $auth, EventBus $events): array
    {
        if (!is_readable($path)) {
            return [null, new ExtensionError($path, 'load', 'not a readable file')];
        }

        $symbolError = HookLoader::checkTopLevelSymbols($resolved);

        if ($symbolError !== null) {
            return [null, new ExtensionError($path, 'load', $symbolError)];
        }

        $name = self::nameOf($path);
        $api = new ExtensionApi($cwd, $path, $name, $auth, events: $events);

        // A package that ships its own libraries ships its own `vendor/` — pig runs no composer
        // (see `PackageManager`). Beside the file, or one level up for a file under `extensions/`.
        foreach ([dirname($resolved), dirname($resolved, 2)] as $root) {
            if (is_file("{$root}/vendor/autoload.php")) {
                require_once "{$root}/vendor/autoload.php";

                break;
            }
        }

        ob_start();

        try {
            $factory = self::evaluate($resolved);
        } catch (Throwable $error) {
            ob_end_clean();

            return [null, new ExtensionError($path, 'load', self::describe($error))];
        }

        $printed = ob_get_clean();

        if (is_string($printed) && trim($printed) !== '') {
            return [null, new ExtensionError($path, 'load', 'printed to standard output while loading: ' . trim($printed))];
        }

        if (!is_callable($factory)) {
            return [null, new ExtensionError($path, 'load', 'must return a callable, got ' . get_debug_type($factory))];
        }

        try {
            $factory($api);
        } catch (Throwable $error) {
            return [null, new ExtensionError($path, 'load', self::describe($error))];
        }

        return [new LoadedExtension($path, $resolved, $name, $api), null];
    }

    private static function evaluate(string $path): mixed
    {
        return (static fn (): mixed => require $path)();
    }

    private static function describe(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
