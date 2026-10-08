<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Closure;
use LogicException;
use Pig\Ai\ImageContent;
use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Prompt\SystemPromptOptions;

/**
 * Someone pressed Enter, and this is what they typed, before the agent sees it.
 *
 * A handler may return a note to put in front of the prompt — the hook's chance to tell
 * the model something the person did not, like which branch the repository is on — or a
 * `systemPrompt` to send in place of the prompt for this run.
 *
 * `systemPromptOptions` is upstream's mutable options object: a handler adds, replaces or removes
 * a prompt section there (`$event->systemPromptOptions->sections['plan_mode'] = '…'`), and later
 * handlers see what earlier ones did.
 */
final readonly class BeforeAgentStartEvent implements HookEvent
{
    /**
     * @param list<ImageContent>       $images
     * @param (Closure(): string)|null $systemPrompt renders the prompt from the options as they are now
     */
    public function __construct(
        public string $prompt,
        public array $images = [],
        public SystemPromptOptions $systemPromptOptions = new SystemPromptOptions(),
        private ?Closure $systemPrompt = null,
    ) {
    }

    public function type(): string
    {
        return 'before_agent_start';
    }

    /** Upstream's `event.systemPrompt`: "rendered from systemPromptOptions and earlier handler changes". */
    public function systemPrompt(): string
    {
        if ($this->systemPrompt === null) {
            throw new LogicException('This before_agent_start event was not given the prompt to render.');
        }

        return ($this->systemPrompt)();
    }
}
