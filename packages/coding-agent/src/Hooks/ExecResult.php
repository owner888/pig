<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Pig\Tui\Process;

/** What a command a hook ran left behind. */
final readonly class ExecResult
{
    /**
     * The default cap on stdout and stderr together, upstream's `DEFAULT_EXEC_MAX_BUFFER`.
     *
     * Upstream's is 256 MiB, bounded by V8's string limit; pig's bound is `bin/pig`'s 512M
     * `memory_limit`, and 256 MiB does not fit under it: the output is held once while it is
     * read, and the hook that asked for it makes at least one copy of its own (a `trim()`, an
     * `explode()`) — measured, a 256 MiB string and one copy of it exhaust 512M on their own,
     * before the session's heap. 128 MiB leaves both under it with room for that heap.
     */
    public const int MAX_BUFFER = 128 * 1024 * 1024;

    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        /** Output went over the cap; stdout and stderr are incomplete and the command was stopped. */
        public bool $truncated = false,
    ) {
    }

    /** Whether it ran and said it was happy. */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** Whether it was killed for taking too long or for printing too much, or could not be started at all. */
    public function stopped(): bool
    {
        return $this->exitCode === Process::STOPPED;
    }
}
