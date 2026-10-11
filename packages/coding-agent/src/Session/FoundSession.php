<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * What `SessionManager::locate()` found for a `--session` / `--fork` argument.
 *
 * Upstream's `ResolvedSession`, folded to the one distinction a caller acts on: `path`, `local`
 * and `global` all carry a path, and only `global` — a session of another project, found by id —
 * carries the directory it belongs to, which `--session` offers to fork into this one.
 */
final readonly class FoundSession
{
    /**
     * @param string      $path      the session file
     * @param string|null $elsewhere the working directory of the project it belongs to, when that
     *                               is not the current one; null for a path or a local match
     */
    public function __construct(
        public string $path,
        public ?string $elsewhere = null,
    ) {
    }
}
