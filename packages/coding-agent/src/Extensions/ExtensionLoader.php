<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

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
 * Locations searched in order:
 * 1. Global extensions: ~/.pig/agent/extensions/*.php and subdirectories with index.php
 * 2. Project extensions: <cwd>/.pig/extensions/*.php and subdirectories with index.php
 * 3. Project root extensions: <cwd>/extensions/*.php and subdirectories with index.php
 * 4. Configured extra paths from settings
 * 5. Explicit CLI paths passed via --extension
 */
final class ExtensionLoader
{
    /**
     * @param list<string> $configured extra paths from settings
     * @param list<string> $cliPaths    explicit paths passed on the command line
     * @param list<string> $disabled    extension names (their directory or file name) not to load
     * @return array{0: list<LoadedExtension>, 1: list<ExtensionError>}
     */
    public static function load(
        string $cwd,
        array $configured = [],
        array $cliPaths = [],
        ?string $home = null,
        ?Auth $auth = null,
        bool $projectTrusted = true,
        array $disabled = [],
    ): array {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        // Both project roots only for a project somebody said yes to — see `ProjectTrust`. An
        // extension is `require`d into this process, which is the whole reason the question exists.
        $paths = [
            ...self::discover($home . '/extensions'),
            ...($projectTrusted ? self::discover($cwd . '/.pig/extensions') : []),
            ...($projectTrusted ? self::discover($cwd . '/extensions') : []),
        ];

        foreach ($configured as $path) {
            $paths[] = Paths::resolve($path, $cwd);
        }

        foreach ($cliPaths as $path) {
            $paths[] = Paths::resolve($path, $cwd);
        }

        $extensions = [];
        $errors = [];
        $seen = [];
        $events = new EventBus();

        foreach ($paths as $path) {
            $real = realpath($path) ?: $path;

            if (isset($seen[$real])) {
                continue;
            }

            // `--no-mcp`: upstream's `disabledBuiltinExtensions: ["mcp"]`. By name, which is the
            // directory's, so the same switch reaches the copy under `~/.pig/agent/extensions` and
            // one in the project. Not loaded at all, rather than loaded and told to do nothing —
            // an extension that connects servers in a fiber has no "do nothing" to be told.
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

        return [array_values($extensions), $errors];
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
