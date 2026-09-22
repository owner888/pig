<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** The jump happened; the leaf is now somewhere else. */
final readonly class SessionTreeEvent implements HookEvent
{
    public function __construct(
        public ?string $newLeafId,
        public ?string $oldLeafId,
    ) {
    }

    public function type(): string
    {
        return 'session_tree';
    }
}
