<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
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
 * <?php // ~/.pig/agent/hooks/log-edits.php
 * return function (Pig\CodingAgent\Hooks\HookApi $pi): void {
 *     $pi->on('tool_result', fn ($event) => error_log("{$event->toolName} ran"));
 * };
 * ```
 *
 * Two roots, in this order: `~/.pig/agent/hooks` and then `<cwd>/.pig/hooks`, so a project can
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
     * @param bool $projectTrusted whether `<cwd>/.pig/hooks` may be read at all — see
     *                             `ProjectTrust`; the person's own `~/.pig/agent/hooks` always is
     * @return array{0: list<LoadedHook>, 1: list<HookError>}
     */
    public static function load(string $cwd, array $configured = [], ?string $home = null, bool $projectTrusted = true): array
    {
        $home ??= Config::home();
        $cwd = rtrim($cwd, '/');

        $paths = [
            ...self::discover($home . '/hooks'),
            ...($projectTrusted ? self::discover($cwd . '/.pig/hooks') : []),
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

            // Both can be set: a reload of a file whose classes are already in memory loads and
            // says so — see `DeclaredSymbols`.
            [$hook, $error] = self::one($path, $real, $cwd);

            if ($error !== null) {
                $errors[] = $error;
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
                $note = new HookError($path, 'reload', 'declares ' . self::listOf($declared) . ', which PHP cannot unload — running the version loaded at startup; restart pig to pick up the change');
            }
        } else {
            $conflict = DeclaredSymbols::conflict($resolved, $declared);

            if ($conflict !== null) {
                return [null, new HookError($path, 'load', "declares {$conflict}, which is already in memory; two different copies of one hook cannot both be loaded")];
            }

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

            if ($declared !== []) {
                DeclaredSymbols::remember($resolved, Closure::fromCallable($factory), $hash);
            }
        }

        try {
            $factory($api);
        } catch (Throwable $error) {
            return [null, new HookError($path, 'load', self::describe($error))];
        }

        return [new LoadedHook($path, $resolved, $api), $note];
    }

    /**
     * The declared names for a message: the first few and a count, because an extension with
     * twelve classes is twelve names nobody needs to read.
     *
     * @param list<string> $names
     */
    public static function listOf(array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $rest = count($names) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? " and {$rest} more" : '');
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
