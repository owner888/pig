<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Hooks\DeclaredSymbols;
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
     * @param bool $discover false is upstream's `--no-extensions`: only `$cliPaths` load — nothing
     *        discovered, configured, bundled, from the project or from a package
     * @param list<string> $builtins upstream's built-in extensions this run loads
     *        (`Settings::builtinExtensions()`), after everything else, as upstream ranks them
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
        bool $discover = true,
        array $builtins = [],
    ): array {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = $discover ? self::discover($home . '/extensions') : [];

        foreach ([...($discover ? $configured : []), ...$cliPaths] as $path) {
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

                // A folder named as a built-in's that is not the built-in: the copy goes stale, and
                // the built-in loads anyway (`BuiltinExtensions::isStaleCopy()`).
                if (BuiltinExtensions::isStaleCopy($path)) {
                    $seen[$real] = true;
                    $folder = self::nameOf($path);
                    $errors[] = new ExtensionError($path, 'load', "not loaded: `{$folder}` is built into pig now, so this copy would be an older one; remove it");

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

        if (!$discover) {
            return [self::omitReplaced(array_values($extensions), $errors), $errors];
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

        $loadAll($builtins);

        return [self::omitReplaced(array_values($extensions), $errors), $errors];
    }

    /**
     * Upstream's `omitReplacedExtensions()`: a replaceable built-in — `codemode`, `tool-search`,
     * `mcp` — gives way to another extension that registers one of its tools or commands, and says
     * so. (Upstream compares flags too; pig's flags are process-wide, not an extension's.)
     *
     * @param list<LoadedExtension> $extensions
     * @param list<ExtensionError> $errors
     * @return list<LoadedExtension>
     */
    private static function omitReplaced(array $extensions, array &$errors): array
    {
        $replaceable = static function (LoadedExtension $extension): ?string {
            $name = BuiltinExtensions::nameOf($extension->resolved);

            return $name !== null && in_array($name, BuiltinExtensions::REPLACEABLE, true) ? $name : null;
        };
        $names = static fn (LoadedExtension $extension): array => [
            ...array_map(static fn (object $tool): string => "tool:{$tool->name}", $extension->api->tools()),
            ...array_map(static fn (string|int $command): string => "command:{$command}", array_keys($extension->api->commands())),
        ];
        $taken = [];

        foreach ($extensions as $extension) {
            if ($replaceable($extension) === null) {
                foreach ($names($extension) as $name) {
                    $taken[$name] ??= $extension;
                }
            }
        }

        return array_values(array_filter($extensions, static function (LoadedExtension $extension) use ($replaceable, $names, $taken, &$errors): bool {
            $builtin = $replaceable($extension);

            if ($builtin === null) {
                return true;
            }

            foreach ($names($extension) as $name) {
                if (isset($taken[$name])) {
                    [$kind, $raw] = explode(':', $name, 2);
                    $registered = $kind === 'command' ? "/{$raw}" : $raw;
                    $errors[] = new ExtensionError(
                        BuiltinExtensions::PREFIX . $builtin,
                        'load',
                        "Extension {$taken[$name]->path} registers {$kind} `{$registered}`, so built-in extension `{$builtin}` was not loaded. To use `{$builtin}`, run `pig config` and make sure it is enabled under Built-in extensions, then disable or remove the existing extension. We recommend only having one or the other loaded at a time.",
                    );

                    return false;
                }
            }

            return true;
        }));
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

        $name = self::nameOf($path);
        $api = new ExtensionApi($cwd, $path, $name, $auth, events: $events);
        $note = null;

        // A file that declares a class or a function cannot be `require`d twice, so on a reload it
        // is the factory from its first load that runs — and a class another file already declared
        // is refused by name rather than let the second `require` fatal. `DeclaredSymbols` says why.
        $declared = DeclaredSymbols::in($resolved);
        $hash = $declared === [] ? '' : DeclaredSymbols::hashOf($resolved);
        $kept = $declared === [] ? null : DeclaredSymbols::recall($resolved, $hash);

        if ($kept !== null) {
            $factory = $kept['factory'];

            if ($kept['changed']) {
                $note = new ExtensionError($path, 'reload', 'declares ' . HookLoader::listOf($declared) . ', which PHP cannot unload — running the version loaded at startup; restart pig to pick up the change');
            }
        } else {
            $conflict = DeclaredSymbols::conflict($resolved, $declared);

            if ($conflict !== null) {
                return [null, new ExtensionError($path, 'load', "declares {$conflict}, which is already in memory; two different copies of one extension cannot both be loaded")];
            }

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

            if ($declared !== []) {
                DeclaredSymbols::remember($resolved, Closure::fromCallable($factory), $hash);
            }
        }

        try {
            $factory($api);
        } catch (Throwable $error) {
            return [null, new ExtensionError($path, 'load', self::describe($error))];
        }

        return [new LoadedExtension($path, $resolved, $name, $api), $note];
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
