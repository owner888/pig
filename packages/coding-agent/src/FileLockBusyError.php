<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use RuntimeException;

/**
 * `FileLock::hold()` gave up: another process held the lock for the whole wait. Its own class
 * so a caller can tell "busy" from "could not create or open the lock file", which are the
 * other two ways `hold()` fails — a busy lock is something to step around (the MCP sign-in asks
 * the person to sign in instead of waiting on another pig's refresh), a broken one is not.
 */
final class FileLockBusyError extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly float $waited,
    ) {
        parent::__construct(sprintf('Another process has held %s for %.0fs; giving up.', $path, $waited));
    }
}
