<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/**
 * Upstream's `OpenAICodexResponsesOptions`.
 *
 * Upstream's `transport` (`sse`, `websocket`, `websocket-cached`, `auto`) and
 * `websocketConnectTimeoutMs` are not here: pig speaks only the SSE transport — see
 * `OpenAiCodexResponses`.
 */
final readonly class OpenAiCodexResponsesOptions extends StreamOptions
{
    /**
     * @param string|null $reasoningEffort upstream's `reasoningEffort`: `none`, `minimal`, `low`,
     *        `medium`, `high`, `xhigh` or `max` — a string rather than `ReasoningEffort` because
     *        `none` is one of them here, "off" spelled the way this backend spells it
     * @param string|null $reasoningSummary upstream's `reasoningSummary` (`auto`, `concise`,
     *        `detailed`, `off`, `on`): sent as `reasoning.summary`, `auto` when not given
     * @param string|null $serviceTier upstream's `serviceTier`, sent as `service_tier`; the turn is
     *        priced by it (`flex` halves, `priority` doubles, 2.5× for gpt-5.5)
     * @param string|null $textVerbosity upstream's `textVerbosity` (`low`, `medium`, `high`), sent
     *        as `text.verbosity`, `low` when not given
     * @param string|null $toolChoice upstream's `toolChoice` (`auto`, `none`, `required`), `auto`
     *        when not given
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
        public ?string $reasoningEffort = null,
        public ?string $reasoningSummary = null,
        public ?string $serviceTier = null,
        public ?string $textVerbosity = null,
        public ?string $toolChoice = null,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env);
    }
}
