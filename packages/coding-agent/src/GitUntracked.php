<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Tui\Process;

/**
 * The files `git diff` cannot show. `/diff` runs `git diff`, and `git diff` compares tracked files
 * only: a file that was never added is not in it, so a `/diff` that says "no changes" while
 * `git status` lists a new file is git's answer, not a lost change. The developer asked for the
 * names alongside the diff — enough to catch a file that was forgotten, or one that must not go
 * in — and not their contents, which `git diff --no-index /dev/null <file>` shows on request.
 */
final class GitUntracked
{
    /** @return list<string> paths as `git status --porcelain` prints them, in its order */
    public static function files(string $cwd): array
    {
        [$code, $out] = Process::run(['git', 'status', '--porcelain', '--untracked-files=all'], cwd: $cwd);

        if ($code !== 0) {
            return [];
        }

        $files = [];

        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, '?? ')) {
                $files[] = trim(substr($line, 3), '"');
            }
        }

        return $files;
    }

    /** The line shown under a diff; `''` when there is nothing to say. */
    public static function note(string $cwd): string
    {
        $files = self::files($cwd);

        return $files === [] ? '' : 'Untracked (not in the diff): ' . implode(', ', $files);
    }
}
