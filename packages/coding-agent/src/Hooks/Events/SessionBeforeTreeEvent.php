<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * About to jump to another point in the session tree — `/tree`.
 *
 * `$entries` is what is being left behind: the messages on the old branch that the new
 * leaf does not have, and `$summarise` says whether the person asked for them to be written
 * down. Cancellable — and a handler may hand back prose of its own instead, which is then
 * what gets written rather than anything the model was asked for.
 */
final readonly class SessionBeforeTreeEvent implements HookEvent
{
    /** @param list<mixed> $entries */
    public function __construct(
        public ?string $targetId,
        public ?string $oldLeafId,
        public array $entries = [],
        public bool $summarise = false,
    ) {
    }

    public function type(): string
    {
        return 'session_before_tree';
    }
}
