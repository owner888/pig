<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Async\AbortSignal;

/**
 * Provider-independent options, as the agent loop passes them.
 *
 * `reasoning` says how hard to think in the abstract; each provider turns that into its
 * own dialect — a token budget for Anthropic, an effort level for OpenAI.
 */
final readonly class SimpleStreamOptions extends StreamOptions
{
    /**
     * @param string|null $toolChoice upstream's provider-neutral `ToolChoice`, `auto` or `none`:
     *        "When omitted, adapters use provider-specific behavior." Handed to the Anthropic and
     *        Responses providers as their own `toolChoice`, as upstream's `streamSimple()`s do
     * @param array<string, int>|null $thinkingBudgets upstream's `thinkingBudgets`: the token budget
     *        for `minimal`, `low`, `medium` and `high`, over `Stream`'s defaults, for the models that
     *        think on a budget. From the `thinkingBudgets` setting
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
        public ?string $toolChoice = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
        ?array $headers = null,
        ?int $timeoutMs = null,
        ?int $maxRetries = null,
        ?int $maxRetryDelayMs = null,
        ?\Closure $onPayload = null,
        ?\Closure $onResponse = null,
        ?\Closure $onProviderStreamEvent = null,
        ?array $env = null,
        ?string $transport = null,
        ?int $websocketConnectTimeoutMs = null,
        public ?array $thinkingBudgets = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env, $transport, $websocketConnectTimeoutMs);
    }
}
