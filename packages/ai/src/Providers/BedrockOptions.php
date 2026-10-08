<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/** Amazon Bedrock's own knobs on top of the shared ones — upstream's `BedrockOptions`. */
final readonly class BedrockOptions extends StreamOptions
{
    /**
     * @param string|null $region upstream's `region`: wins over `AWS_REGION` / `AWS_DEFAULT_REGION`,
     *        loses to the region in an inference-profile ARN
     * @param string|null $profile upstream's `profile`: a named AWS profile, which then wins over
     *        ambient access keys (#6957)
     * @param string|array{type: 'tool', name: string}|null $toolChoice `auto`, `any`, `none` or
     *        `['type' => 'tool', 'name' => …]`; `none` sends no tools at all
     * @param string|null $reasoning upstream's `reasoning`, a `ThinkingLevel`: minimal, low, medium,
     *        high, xhigh or max. "See https://docs.aws.amazon.com/bedrock/latest/userguide/inference-reasoning.html
     *        for supported models."
     * @param array<string, int>|null $thinkingBudgets upstream's `thinkingBudgets`: "Custom token
     *        budgets per thinking level. Overrides default budgets."
     * @param bool|null $interleavedThinking "Only supported by Claude 4.x models"; unset is on
     * @param string|null $thinkingDisplay `summarized` (the default here) or `omitted`
     * @param array<string, string>|null $requestMetadata "Key-value pairs attached to the inference
     *        request for cost allocation tagging."
     * @param string|null $bearerToken "Bearer token for Bedrock API key authentication. When set,
     *        bypasses SigV4 signing and sends Authorization: Bearer <token> instead."
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?string $region = null,
        public ?string $profile = null,
        public string|array|null $toolChoice = null,
        public ?string $reasoning = null,
        public ?array $thinkingBudgets = null,
        public ?bool $interleavedThinking = null,
        public ?string $thinkingDisplay = null,
        public ?array $requestMetadata = null,
        public ?string $bearerToken = null,
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
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env);
    }
}
