<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Config;

/**
 * The `AGENTS.md` files that apply where the agent is working.
 *
 * Collected by walking from the working directory up to the root, so a file in a
 * monorepo's root applies to every package under it and a package can add to it. The
 * order is outermost first, because that is the order they read in: the general rules,
 * then the ones that narrow them.
 *
 * `CLAUDE.md` is accepted under the same rules, since plenty of projects already have
 * one and nobody wants to keep two copies of the same instructions.
 */
final class ContextFiles
{
    /** In order of preference. A directory with both gets only the first. */
    private const array NAMES = ['AGENTS.md', 'CLAUDE.md'];

    /**
     * The global file, then every one from the root down to $cwd.
     *
     * @return list<ContextFile>
     */
    public static function load(string $cwd, ?string $home = null): array
    {
        $home ??= Config::home();
        $files = [];
        $seen = [];

        // The person's own instructions come first, so a project can override them.
        $global = self::inDirectory($home);

        if ($global !== null) {
            $files[] = $global;
            $seen[$global->path] = true;
        }

        $ancestors = [];
        $directory = rtrim($cwd, '/');

        while (true) {
            $found = self::inDirectory($directory === '' ? '/' : $directory);

            if ($found !== null && !isset($seen[$found->path])) {
                // Unshifted, so the walk up comes back out as a walk down.
                array_unshift($ancestors, $found);
                $seen[$found->path] = true;
            }

            $parent = dirname($directory === '' ? '/' : $directory);

            if ($parent === $directory || $directory === '' || $directory === '/') {
                break;
            }

            $directory = $parent;
        }

        return [...$files, ...$ancestors];
    }

    private static function inDirectory(string $directory): ?ContextFile
    {
        foreach (self::NAMES as $name) {
            $path = rtrim($directory, '/') . '/' . $name;

            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);

            // An unreadable one is skipped rather than fatal: a context file is help,
            // and refusing to start because one of them has awkward permissions would
            // be worse than starting without it.
            if ($content !== false) {
                return new ContextFile($path, $content);
            }
        }

        return null;
    }
}
