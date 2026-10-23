<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Closure;
use LogicException;
use Pig\Ai\ImageContent;
use Pig\CodingAgent\Hooks\HookEvent;
use Pig\CodingAgent\Prompt\SystemPromptOptions;
use Pig\CodingAgent\Session\HookMessage;

/**
 * A run is about to start, before the agent sees its first message — upstream's
 * `BeforeAgentStartEvent`, a union on `messageType`.
 *
 * `user` is a run started by a submitted prompt, an RPC prompt or `sendUserMessage()`: `prompt` is
 * the raw prompt text (after expansion) and `images` its pictures. `custom` is a run started by
 * `sendMessage(…, triggerTurn: true)` while idle: `message` is the custom message that starts it,
 * and there is no `prompt`. A handler that should act only on what a person typed checks
 * `$event->messageType === 'user'`.
 *
 * A handler may return a custom message to add to the run — the hook's chance to tell the model
 * something the person did not, like which branch the repository is on — or a `systemPrompt` to
 * send in place of the prompt for this run.
 *
 * `systemPromptOptions` is upstream's mutable options object: a handler adds, replaces or removes
 * a prompt section there (`$event->systemPromptOptions->sections['plan_mode'] = '…'`), and later
 * handlers see what earlier ones did.
 */
final readonly class BeforeAgentStartEvent implements HookEvent
{
    /**
     * @param 'user'|'custom'          $messageType
     * @param string|null              $prompt  the raw user prompt text (after expansion); null for `custom`
     * @param list<ImageContent>       $images
     * @param (Closure(): string)|null $systemPrompt renders the prompt from the options as they are now
     * @param HookMessage|null         $message the custom message that starts the run; null for `user`
     */
    public function __construct(
        public string $messageType = 'user',
        public ?string $prompt = null,
        public array $images = [],
        public SystemPromptOptions $systemPromptOptions = new SystemPromptOptions(),
        private ?Closure $systemPrompt = null,
        public ?HookMessage $message = null,
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
