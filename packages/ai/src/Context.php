<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Everything one request to a model is made of. */
final readonly class Context
{
    /**
     * Upstream orders these systemPrompt, messages, tools; PHP wants the required
     * parameter first, so messages leads.
     *
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     */
    public function __construct(
        public array $messages,
        public ?string $systemPrompt = null,
        public array $tools = [],
    ) {
    }
}
