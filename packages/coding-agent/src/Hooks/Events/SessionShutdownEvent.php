<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Fired before the hooks are torn down or the conversation under them replaced: on the way
 * out of a mode (`quit`, after `/exit` or a second Ctrl-C — not after a kill or a crash, where
 * nothing gets to run), before `/reload`, and before `/new` or `/resume` replace the session.
 */
final readonly class SessionShutdownEvent implements HookEvent
{
    /**
     * @param 'quit'|'reload'|'new'|'resume'|'fork' $reason
     * @param string|null $targetSessionFile the file being switched to, when the reason is a replacement
     */
    public function __construct(
        public string $reason = 'quit',
        public ?string $targetSessionFile = null,
    ) {
    }

    public function type(): string
    {
        return 'session_shutdown';
    }
}
