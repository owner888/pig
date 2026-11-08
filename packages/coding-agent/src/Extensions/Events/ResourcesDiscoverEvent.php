<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\CodingAgent\Extensions\ExtensionEvent;

/**
 * Fired after `session_start` on startup and on `/reload`, so an extension can add skill,
 * prompt-template and theme directories of its own. Upstream's `ResourcesDiscoverEvent`.
 */
final readonly class ResourcesDiscoverEvent implements ExtensionEvent
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
