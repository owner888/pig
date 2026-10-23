<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\CodingAgent\Session\HookMessage;

/**
 * What a `before_agent_start` handler adds to the run — upstream's `BeforeAgentStartEventResult`.
 *
 * `message` is a custom message for the run: every handler's goes in, after the run's starting
 * message, with the hook's custom type, display mode and details as `sendMessage()` takes them.
 *
 * `systemPrompt` is upstream's: "Replace the complete system prompt for this turn. Later handlers
 * observe this exact override." It is sent and never recorded — see `AgentSession`'s forced prompt.
 */
final readonly class BeforeAgentStartEventResult
{
    public function __construct(
        public ?HookMessage $message = null,
        public ?string $systemPrompt = null,
    ) {
    }
}
