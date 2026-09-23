<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * A hook's own note in the session file, which is not part of the conversation.
 *
 * `appendEntry()` writes one. It is in the file, so it survives a restart and a `--continue`;
 * it is not in `messages()`, so the model never sees it and it costs no context. What it is
 * for is hook state: "this session was granted full permissions at 14:02", read back on the
 * next `session_start` by scanning for the hook's own `customType`.
 *
 * Deliberately outside the tree. Every message has a parent, because going back to an earlier
 * point is what the tree is for; a note about the session is not a point in the conversation
 * that anyone could go back to, and upstream reads these with a flat scan rather than a walk.
 *
 * Ported from upstream's `CustomEntry`, the half of `core/messages.ts` that is not a message.
 */
final readonly class CustomEntry
{
    public int $timestamp;

    public function __construct(
        public string $customType,
        public mixed $data = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
