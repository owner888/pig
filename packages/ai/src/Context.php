<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Request input accepted by the public stream entry points (`Stream::simple()`, `Stream::start()`)
 * — upstream's `Context`. `systemPrompt` and `tools` are shorthand for a leading system message;
 * `Utils\Transcript::normalizeContext()` folds them into one before the request reaches a
 * provider, which only ever sees a `TranscriptContext`.
 *
 * Holds the canonical alias for the Message union. PHP has union types but no way to
 * name one — `type Message = A|B` is a parse error — so the name lives in a docblock
 * and travels via `@phpstan-import-type Message from \Pig\Ai\Context`. Being a closed
 * union rather than a marker interface is what lets a `match` over a message be checked
 * for exhaustiveness.
 *
 * @phpstan-type Message SystemMessage|UserMessage|AssistantMessage|ToolResultMessage
 */
final readonly class Context
{
    /**
     * Upstream orders these systemPrompt, messages, tools; PHP wants the required
     * parameter first, so messages leads.
     *
     * `$tools` is `[]` for "none", where upstream's is `undefined`; `createInitialSystemMessage()`
     * treats an empty list and a missing one alike, so nothing on the wire depends on which.
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
