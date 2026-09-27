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
     * **`$tools` is `[]` for "none", where upstream's is `undefined`** — and that is a difference
     * on the wire rather than only in the types, because `[]` is *truthy* in JavaScript: its
     * Anthropic, OpenAI-completions and OpenAI-responses providers all guard with
     * `if (context.tools)` and therefore send `tools: []` for a conversation with no tools, while
     * pig's `!== []` sends no field at all. Only its Google provider asks `.length > 0`, as pig
     * does everywhere. No defect has been traced to either, and the one case where the empty field
     * is load-bearing is already handled: `OpenAiCompletions` sends `tools: []` when the
     * conversation holds tool calls, because some proxies reject one otherwise — a branch upstream
     * needs no equivalent of, since its condition covers it by accident.
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
