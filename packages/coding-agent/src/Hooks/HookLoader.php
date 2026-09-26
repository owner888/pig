<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Tools\Paths;
use Throwable;

/**
 * Finding hook files and running them.
 *
 * A hook is a `.php` file that returns a callable. The file is `require`d and the
 * callable is handed a `HookApi` to register on:
 *
 * ```php
 * <?php // ~/.pig/hooks/log-edits.php
 * return function (Pig\CodingAgent\Hooks\HookApi $pi): void {
 *     $pi->on('tool_result', fn ($event) => error_log("{$event->toolName} ran"));
 * };
 * ```
 *
 * Two roots, in this order: `~/.pig/hooks` and then `<cwd>/.pig/hooks`, so a project can
 * add to what the machine already has. Settings may name more files after those.
 *
 * Upstream's loader is the part that does not survive the port. It uses `jiti` to load
 * TypeScript from a user directory with the agent's own packages aliased in, which is
 * work PHP does not need: `require` is already the loader, and a hook that needs pig's
 * classes already has them because it is running inside pig.
 *
 * **What that costs.** `require` runs the file in this process, with this process's
 * permissions and no way back. A hook that loops does not time out; a hook that calls
 * `exit()` takes the session with it; a hook that defines a function pig already has is
 * a fatal error at load. What *is* caught is everything PHP raises as a `Throwable`,
 * which includes a syntax error (`ParseError`) and a call to something that is not
 * there (`Error`) — so a broken hook is a complaint at startup, not a crash. That trade
 * was chosen deliberately over running hooks as separate commands, which would have
 * bought isolation and timeouts and cost hooks the ability to hand back an object. See
 * CLAUDE.md.
 */
final class HookLoader
{
    /**
     * Every hook this machine and this project offer.
     *
     * @param list<string> $configured extra files, from settings; `~` is expanded and a
     *                                 relative path is resolved against $cwd
     * @return array{0: list<LoadedHook>, 1: list<HookError>}
     */
    public static function load(string $cwd, array $configured = [], ?string $home = null): array
    {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = [
            ...self::discover($home . '/hooks'),
            ...self::discover($cwd . '/.pig/hooks'),
        ];

        foreach ($configured as $path) {
            $paths[] = Paths::resolve($path, $cwd);
        }

        $hooks = [];
        $errors = [];
        $seen = [];

        foreach ($paths as $path) {
            // Two roots reaching one file through a symlink is one hook. Loading it twice
            // would not just double its handlers — a file that declares a function would
            // fatal on the second `require`, which is not a failure a person could read.
            $real = realpath($path);
            $real = $real === false ? $path : $real;

            if (isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;

            [$hook, $error] = self::one($path, $real, $cwd);

            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            if ($hook !== null) {
                $hooks[] = $hook;
            }
        }

        return [$hooks, $errors];
    }

    /**
     * The `.php` files directly inside a directory, sorted.
     *
     * Not recursive, and sorted so that two machines with the same files load them in the
     * same order — hooks run in load order, and readdir order is not an order.
     *
     * A `glob()` rather than a scan, and here that is the point rather than an oversight:
     * a glob does not match a leading dot, and a hook directory is a directory somebody
     * *edits*. Emacs writes a `.#name.php` symlink beside the file it has open, which ends
     * in `.php` — upstream's `readdirSync` picks it up (it accepts symlinks) and reports a
     * load error for as long as the editor is open. `Migrations` has the opposite rule, and
     * for the opposite reason: missing a session file there loses somebody's conversation,
     * where missing a dotfile here is how an editor's droppings stay out of the way.
     *
     * @return list<string>
     */
    private static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = glob($directory . '/*.php', GLOB_NOSORT);

        if ($found === false) {
            return [];
        }

        sort($found);

        return array_values($found);
    }

    /**
     * Load one file.
     *
     * @return array{0: LoadedHook|null, 1: HookError|null}
     */
    private static function one(string $path, string $resolved, string $cwd): array
    {
        if (!is_file($resolved) || !is_readable($resolved)) {
            return [null, new HookError($path, 'load', 'not a readable file')];
        }

        $api = new HookApi($cwd, $path);

        // Anything the file prints would land in the middle of the terminal UI, which
        // redraws over it and leaves a session that looks corrupted for a reason nobody
        // can see. Captured and reported as what it is instead.
        ob_start();

        try {
            $factory = self::evaluate($resolved);
        } catch (Throwable $error) {
            ob_end_clean();

            return [null, new HookError($path, 'load', self::describe($error))];
        }

        $printed = ob_get_clean();

        if ($printed !== false && trim($printed) !== '') {
            return [null, new HookError($path, 'load', 'printed to standard output while loading: ' . trim($printed))];
        }

        if (!is_callable($factory)) {
            return [null, new HookError($path, 'load', 'must return a callable, got ' . get_debug_type($factory))];
        }

        try {
            $factory($api);
        } catch (Throwable $error) {
            return [null, new HookError($path, 'load', self::describe($error))];
        }

        return [new LoadedHook($path, $resolved, $api), null];
    }

    /**
     * `require`, in a scope of its own.
     *
     * Static so the file cannot reach `$this`, and with one oddly-named parameter so that
     * a hook writing `$path` at the top level is writing its own variable, not ours.
     */
    private static function evaluate(string $pigHookPath): mixed
    {
        return (static fn (): mixed => require $pigHookPath)();
    }

    /** A throwable as one line, with where it came from when that is not obvious. */
    private static function describe(Throwable $error): string
    {
        $type = $error::class;
        $where = basename($error->getFile()) . ':' . $error->getLine();

        return "{$type}: {$error->getMessage()} ({$where})";
    }

}
