<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/**
 * Something happened to the session that a tool holding state should know about.
 *
 * Upstream's five reasons, with its `branch` under the name upstream's session events use for
 * the same thing now — `fork`, a conversation forked into a second session file — and one of
 * pig's own, `tree`, for `/tree` moving inside one file.
 */
final readonly class CustomToolSessionEvent
{
    /** @param 'start'|'switch'|'fork'|'tree'|'shutdown' $reason */
    public function __construct(
        public string $reason,
        public ?string $previousSessionFile = null,
    ) {
    }
}
