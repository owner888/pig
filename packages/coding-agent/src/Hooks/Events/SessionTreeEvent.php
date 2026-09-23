<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Session\BranchSummary;

/**
 * The jump happened; the leaf is now somewhere else.
 *
 * `$summary` is what was written down about the branch that was left, when anything was —
 * already appended to the branch being joined by the time this goes out.
 */
final readonly class SessionTreeEvent implements HookEvent
{
    public function __construct(
        public ?string $newLeafId,
        public ?string $oldLeafId,
        public ?BranchSummary $summary = null,
    ) {
    }

    public function type(): string
    {
        return 'session_tree';
    }
}
