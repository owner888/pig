<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/** Mistral's own knobs on top of the shared ones — upstream's `MistralOptions`. */
final readonly class MistralOptions extends StreamOptions
{
    /**
     * @param string|array{type: 'function', function: array{name: string}}|null $toolChoice `auto`,
     *        `none`, `any` or `required`, or upstream's object form `{type: "function", function:
     *        {name}}` naming one tool; sent as `tool_choice` (`mapToolChoice()`)
     * @param string|null $promptMode      `reasoning`, sent as `prompt_mode` — how a reasoning model with
     *        no effort levels (Magistral) is asked to think
     * @param string|null $reasoningEffort `none`, `low`, `medium`, `high` or `max`, sent as
     *        `reasoning_effort` — what a model whose `thinkingLevelMap` names its efforts is sent
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public string|array|null $toolChoice = null,
        public ?string $promptMode = null,
        public ?string $reasoningEffort = null,
        ?string $cacheRetention = null,
        ?string $sessionId = null,
        ?array $metadata = null,
        ?int $timeoutMs = null,
        ?array $headers = null,
        ?int $maxRetries = null,
        ?int $maxRetryDelayMs = null,
        ?\Closure $onPayload = null,
        ?\Closure $onResponse = null,
        ?\Closure $onProviderStreamEvent = null,
        ?array $env = null,
        ?string $transport = null,
        ?int $websocketConnectTimeoutMs = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env, $transport, $websocketConnectTimeoutMs);
    }
}
