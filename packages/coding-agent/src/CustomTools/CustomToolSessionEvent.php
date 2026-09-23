<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/**
 * Something happened to the session that a tool holding state should know about.
 *
 * Four reasons rather than upstream's five: `branch` is about forking a conversation into
 * a second session file, which pig does not do — `/tree` branches inside one file and
 * arrives here as `tree`.
 */
final readonly class CustomToolSessionEvent
{
    /** @param 'start'|'switch'|'tree'|'shutdown' $reason */
    public function __construct(
        public string $reason,
        public ?string $previousSessionFile = null,
    ) {
    }
}
