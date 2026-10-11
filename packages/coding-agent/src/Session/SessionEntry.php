<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * One entry of a session file, with the id it is known by and the entry it hangs off.
 *
 * Upstream's `SessionEntry`. pig keeps an entry's *content* as the object it decodes to —
 * a message, a `CompactionSummary`, a `ModelChange`, a `Label` — rather than as a tagged
 * record with a `type`, so the type here is the class of `$message`. What this adds is the two
 * facts the content alone does not carry and an extension reading a branch needs: which entry it
 * is (`firstKeptEntryId` names one) and which one came before it.
 *
 * Handed to hooks as `session_before_compact`'s `branchEntries` and `session_before_tree`'s
 * `preparation->entriesToSummarize`, and taken by `Compaction::prepareCompaction()` and
 * `BranchSummarization::prepareBranchEntries()`, as upstream's functions of the same names take
 * `SessionEntry[]`.
 */
final readonly class SessionEntry
{
    /**
     * @param mixed $message what the entry holds: a message, a summary, or one of the entries that
     *        are not part of the conversation (`ModelChange`, `ThinkingLevelChange`, `Label`,
     *        `CustomEntry`, `ContextEdit`, `UsageEntry`, `SessionInfoEntry`)
     */
    public function __construct(
        public string $id,
        public ?string $parentId,
        public mixed $message,
    ) {
    }
}
