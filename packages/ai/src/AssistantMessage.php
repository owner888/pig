<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A turn from the model: text, reasoning, and tool calls, plus what it cost.
 *
 * Also the shape a stream carries while it is still filling up — the `partial` on
 * every event is one of these, and the same class is the final result.
 *
 * One arm of the Message union — see Context for the alias.
 */
final readonly class AssistantMessage
{
    public int $timestamp;

    /**
     * @param list<AssistantContent> $content
     * @param string                 $provider anthropic, openai, groq, … — free-form,
     *        since providers can be added without touching this package
     * @param string|null            $rawStopReason the provider's own word for why it stopped
     *        (`end_turn`, `length`, `incomplete.max_output_tokens`, `MALFORMED_FUNCTION_CALL`, …),
     *        kept beside the mapped `stopReason` for debugging. Last so the positional calls that
     *        predate it keep working; null when the provider sent none.
     * @param string|null            $responseId the provider's id for this response — Anthropic's
     *        message id, a completions chunk id, a responses API response id, Gemini's `responseId`
     * @param string|null            $responseModel the model the provider says answered, set only
     *        when it is not the `model` that was asked for (an alias resolved, a router's pick)
     * @param bool|null              $endTurn whether the model said it was done with its turn.
     *        Upstream sets it only from the Codex responses API (`response.end_turn` on its terminal
     *        event), and so does `Providers\OpenAiCodexResponses`
     * @param list<AssistantMessageDiagnostic>|null $diagnostics what the provider or the runtime
     *        noticed about the turn, for `/bug`
     * @param string|null            $providerThinkingLevel upstream's "exact provider-native effort
     *        level used for this response" — set by `Anthropic` on a managed-effort model
     *        (`AnthropicCompat::$supportsMidConvoEffort`), which replays it in front of this turn on
     *        later requests. Null for every other response
     * @param array<string, mixed>|null $deferred upstream's `DeferredHandle` on a `deferred` turn —
     *        `{provider, modelId, api, id, expiresAt?, pollAfterMs?, data?}`, the provider's token for
     *        the result it will produce later. Carried as written; see `StopReason::Deferred`
     *
     * @param string|null            $thinkingLevel upstream's "Pi thinking level the agent loop
     *        requested for this response" — a `ThinkingLevel` value, stamped by the agent loop on
     *        every response it streams (`AgentLoop::streamAssistantResponse()`). Null outside the
     *        loop (a summary, a provider called directly) and on responses written before it existed
     *
     * The six after `rawStopReason` follow it for the same reason it is last: they are upstream's
     * optional fields, absent from JSON when null, and appended so no positional call changes.
     */
    public function __construct(
        public array $content,
        public Api $api,
        public string $provider,
        public string $model,
        public Usage $usage,
        public StopReason $stopReason,
        public ?string $errorMessage = null,
        ?int $timestamp = null,
        public ?string $rawStopReason = null,
        public ?string $responseId = null,
        public ?string $responseModel = null,
        public ?bool $endTurn = null,
        public ?array $diagnostics = null,
        public ?string $providerThinkingLevel = null,
        public ?array $deferred = null,
        public ?string $thinkingLevel = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** This response with the thinking level it was asked with — upstream's `Object.assign(result, {thinkingLevel})`. */
    public function withThinkingLevel(string $thinkingLevel): self
    {
        return new self(
            $this->content,
            $this->api,
            $this->provider,
            $this->model,
            $this->usage,
            $this->stopReason,
            $this->errorMessage,
            $this->timestamp,
            $this->rawStopReason,
            $this->responseId,
            $this->responseModel,
            $this->endTurn,
            $this->diagnostics,
            $this->providerThinkingLevel,
            $this->deferred,
            $thinkingLevel,
        );
    }

    /** @return list<ToolCall> */
    public function toolCalls(): array
    {
        return array_values(array_filter(
            $this->content,
            static fn (AssistantContent $block): bool => $block instanceof ToolCall,
        ));
    }
}
