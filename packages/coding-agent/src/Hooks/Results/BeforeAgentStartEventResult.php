<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * A note to put in front of the prompt, as a user message.
 *
 * Upstream carries a whole `HookMessage` here — a custom type, a display mode and
 * details for a renderer the hook registered. pig has no custom message types yet, so
 * what survives the port is the part that reaches the model: the text.
 */
final readonly class BeforeAgentStartEventResult
{
    public function __construct(public string $text)
    {
    }
}
