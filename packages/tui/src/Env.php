<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The environment, read the way JavaScript reads it.
 *
 * Every question here has one right answer and used to have two or three implementations, which
 * is how they came to disagree. Both are about a variable's *presence*, and both are places where
 * PHP and the thing being ported apart.
 */
final class Env
{
    /**
     * Whether a variable is set to anything at all.
     *
     * **An exported-but-empty variable is not presence.** `getenv()` answers `false` for absent
     * and `''` for set-but-empty, so the obvious `!== false` calls an empty one present; upstream
     * reads these variables through JavaScript's truthiness, where `''` is falsy, and therefore
     * ignores them.
     *
     * What that cost where it was first found, in `Images\Capabilities`: a bare
     * `export ITERM_SESSION_ID`, a `docker run -e ITERM_SESSION_ID`, or an ssh or tmux
     * environment forwarding the name without a value made pig send iTerm2 image sequences to a
     * terminal that cannot draw them — which is not a missing picture, it is tens of kilobytes of
     * base64 printed into the transcript. In `Clipboard\SystemClipboard` it is milder and still
     * worth one answer: the wrong clipboard command is chosen, `Process::capture()` answers null
     * for it, and what the person gets is no clipboard rather than an explanation.
     */
    public static function isSet(string $name): bool
    {
        return (string) getenv($name) !== '';
    }

    /**
     * The home directory, or null when there is none to be had.
     *
     * Null rather than a guess, because the three callers want different things from its absence
     * and only one of them can be answered here: `Paths::expand()` leaves a `~` alone, a footer
     * leaves the path unshortened, and `CodingAgent\Config` falls back to the temp directory
     * because it has to put its files *somewhere*. A `sys_get_temp_dir()` in here would make the
     * first two resolve a `~` to `/tmp`, which is the silent fallback two loaders were already
     * caught doing.
     */
    public static function home(): ?string
    {
        $home = getenv('HOME');

        return $home === false || $home === '' ? null : rtrim($home, '/');
    }
}
