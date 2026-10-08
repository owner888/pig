<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\StreamOptions;
use Pig\Async\AbortSignal;

/** Upstream's `PiMessagesOptions`. */
final readonly class PiMessagesOptions extends StreamOptions
{
    /**
     * @param string|null $reasoning upstream's `reasoning`, a `ThinkingLevel` (`minimal` … `max`),
     *        sent as it is: the backend maps and clamps it
     * @param string|array<string, mixed>|null $toolChoice upstream's `toolChoice`: `auto`, `none`,
     *        `required`, or `{type: "function", function: {name}}`
     * @param bool $debug upstream's `debug`: "Ask the backend for debug metadata (e.g. routing
     *        response headers)" — `?debug=1` on the URL
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
        public ?string $reasoning = null,
        public string|array|null $toolChoice = null,
        public bool $debug = false,
    ) {
        parent::__construct($temperature, $maxTokens, $signal, $apiKey, $cacheRetention, $sessionId, $metadata, $headers, $timeoutMs, $maxRetries, $maxRetryDelayMs, $onPayload, $onResponse, $onProviderStreamEvent, $env);
    }
}
