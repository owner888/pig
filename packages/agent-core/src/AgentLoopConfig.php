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
    ) {
    }
}
