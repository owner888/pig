<?php

declare(strict_types=1);

namespace Pig\Agent;

use Closure;

/** How an Agent is set up. Everything has a default that does the obvious thing. */
final readonly class AgentOptions
{
    /**
     * @param Closure(list<mixed>): list<mixed>|null $convertToLlm
     *        Defaults to keeping user, assistant and tool-result messages and dropping
     *        everything the app added.
     * @param Closure(list<mixed>, ?\Pig\Async\AbortSignal): list<mixed>|null $transformContext
     * @param Closure(mixed, \Pig\Ai\TranscriptContext, mixed): mixed|null $streamFn
     *        Stands in for the provider — a proxy backend, or a script in a test. It is handed a
     *        normalized transcript, the prompt and the tools in its system messages.
     * @param Closure(string): ?string|null $getApiKey resolved per call, for expiring tokens
     * @param Closure|null $onPayload upstream's `AgentOptions.onPayload`, handed to every request
     *        (`SimpleStreamOptions::$onPayload`)
     * @param Closure|null $onResponse upstream's `AgentOptions.onResponse`
     * @param Closure|null $onProviderStreamEvent upstream's `AgentOptions.onProviderStreamEvent`
     * @param int|null $maxRetryDelayMs upstream's `AgentOptions.maxRetryDelayMs`
     * @param Closure|null $prepareNextTurn upstream's `AgentOptions.prepareNextTurn` — see `Agent::$prepareNextTurn`
     * @param Closure|null $prepareNextTurnWithContext upstream's `AgentOptions.prepareNextTurnWithContext`
     */
    public function __construct(
        public ?AgentState $initialState = null,
        public ?Closure $convertToLlm = null,
        public ?Closure $transformContext = null,
        public QueueMode $steeringMode = QueueMode::OneAtATime,
        public QueueMode $followUpMode = QueueMode::OneAtATime,
        public ?Closure $streamFn = null,
        public ?Closure $getApiKey = null,
        public ?string $apiKey = null,
        public ?Closure $onPayload = null,
        public ?Closure $onResponse = null,
        public ?Closure $onProviderStreamEvent = null,
        public ?int $maxRetryDelayMs = null,
        public ?Closure $prepareNextTurn = null,
        public ?Closure $prepareNextTurnWithContext = null,
    ) {
    }
}
