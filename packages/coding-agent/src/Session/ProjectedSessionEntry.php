<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * One entry of the projected context and the messages it contributes — upstream's
 * `ProjectedSessionEntry` (`session-manager.ts`).
 *
 * The projection is what the model is shown: the newest compaction first (its replayed system
 * message and its summary), then the entries it kept, then everything after it, with every
 * `context_edit` applied. An entry that contributes nothing — a model change, a label, an entry
 * an edit omitted, an older compaction inside the kept range — is still listed, with no messages,
 * because where it sits is something `Compaction::prepareCompaction()`'s cut has to know.
 */
final readonly class ProjectedSessionEntry
{
    /** @param list<mixed> $messages what this entry contributes to the context, edits applied */
    public function __construct(
        public SessionEntry $sourceEntry,
        public array $messages,
    ) {
    }
}
