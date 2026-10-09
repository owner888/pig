<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/** Fired when a session is started, loaded, or reloaded. */
final readonly class SessionStartEvent implements HookEvent
{
    /**
     * @param 'startup'|'reload'|'new'|'resume'|'fork' $reason why this session start happened
     * @param string|null $previousSessionFile the file that was active before; present for `new`, `resume` and `fork`
     */
    public function __construct(
        public string $reason = 'startup',
        public ?string $previousSessionFile = null,
    ) {
    }

    public function type(): string
    {
        return 'session_start';
    }
}
