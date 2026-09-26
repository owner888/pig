<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Tui\Paths as TuiPaths;

/**
 * Turning what the model wrote into a path that exists.
 *
 * A model produces paths from three places — what the user typed, what a previous tool
 * printed, and its own guesswork — and the three do not always agree about spaces.
 */
final class Paths
{
    /** The space a macOS screenshot has in its own name, before `AM` or `PM`. */
    private const string NARROW_NO_BREAK_SPACE = "\u{202F}";

    /**
     * Expand a leading `~`, and normalise the spaces.
     *
     * `Tui\Paths::expand()` is the implementation, because the `@` file picker lives in that
     * package and cannot see this one — and had its own worse copy of these lines until it did.
     * Kept here as the name every caller in this package already asks for.
     */
    public static function expand(string $path): string
    {
        return TuiPaths::expand($path);
    }

    /**
     * Resolve against $cwd, unless it is already absolute, and collapse the result.
     *
     * The collapsing is upstream's — Node's `path.resolve` does it — and it matters for more
     * than tidiness even though the OS would open the same file either way. This path is what
     * `grep` and `find` are handed as their search root, so it is the prefix on every line they
     * print; the model reads those lines and writes the path back into its next call. Left
     * uncollapsed, a `read ../README.md` became
     * `/home/dev/pkg/../README.md` in everything the model saw from then on.
     */
    public static function resolve(string $path, string $cwd): string
    {
        $expanded = self::expand($path);

        return self::collapse(str_starts_with($expanded, '/') ? $expanded : rtrim($cwd, '/') . '/' . $expanded);
    }

    /**
     * An absolute path with `.`, `..`, doubled slashes and any trailing slash taken out.
     *
     * Not `realpath()`, which answers false for a path that does not exist yet — and `write`
     * and `edit` are given exactly those. Not `realpath()` for a second reason either: it
     * follows symlinks, so a path inside a linked directory would come back somewhere the
     * model never asked about.
     *
     * `..` above the root stays at the root, as every other implementation of this has it.
     */
    private static function collapse(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part !== '..') {
                $parts[] = $part;

                continue;
            }

            array_pop($parts);
        }

        return '/' . implode('/', $parts);
    }

    /**
     * The same, but with one retry for the path macOS actually used.
     *
     * A macOS screenshot is named `Screenshot 2026-09-22 at 4.31.05 PM.png`, and the
     * space before `PM` is U+202F, not a space. Normalising it — which the step above
     * does, and has to, because most mismatches go the other way — turns a real path into
     * one that does not exist. So if the normalised path is missing, the original spelling
     * is tried before giving up.
     */
    public static function resolveForRead(string $path, string $cwd): string
    {
        $resolved = self::resolve($path, $cwd);

        if (file_exists($resolved)) {
            return $resolved;
        }

        $screenshot = (string) preg_replace('/ (AM|PM)\./', self::NARROW_NO_BREAK_SPACE . '$1.', $resolved);

        return $screenshot !== $resolved && file_exists($screenshot) ? $screenshot : $resolved;
    }

    /** $path shown relative to $cwd when it is inside it, for output a human reads. */
    public static function relative(string $path, string $cwd): string
    {
        $prefix = rtrim($cwd, '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
