<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SystemMessage;
use Pig\Ai\Utils\Transcript;

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
     * The model an agent has when nobody said which — upstream's, and the cheapest thing Google
     * sells, which is why it can be a default at all: five lines of library code answer without
     * anybody choosing a model, and choosing badly for somebody is the thing a default must not do.
     *
     * Two constants rather than a string to parse, and the one place to change it. `bin/pig` never
     * reaches this — `CodingAgent::session()` resolves its own model (its own default is
     * `claude-sonnet-4-5`) and calls `setModel()` before anything is sent.
     */
    public const string DEFAULT_PROVIDER = 'google';

    /**
     * **This id dates, and the first regeneration of the registry proved it.**
     *
     * It was `gemini-2.5-flash-lite-preview-06-17`, which models.dev has since dropped, so
     * `Models::find()` answered null and an `Agent` nobody configured was back to throwing
     * `No model configured` — the exact thing these two constants were added to stop. The default
     * has to be an id the registry carries, and the registry is generated now, so
     * `AgentTest::testAnAgentNobodyConfiguredStillHasAModel` is the tripwire rather than a
     * formality: a regeneration that retires this model is supposed to turn it red.
     *
     * The rule for replacing it is the rule that chose it — **the cheapest thing Google sells**,
     * because five lines of library code should not pick an expensive model on somebody's behalf.
     */
    public const string DEFAULT_MODEL = 'gemini-2.5-flash-lite';

    /**
     * Upstream's `createMutableAgentState()`: `$systemPrompt` and `$tools` become the leading system
     * message (`Transcript::createInitialSystemMessage()`, the tools as declarations) unless
     * `$messages` already starts with one. The prompt is not kept beside the transcript any more —
     * `systemPrompt()` replays it from there.
     *
     * @param string          $systemPrompt      seeds the leading system message
     * @param Model|null      $model             null takes the default above; still nullable
     *        afterwards, because a default that has left the registry resolves to null and
     *        `Agent::prompt()` has to say "No model configured" rather than fail deeper down
     * @param list<AgentTool> $tools             what the runtime can execute; differences from the
     *        tools the transcript declares are announced with a system message before the next request
     * @param list<mixed>     $messages          the conversation, app messages included; its system
     *        messages carry the prompt and the tool declarations
     * @param mixed           $streamMessage     the assistant message still arriving, or null
     * @param list<string>    $pendingToolCalls  ids of tools running right now
     */
    public function __construct(
        string $systemPrompt = '',
        public ?Model $model = null,
        public ThinkingLevel $thinkingLevel = ThinkingLevel::Off,
        public array $tools = [],
        public array $messages = [],
        public bool $isStreaming = false,
        public mixed $streamMessage = null,
        public array $pendingToolCalls = [],
        public ?string $error = null,
    ) {
        $this->model ??= Models::find(self::DEFAULT_PROVIDER, self::DEFAULT_MODEL);
        $initial = Transcript::createInitialSystemMessage(
            $systemPrompt,
            array_map(static fn (AgentTool $tool): \Pig\Ai\Tool => Transcript::toToolDeclaration($tool->definition()), $tools),
        );

        if (!(($this->messages[0] ?? null) instanceof SystemMessage) && $initial !== null) {
            array_unshift($this->messages, $initial);
        }
    }

    /**
     * Current system prompt, replayed from the transcript's system messages — upstream's read-only
     * `state.systemPrompt`. A method because PHP 8.3 has no property hooks: to change the prompt,
     * append a system message with `content` or `sections`.
     */
    public function systemPrompt(): string
    {
        return Transcript::getCurrentSystemPrompt($this->messages);
    }
}
