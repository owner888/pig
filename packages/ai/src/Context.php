<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Everything one request to a model is made of.
 *
 * Holds the canonical alias for the Message union. PHP has union types but no way to
 * name one — `type Message = A|B` is a parse error — so the name lives in a docblock
 * and travels via `@phpstan-import-type Message from \Pig\Ai\Context`. Being a closed
 * union rather than a marker interface is what lets a `match` over a message be checked
 * for exhaustiveness.
 *
 * @phpstan-type Message UserMessage|AssistantMessage|ToolResultMessage
 */
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
