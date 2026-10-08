<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Gemini's own knobs.
 *
 * Thinking is said two different ways depending on the model, which is why both are here
 * rather than one: Gemini 2.5 takes a token budget; Gemini 3, the `-latest` aliases and Gemma 4
 * take a named level and ignore the budget entirely (`GoogleShared::usesGoogleThinkingLevel()`).
 *
 * `enabled` is not redundant with the other two. Gemini thinks *by default* — "dynamic
 * thinking" — so not asking for it is not the same as asking for none, and a caller that
 * wants none has to say so. Null is upstream's `thinking` left out altogether: no
 * `thinkingConfig` is sent, and the model does what it does by default. `Stream::simple()`
 * always says, true or false.
 */
final readonly class GoogleOptions extends StreamOptions
{
    /**
     * @param bool|null   $thinkingEnabled upstream's `thinking.enabled`; null is no `thinking` at all
     * @param int|null    $thinkingBudget tokens to spend thinking; -1 lets the model decide
     * @param string|null $thinkingLevel  MINIMAL, LOW, MEDIUM or HIGH, for a level model
     * @param string|null $toolChoice     auto, none or any
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
