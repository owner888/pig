<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Config;

/**
 * The `AGENTS.md` files that apply where the agent is working — upstream's
 * `loadProjectContextFiles()` in `core/resource-loader.ts`.
 *
 * The agent directory's own file first, then one per directory walking from the working
 * directory up to the root, so a file in a monorepo's root applies to every package under it and
 * a package can add to it. The order is outermost first, because that is the order they read in:
 * the general rules, then the ones that narrow them.
 *
 * Each directory contributes at most one file, the first of upstream's five names it has:
 * `AGENTS.override.md`, `AGENTS.md`, `AGENTS.MD`, `CLAUDE.md`, `CLAUDE.MD`. An override replaces
 * the others in its own directory only. Not gated by project trust: they are text the model reads,
 * not code pig runs.
 *
 * One more rule from upstream: inside a linked git worktree nested in its main checkout
 * (`git worktree add .worktrees/feat`), the main checkout's file of the same name is the
 * worktree's own file twice over, so it is left out (`findShadowedContextFile()`).
 */
final class ContextFiles
{
    /** Upstream's candidates, in order of preference. A directory with several gets only the first. */
    private const array NAMES = ['AGENTS.override.md', 'AGENTS.md', 'AGENTS.MD', 'CLAUDE.md', 'CLAUDE.MD'];

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
        $shadowed = self::shadowedContextFile($directory === '' ? '/' : $directory);

        while (true) {
            [$found, $complaints] = self::inDirectory($directory === '' ? '/' : $directory);
            $warnings = [...$warnings, ...$complaints];
            $isShadowed = $found !== null && $shadowed !== null && (realpath($found->path) ?: $found->path) === $shadowed;

            if ($found !== null && !$isShadowed && !isset($seen[$found->path])) {
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
     * Upstream's `findShadowedContextFile()`: the main checkout's context file that a nested linked
     * worktree's own copy shadows — both occupy the same logical repository scope, so loading both
     * applies that context twice. Null when nothing is shadowed: an ordinary repository, a sibling
     * worktree (`git worktree add ../feat`, whose main checkout is not an ancestor), a bare layout,
     * a submodule.
     *
     * Canonical (realpath), because `git worktree add` writes the `.git` file's `gitdir:` target in
     * realpath form while the working directory may be reached through a symlink.
     */
    private static function shadowedContextFile(string $cwd): ?string
    {
        $git = self::gitPaths($cwd);

        if ($git === null) {
            return null;
        }

        $commonGitDir = realpath($git['commonGitDir']) ?: $git['commonGitDir'];
        $worktreeRoot = realpath($git['repoDir']) ?: $git['repoDir'];
        $mainRepoRoot = dirname($commonGitDir);

        if (!str_starts_with($worktreeRoot, rtrim($mainRepoRoot, '/') . '/')) {
            return null;
        }

        // The common git dir's parent is the main worktree only when that directory is itself
        // checked out from the same repository: in a bare layout it merely holds `.bare`, and a
        // submodule's gitdir (no `commondir`) sits under `.git/modules`.
        if ((realpath($mainRepoRoot . '/.git') ?: $mainRepoRoot . '/.git') !== $commonGitDir) {
            return null;
        }

        [$own] = self::inDirectory($worktreeRoot);

        return $own === null ? null : $mainRepoRoot . '/' . basename($own->path);
    }

    /**
     * Upstream's `findGitPaths()`: the nearest `.git` upward from $cwd — a directory, or a file
     * whose `gitdir:` names the worktree's git dir, whose `commondir` names the shared one.
     *
     * @return array{repoDir: string, commonGitDir: string}|null
     */
    private static function gitPaths(string $cwd): ?array
    {
        for ($dir = $cwd; ; $dir = dirname($dir)) {
            $gitPath = rtrim($dir, '/') . '/.git';

            if (is_file($gitPath)) {
                $content = is_readable($gitPath) ? trim((string) file_get_contents($gitPath)) : '';

                if (!str_starts_with($content, 'gitdir: ')) {
                    return null;
                }

                $gitDir = \Pig\CodingAgent\Tools\Paths::resolve(trim(substr($content, 8)), $dir);

                if (!file_exists($gitDir . '/HEAD')) {
                    return null;
                }

                $commonDir = is_file($gitDir . '/commondir')
                    ? \Pig\CodingAgent\Tools\Paths::resolve(trim((string) file_get_contents($gitDir . '/commondir')), $gitDir)
                    : $gitDir;

                return ['repoDir' => $dir, 'commonGitDir' => $commonDir];
            }

            if (is_dir($gitPath)) {
                return file_exists($gitPath . '/HEAD') ? ['repoDir' => $dir, 'commonGitDir' => $gitPath] : null;
            }

            if (dirname($dir) === $dir) {
                return null;
            }
        }
    }

    /**
     * The first of upstream's names that is there and readable, and what the others cost.
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
