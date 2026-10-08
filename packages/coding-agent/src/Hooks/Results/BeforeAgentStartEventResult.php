<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * A note to put in front of the prompt, as a user message, and/or the prompt to send instead.
 *
 * Upstream carries a whole `HookMessage` here — a custom type, a display mode and
 * details for a renderer the hook registered. pig has no custom message types yet, so
 * what survives the port is the part that reaches the model: the text.
 *
 * `systemPrompt` is upstream's: "Replace the complete system prompt for this turn. Later handlers
 * observe this exact override." It is sent and never recorded — see `AgentSession`'s forced prompt.
 */
final readonly class BeforeAgentStartEventResult
{
    public function __construct(
        public ?string $text = null,
        public ?string $systemPrompt = null,
    ) {
    }
}
