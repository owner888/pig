<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * A session whose working directory has gone — upstream's `SessionCwdIssue` (`core/session-cwd.ts`):
 * the file, the directory its header names, and the directory carrying on would use instead.
 */
final readonly class SessionCwdIssue
{
    public function __construct(
        public ?string $sessionFile,
        public string $sessionCwd,
        public string $fallbackCwd,
    ) {
    }
}
