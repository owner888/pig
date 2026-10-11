<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * An entry went into the session that no message event carries — upstream's `entry_appended`.
 *
 * Upstream announces it for the entries written outside a turn's `message_end`: a hook's custom
 * entry (`appendEntry()`), a context edit omitting a failed attempt (`_omitRecoveryAttempt()`), the
 * cache warmer's usage entry, and the boundary drafts a `turn_end` handler commits. A host keeping a
 * copy of the session tree (`get_entries` with `since`) learns of them here rather than by polling.
 */
final readonly class EntryAppendedEvent implements AgentEvent
{
    public function __construct(
        public SessionEntry $entry,
    ) {
    }
}
