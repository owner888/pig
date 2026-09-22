<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Pig\Tui\Process;

/** What a command a hook ran left behind. */
final readonly class ExecResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    /** Whether it ran and said it was happy. */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** Whether it was killed for taking too long, or could not be started at all. */
    public function stopped(): bool
    {
        return $this->exitCode === Process::STOPPED;
    }
}
