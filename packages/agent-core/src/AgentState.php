<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Model;

/**
 * Everything a UI needs to draw the agent.
 *
 * Mutable, and deliberately so: the Agent updates it in place as events arrive and the
 * UI reads whatever is current. Everything below it — messages, events — is immutable,
 * so this is the one place state actually changes.
 */
final class AgentState
{
    /**
     * @param list<AgentTool> $tools
     * @param list<mixed>     $messages          the conversation, app messages included
     * @param mixed           $streamMessage     the assistant message still arriving, or null
     * @param list<string>    $pendingToolCalls  ids of tools running right now
     */
    public function __construct(
        public string $systemPrompt = '',
        public ?Model $model = null,
        public ThinkingLevel $thinkingLevel = ThinkingLevel::Off,
        public array $tools = [],
        public array $messages = [],
        public bool $isStreaming = false,
        public mixed $streamMessage = null,
        public array $pendingToolCalls = [],
        public ?string $error = null,
    ) {
    }
}
