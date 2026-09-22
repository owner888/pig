<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * pig is quitting.
 *
 * Fired on the way out of the interactive mode, which means after a `/exit` or a second
 * Ctrl-C — not after a kill or a crash, where nothing gets to run.
 */
final readonly class SessionShutdownEvent implements HookEvent
{
    public function type(): string
    {
        return 'session_shutdown';
    }
}
