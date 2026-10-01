<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * pi's `context_edit` entry: an append-only edit to what the model is shown of one earlier
 * message, without touching the message itself.
 *
 * `replacement: null` **omits** the target from the model's context; a string or a content
 * list **replaces** its content. The target stays in the file, in the tree and in the money —
 * "it changes only future model context", as the format document puts it. That is what makes it
 * append-only: a conversation can lose a message from the model's view and still be a record of
 * what was said.
 *
 * Two things write one: pi's retry omits a failed turn this way (pig took the message off the
 * agent's state and wrote nothing, so a resumed conversation put it back), and pi's context
 * pruning replaces a tool result that has outlived its use. Edits are **branch-relative** — one
 * on a branch you later walked away from does not apply — and the latest edit on the active
 * branch for a given target wins.
 *
 * @see https://github.com/earendil-works/pi — `docs/session-format.md`, "ContextEditEntry"
 */
final readonly class ContextEdit
{
    public int $timestamp;

    /**
     * @param string|list<\Pig\Ai\Content>|null $replacement null omits the target; a string is
     *        one text block for roles that need a content array; a list is the new content whole
     */
    public function __construct(
        public string $targetId,
        public string|array|null $replacement = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
