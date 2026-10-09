<?php

declare(strict_types=1);

namespace Pig\Agent;

use Closure;
use Pig\Ai\Model;
use Pig\Ai\ReasoningEffort;

/**
 * Everything the loop needs that is not the conversation itself.
 *
 * The callbacks are where an application reaches in. `convertToLlm` is the only required
 * one, and it is the boundary: whatever the app keeps in the conversation, this decides
 * what the model is shown.
 */
final readonly class AgentLoopConfig
{
    /**
     * @param Closure(list<mixed>): list<mixed> $convertToLlm
     *        AgentMessage[] → Message[], run before every call. Messages that cannot
     *        become an LLM message — a status line, a notification — are dropped here.
     * @param Closure(list<mixed>, ?\Pig\Async\AbortSignal): list<mixed>|null $transformContext
     *        Runs before convertToLlm, at the agent's level: pruning for the context
     *        window, injecting external context.
     * @param Closure(): list<mixed>|null $getSteeringMessages
     *        Checked after each tool. Anything returned skips the remaining tool calls
     *        and goes in before the next model call — how "wait, do it this way" works.
     * @param Closure(): list<mixed>|null $getFollowUpMessages
     *        Checked when the agent would otherwise stop. Anything returned keeps it going.
     * @param Closure(string): ?string|null $getApiKey
     *        Resolved per call, for tokens that expire during a long run.
     * @param Closure(): list<AgentTool>|null $getTools
     *        Asked before every model call. The loop takes a snapshot of the tools when it
     *        starts; a tool that is registered *during* a run — `tool_search` loading an MCP
     *        tool is the case — has to reach the next request of the same run, and this is how.
     *        Upstream refreshes `context.tools` from `agent.state.tools` in `prepareRequest`.
     * @param string|null $sessionId upstream's `AgentLoopConfig.sessionId`, passed on as the
     *        request's `sessionId` — the key Anthropic's session affinity and the Responses API's
     *        `prompt_cache_key` use
     * @param Closure|null $onPayload upstream's `AgentLoopConfig.onPayload` (a `SimpleStreamOptions`
     *        field, spread into every request with `...config`), as are the next three
     * @param (Closure(PrepareRequestContext, ?\Pig\Async\AbortSignal): ?AgentLoopTurnUpdate)|null $prepareRequest
     *        upstream's `AgentLoopConfig.prepareRequest`: "Called immediately before every
     *        conversational provider request, including the first." Its answer replaces the
     *        context, the model and the thinking level for that request and the rest of the run;
     *        `messages` on it is not read. How a virtual model is routed to a physical one.
     * @param (Closure(PrepareNextTurnContext): ?AgentLoopTurnUpdate)|null $prepareNextTurn
     *        upstream's `AgentLoopConfig.prepareNextTurn`: "Called after `turn_end` when the loop will
     *        continue, immediately before the next turn starts. Return replacement
     *        context/model/thinking state or messages to append to affect that turn."
     */
    public function __construct(
        public Model $model,
        public Closure $convertToLlm,
        public ?ReasoningEffort $reasoning = null,
        public ?Closure $transformContext = null,
        public ?Closure $getSteeringMessages = null,
        public ?Closure $getFollowUpMessages = null,
        public ?Closure $getApiKey = null,
        public ?string $apiKey = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?Closure $getTools = null,
        public ?string $sessionId = null,
        public ?Closure $onPayload = null,
        public ?Closure $onResponse = null,
        public ?Closure $onProviderStreamEvent = null,
        public ?int $maxRetryDelayMs = null,
        public ?Closure $prepareNextTurn = null,
        public ?Closure $prepareRequest = null,
    ) {
    }

    /** A copy with the model and the reasoning level replaced, for an `AgentLoopTurnUpdate`. */
    public function withModel(Model $model, ?ReasoningEffort $reasoning): self
    {
        return new self(
            $model,
            $this->convertToLlm,
            $reasoning,
            $this->transformContext,
            $this->getSteeringMessages,
            $this->getFollowUpMessages,
            $this->getApiKey,
            $this->apiKey,
            $this->temperature,
            $this->maxTokens,
            $this->getTools,
            $this->sessionId,
            $this->onPayload,
            $this->onResponse,
            $this->onProviderStreamEvent,
            $this->maxRetryDelayMs,
            $this->prepareNextTurn,
            $this->prepareRequest,
        );
    }
}
