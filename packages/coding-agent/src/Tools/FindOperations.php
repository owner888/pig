<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * Upstream's `FindOperations` (`tools/find.ts`). As upstream: given one, `find` checks the path
 * with `exists()` and asks `glob()` instead of running `fd`; without one it runs `fd` on this
 * machine, as it always did — upstream's default `glob` is a placeholder for the same reason.
 */
interface FindOperations
{
    public function exists(string $absolutePath): bool;

    /**
     * Files matching the glob `$pattern` under `$cwd`, relative to it or absolute, at most `$limit`.
     *
     * @param list<string> $ignore globs to leave out (`**\/node_modules/**`, `**\/.git/**`)
     * @return list<string>
     */
    public function glob(string $pattern, string $cwd, array $ignore, int $limit): array;
}
