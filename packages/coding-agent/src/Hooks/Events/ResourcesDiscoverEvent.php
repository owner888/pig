<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Fired after `session_start` on startup and on `/reload`, so an extension can add skill,
 * prompt-template and theme directories of its own. Upstream's `ResourcesDiscoverEvent`.
 */
final readonly class ResourcesDiscoverEvent implements HookEvent
{
    /** @param 'startup'|'reload' $reason */
    public function __construct(
        public string $cwd,
        public string $reason,
    ) {
    }

    public function type(): string
    {
        return 'resources_discover';
    }
}
