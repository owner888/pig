<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions\Events;

use Pig\Agent\AgentEvent;
use Pig\CodingAgent\Extensions\ExtensionEvent;

/** The session name / display title changed. */
final readonly class SessionInfoChangedEvent implements ExtensionEvent, AgentEvent
{
    public function __construct(public string $name = '')
    {
    }

    public function type(): string
    {
        return 'session_info_changed';
    }
}
