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
        [$files] = self::loadWithWarnings($cwd, $home);

        return $files;
    }

    /**
     * The same, and what could not be read.
     *
     * **A file this skips is a file the person wrote and the agent is now working without.**
     * It used to be skipped silently, which is not what the rest of this repository does with a
     * problem it cannot fix: `Skills`, `HookLoader`, `CustomToolLoader`, `Settings` and
     * `CustomModels` all hand their complaints back for `bin/pig` to print before the UI
     * starts. The old comment said "skipped rather than fatal", which answers whether to
     * *stop* and says nothing about whether to speak — and upstream prints a yellow warning
     * here. Permissions and a broken symlink are the two ways in.
     *
     * Why this is a second method rather than `load()`'s own shape, which is what its three
     * siblings have: `Prompt\SystemPrompt` calls `load()` and is a file the developer owns, so
     * the tuple cannot be pushed into it from here. `load()` is the thin wrapper for that
     * caller; everything else should ask for the warnings.
     *
     * @return array{0: list<ContextFile>, 1: list<string>}
     */
    public static function loadWithWarnings(string $cwd, ?string $home = null): array
    {
        $home ??= Config::home();
        $files = [];
        $warnings = [];
        $seen = [];

        // The person's own instructions come first, so a project can override them.
        [$global, $complaints] = self::inDirectory($home);
        $warnings = [...$warnings, ...$complaints];

        if ($global !== null) {
            $files[] = $global;
            $seen[$global->path] = true;
        }

        $ancestors = [];
        $directory = rtrim($cwd, '/');

        while (true) {
            [$found, $complaints] = self::inDirectory($directory === '' ? '/' : $directory);
            $warnings = [...$warnings, ...$complaints];

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

        return [[...$files, ...$ancestors], $warnings];
    }

    /**
     * The first of the two names that is there and readable, and what the other cost.
     *
     * A name that is there and cannot be read falls through to the next candidate, as
     * upstream's does — an unreadable `AGENTS.md` should not hide a perfectly good
     * `CLAUDE.md` beside it — and is complained about either way.
     *
     * @return array{0: ContextFile|null, 1: list<string>}
     */
    private static function inDirectory(string $directory): array
    {
        $warnings = [];

        foreach (self::NAMES as $name) {
            $path = rtrim($directory, '/') . '/' . $name;

            if (!is_file($path)) {
                continue;
            }

            $content = is_readable($path) ? file_get_contents($path) : false;

            if ($content !== false) {
                return [new ContextFile($path, $content), $warnings];
            }

            $warnings[] = "{$path} could not be read";
        }

        return [null, $warnings];
    }
}
