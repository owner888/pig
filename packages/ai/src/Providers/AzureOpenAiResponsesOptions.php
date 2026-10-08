<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ReasoningEffort;
use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Upstream's `AzureOpenAIResponsesOptions`: `AzureEndpointOptions` (`azure-openai-config.ts`) and
 * the three Responses knobs Azure's arm reads.
 *
 * @see AzureOpenAiConfig for what the four endpoint fields decide
 */
final readonly class AzureOpenAiResponsesOptions extends StreamOptions
{
    /**
     * @param ReasoningEffort|null $reasoningEffort upstream's `reasoningEffort`, sent as the model's
     *        `thinkingLevelMap` word for it
     * @param string|null $toolChoice upstream's `toolChoice`, sent as `tool_choice`
     * @param string|null $reasoningSummary upstream's `reasoningSummary` (`auto`, `detailed`,
     *        `concise`): sent as `reasoning.summary`, `auto` when not given; given without a
     *        `reasoningEffort` it asks for `medium` effort
     * @param string|null $azureApiVersion upstream's `azureApiVersion`
     * @param string|null $azureResourceName upstream's `azureResourceName`
     * @param string|null $azureBaseUrl upstream's `azureBaseUrl`
     * @param string|null $azureDeploymentName upstream's `azureDeploymentName`
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
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
        public ?ReasoningEffort $reasoningEffort = null,
        public ?string $toolChoice = null,
        public ?string $reasoningSummary = null,
        public ?string $azureApiVersion = null,
        public ?string $azureResourceName = null,
        public ?string $azureBaseUrl = null,
        public ?string $azureDeploymentName = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env);
    }
}
