<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

use Pig\CodingAgent\Config;
use Throwable;

/**
 * Finding tool files and running them.
 *
 * A tool lives in a folder of its own: `~/.pig/tools/<name>/index.php`, or the same under
 * `<cwd>/.pig/tools`. That is upstream's layout and the reason is the same — a tool is
 * more likely than a hook to want a second file next to it, and a folder is where that
 * goes. The file returns a factory; the factory is handed a `CustomToolApi` and returns a
 * `CustomTool` or a list of them.
 *
 * Everything true of `Hooks\HookLoader` is true here, because it is the same mechanism:
 * `require` in this process, catchable `ParseError` for a syntax mistake, `realpath()`
 * deduplication so one file reached twice is one tool, output captured so a stray `echo`
 * cannot land on the screen the UI is about to draw. See that class, and CLAUDE.md, for
 * what running user code in-process costs.
 *
 * What upstream's loader has that this does not is `jiti` with pig's own packages aliased
 * in — work PHP does not need, since `require` is already the loader and a tool file
 * already has pig's classes because it is running inside pig.
 */
final class CustomToolLoader
{
    /**
     * Every tool this machine and this project offer.
     *
     * @param list<string> $builtIn    names already taken; a tool may not shadow one
     * @param list<string> $configured extra `index.php` paths, from settings
     * @return array{0: list<LoadedCustomTool>, 1: list<ToolProblem>}
     */
    public static function load(
        string $cwd,
        array $builtIn = [],
        array $configured = [],
        ?string $home = null,
    ): array {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = [
            ...self::discover($home . '/tools'),
            ...self::discover($cwd . '/.pig/tools'),
        ];

        foreach ($configured as $path) {
            $paths[] = self::resolve($path, $cwd);
        }

        // The API is shared, as upstream's is: it holds the working directory and nothing
        // else that could differ between two tools, and one of them is one fewer thing to
        // keep in step.
        $api = new CustomToolApi($cwd);

        $tools = [];
        $problems = [];
        $seenFiles = [];
        $taken = array_fill_keys($builtIn, true);

        foreach ($paths as $path) {
            $real = realpath($path);
            $real = $real === false ? $path : $real;

            if (isset($seenFiles[$real])) {
                continue;
            }

            $seenFiles[$real] = true;

            [$declared, $problem] = self::one($path, $real, $api);

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            foreach ($declared as $tool) {
                // A tool that shadows `bash` is not a tool, it is a trap: the model was
                // told what `bash` does and would be calling something else. Named rather
                // than silently dropped, because the author is the only one who can fix it.
                if (isset($taken[$tool->name])) {
                    $problems[] = new ToolProblem($path, "the name '{$tool->name}' is already taken");

                    continue;
                }

                $taken[$tool->name] = true;
                $tools[] = new LoadedCustomTool($path, $real, $tool);
            }
        }

        return [$tools, $problems];
    }

    /**
     * The `index.php` in each subfolder, sorted.
     *
     * Sorted so two machines with the same folders load in the same order — a name
     * collision is decided by load order, and readdir order is not an order.
     *
     * @return list<string>
     */
    private static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = glob($directory . '/*/index.php', GLOB_NOSORT);

        if ($found === false) {
            return [];
        }

        sort($found);

        return array_values($found);
    }

    /**
     * Load one file.
     *
     * @return array{0: list<CustomTool>, 1: ToolProblem|null}
     */
    private static function one(string $path, string $resolved, CustomToolApi $api): array
    {
        if (!is_file($resolved) || !is_readable($resolved)) {
            return [[], new ToolProblem($path, 'not a readable file')];
        }

        ob_start();

        try {
            $factory = self::evaluate($resolved);
        } catch (Throwable $error) {
            ob_end_clean();

            return [[], new ToolProblem($path, self::describe($error))];
        }

        $printed = ob_get_clean();

        if ($printed !== false && trim($printed) !== '') {
            return [[], new ToolProblem($path, 'printed to standard output while loading: ' . trim($printed))];
        }

        if (!is_callable($factory)) {
            return [[], new ToolProblem($path, 'must return a callable, got ' . get_debug_type($factory))];
        }

        try {
            $declared = $factory($api);
        } catch (Throwable $error) {
            return [[], new ToolProblem($path, self::describe($error))];
        }

        // One tool or several, the way upstream takes either. A factory that reads a
        // config file and declares a tool per entry is the case that wants the list.
        $declared = is_array($declared) ? array_values($declared) : [$declared];

        foreach ($declared as $tool) {
            if (!$tool instanceof CustomTool) {
                return [[], new ToolProblem(
                    $path,
                    'must return a CustomTool, or a list of them, got ' . get_debug_type($tool),
                )];
            }
        }

        return [$declared, null];
    }

    /** `require`, in a scope of its own — see `HookLoader::evaluate()`. */
    private static function evaluate(string $pigToolPath): mixed
    {
        return (static fn (): mixed => require $pigToolPath)();
    }

    private static function describe(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage()
            . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')';
    }

    /** Absolute as given, `~` from the environment, relative from the working directory. */
    private static function resolve(string $path, string $cwd): string
    {
        if (str_starts_with($path, '~')) {
            $home = getenv('HOME');
            $home = $home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/');

            return $home . substr($path, 1);
        }

        return str_starts_with($path, '/') ? $path : $cwd . '/' . $path;
    }
}
