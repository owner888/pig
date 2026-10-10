<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ReasoningEffort;
use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * The knobs `openai-completions` has that the shared options do not.
 *
 * `reasoning` is the same idea as Anthropic's thinking budget, said the way this API
 * says it: an effort level rather than a number of tokens.
 */
final readonly class OpenAiOptions extends StreamOptions
{
    /**
     * @param string|null $serviceTier upstream's `OpenAIResponsesOptions.serviceTier`, read by the
     *        Responses provider only: sent as `service_tier`, and the turn's cost scaled by the tier
     *        the response reports (else this one) — `flex` halves it, `priority` doubles it
     * @param string|null $reasoningSummary upstream's `OpenAIResponsesOptions.reasoningSummary`
     *        (`auto`, `detailed` or `concise`), read by the Responses provider only: sent as
     *        `reasoning.summary`, `auto` when not given; given without a `reasoning` level it asks
     *        for `medium` effort. Upstream's `streamSimple()` never sets it, so neither does
     *        `Stream::simple()` — it is for a caller of `Stream::start()`
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?ReasoningEffort $reasoning = null,
        public ?string $toolChoice = null,
        public ?string $serviceTier = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
        public ?string $reasoningSummary = null,
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
        /** @var array<string, int>|null upstream's `thinkingBudgets`, read by Chat Completions' budget fields */
        public ?array $thinkingBudgets = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env, $transport, $websocketConnectTimeoutMs);
    }
}
