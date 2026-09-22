<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\Ai\ImageContent;
use Pig\CodingAgent\Hooks\HookEvent;

/**
 * Someone pressed Enter, and this is what they typed, before the agent sees it.
 *
 * A handler may return a note to put in front of the prompt — the hook's chance to tell
 * the model something the person did not, like which branch the repository is on.
 */
final readonly class BeforeAgentStartEvent implements HookEvent
{
    /** @param list<ImageContent> $images */
    public function __construct(
        public string $prompt,
        public array $images = [],
    ) {
    }

    public function type(): string
    {
        return 'before_agent_start';
    }
}
