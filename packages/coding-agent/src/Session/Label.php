<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * A name somebody put on a point in the conversation.
 *
 * Not a message and not a point of its own: it is an annotation *on* another entry, named by
 * id. What it is for is `/tree` — a list of "4 back · 7 back · 12 back" is a list nobody can
 * choose from, and "before the refactor" is.
 *
 * Setting an empty one clears it, which is why the label is nullable rather than the entry
 * being deleted. Nothing in a session file is ever deleted: a label that was removed is a
 * line saying it was removed, sitting after the line that set it.
 *
 * Ported from upstream's `LabelEntry`.
 */
final readonly class Label
{
    public int $timestamp;

    public function __construct(
        public string $targetId,
        public ?string $label = null,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
