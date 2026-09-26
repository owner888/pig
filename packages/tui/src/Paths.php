<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A path as a person typed or pasted it, turned into one the filesystem knows.
 *
 * Two corrections, and the reason they live together is that both are about text arriving from
 * outside rather than about paths: a leading `~`, and the spaces macOS substitutes on the way to
 * the clipboard.
 *
 * `CodingAgent\Tools\Paths` is the rest of pig's path handling — resolving against a working
 * directory, collapsing `..`, the macOS screenshot retry — and it calls `expand()` here rather
 * than keeping its own copy. That is which way round it had to be: this package cannot see that
 * one, and the `@` file picker lives here. Before this there were two copies and the one here was
 * the worse of them, which is the third time that has happened in this repository to the same
 * fourteen lines.
 */
final class Paths
{
    /**
     * The spaces that are not the space.
     *
     * A path copied out of Finder comes back with U+202F where the name on disk has an ordinary
     * space, so a path that looks identical to the one on screen does not exist. The rest of the
     * range is the same mistake from other sources — a no-break space out of a word processor,
     * an ideographic space out of a Chinese input method.
     */
    private const string UNICODE_SPACES = '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

    /** Expand a leading `~`, and normalise the spaces. */
    public static function expand(string $path): string
    {
        $path = self::normaliseSpaces($path);
        $home = Env::home();

        if ($home === null) {
            return $path;
        }

        return match (true) {
            $path === '~' => $home,
            str_starts_with($path, '~/') => $home . substr($path, 1),
            default => $path,
        };
    }

    /**
     * Every kind of space turned into the ordinary one.
     *
     * Separate from `expand()` because the `@` picker needs it for *comparing* as well as for
     * opening: normalising alone is not the fix, since a macOS screenshot has U+202F in its real
     * name on disk. With both sides normalised, a name typed with an ordinary space matches the
     * file that has the narrow one and the other way round — which is the pair of answers
     * `CodingAgent\Tools\Paths::resolveForRead()` arrives at by retrying instead.
     */
    public static function normaliseSpaces(string $text): string
    {
        return (string) preg_replace(self::UNICODE_SPACES, ' ', $text);
    }
}
