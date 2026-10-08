<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Vertex AI's own knobs — upstream's `GoogleVertexOptions`: Gemini's (`GoogleOptions`, whose
 * docblock says why thinking is three fields), plus where the project lives.
 */
final readonly class GoogleVertexOptions extends StreamOptions
{
    /**
     * @param bool|null   $thinkingEnabled upstream's `thinking.enabled`; null is no `thinking` at all
     * @param int|null    $thinkingBudget tokens to spend thinking; -1 lets the model decide, 0 disables
     * @param string|null $thinkingLevel  MINIMAL, LOW, MEDIUM or HIGH, for a level model
     * @param string|null $toolChoice     auto, none or any
     * @param string|null $project upstream's `project`: wins over `GOOGLE_CLOUD_PROJECT` / `GCLOUD_PROJECT`
     * @param string|null $location upstream's `location`: wins over `GOOGLE_CLOUD_LOCATION`
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?AbortSignal $signal = null,
        ?string $apiKey = null,
        public ?bool $thinkingEnabled = null,
        public ?int $thinkingBudget = null,
        public ?string $thinkingLevel = null,
        public ?string $toolChoice = null,
        public ?string $project = null,
        public ?string $location = null,
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
