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
     * @return array{0: list<LoadedExtension>, 1: list<ExtensionError>}
     */
    public static function load(
        string $cwd,
        array $configured = [],
        array $cliPaths = [],
        ?string $home = null,
        ?Auth $auth = null,
    ): array {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = [
            ...self::discover($home . '/extensions'),
            ...self::discover($cwd . '/.pig/extensions'),
            ...self::discover($cwd . '/extensions'),
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

        foreach ($paths as $path) {
            $real = realpath($path) ?: $path;

            if (isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;

            [$loaded, $error] = self::one($path, $real, $cwd, $auth);

            if ($error !== null) {
                $errors[] = $error;
            }

            if ($loaded !== null) {
                $extensions[] = $loaded;
            }
        }

        return [$extensions, $errors];
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
    private static function one(string $path, string $resolved, string $cwd, ?Auth $auth): array
    {
        if (!is_readable($path)) {
            return [null, new ExtensionError($path, 'load', 'not a readable file')];
        }

        $symbolError = HookLoader::checkTopLevelSymbols($resolved);

        if ($symbolError !== null) {
            return [null, new ExtensionError($path, 'load', $symbolError)];
        }

        $name = self::nameOf($path);
        $api = new ExtensionApi($cwd, $path, $name, $auth);

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
