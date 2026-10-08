<?php

declare(strict_types=1);

namespace Pig\Ai;

use Closure;
use Pig\Async\AbortSignal;

/**
 * What every provider understands. Providers extend this with their own knobs.
 *
 * Upstream's `StreamOptions` with its `ProviderRequestOptions` base. Not ported, because PHP has
 * nothing for them to mean: `fetch` (upstream's custom `fetch` implementation — a provider here is
 * handed its `HttpClient` in its constructor, which is the same seam), the Anthropic `client`
 * (there is no SDK client to inject), `transport`/`websocketConnectTimeoutMs` (no WebSocket
 * transport), `telemetryContext` (no telemetry), and `samplingParams` (no model carries any).
 */
readonly class StreamOptions
{
    /**
     * @param string|null $cacheRetention upstream's `cacheRetention`: `none`, `short` or `long`.
     *        Null is upstream's undefined, which a provider resolves as `long` when
     *        `PI_CACHE_RETENTION=long` is set and `short` otherwise (`resolveCacheRetention()`)
     * @param string|null $sessionId upstream's `sessionId`: what a provider that routes or caches by
     *        session keys on — Anthropic's session-affinity header, the Responses API's
     *        `prompt_cache_key`. Null sends none
     * @param array<string, mixed>|null $metadata upstream's `metadata`: "Providers extract the fields
     *        they understand and ignore the rest" — Anthropic reads `user_id`
     * @param array<string, string|null>|null $headers upstream's `headers` (`ProviderHeaders`):
     *        merged over the provider's and the model's own, caller values winning; "A null value
     *        suppresses a provider/API default header with the same name"
     * @param int|null $timeoutMs upstream's `timeoutMs`, with each provider's own meaning: the SDK
     *        request timeout (until the response headers) for Anthropic and both OpenAI APIs, the
     *        response-header deadline for Mistral; Gemini's SDK takes none
     * @param int|null $maxRetries upstream's `maxRetries`: provider-level retries of the initial
     *        request (`ProviderRetry::retryProviderRequest()`); unset is none
     * @param int|null $maxRetryDelayMs upstream's `maxRetryDelayMs`: the longest server-requested
     *        retry delay waited out (60,000 when unset, 0 for no cap)
     * @param (Closure(mixed $payload, Model $model): mixed)|null $onPayload upstream's `onPayload`:
     *        sees the request body before it is sent; a non-null answer replaces it
     * @param (Closure(array{status: int, headers: array<string, string>} $response, Model $model): void)|null $onResponse
     *        upstream's `onResponse`: the status and headers, before the body is read
     * @param (Closure(mixed $data, Model $model): void)|null $onProviderStreamEvent upstream's
     *        `onProviderStreamEvent`: each parsed provider stream event, before pig reads it
     * @param array<string, string>|null $env upstream's `env` (`ProviderEnv`): provider-scoped
     *        environment values that win over the process environment (`getProviderEnvValue()`)
     */
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?AbortSignal $signal = null,
        public ?string $apiKey = null,
        public ?string $cacheRetention = null,
        public ?string $sessionId = null,
        public ?array $metadata = null,
        public ?array $headers = null,
        public ?int $timeoutMs = null,
        public ?int $maxRetries = null,
        public ?int $maxRetryDelayMs = null,
        public ?Closure $onPayload = null,
        public ?Closure $onResponse = null,
        public ?Closure $onProviderStreamEvent = null,
        public ?array $env = null,
    ) {
    }

    /**
     * Upstream's `resolveCacheRetention(cacheRetention, env)`, which the Anthropic and Responses
     * providers each carry a copy of: the option when given, else `long` for
     * `PI_CACHE_RETENTION=long` "for backward compatibility", else `short`.
     */
    public function resolvedCacheRetention(): string
    {
        if ($this->cacheRetention !== null && $this->cacheRetention !== '') {
            return $this->cacheRetention;
        }

        return self::providerEnvValue('PI_CACHE_RETENTION', $this->env) === 'long' ? 'long' : 'short';
    }

    /**
     * Upstream's `getProviderEnvValue(name, env)`: the scoped override, then the process
     * environment, an empty value counting as none (`||`). Upstream's Bun sandbox fallback
     * (`/proc/self/environ` when `process.env` is empty) has no PHP counterpart: `getenv()` is
     * never emptied that way.
     *
     * @param array<string, string>|null $env
     */
    public static function providerEnvValue(string $name, ?array $env): ?string
    {
        $scoped = $env[$name] ?? null;

        if (is_string($scoped) && $scoped !== '') {
            return $scoped;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The base fields as named arguments — upstream's `...base` spread, for building a provider's
     * options from these: `new AnthropicOptions(...$options->baseArgs(), thinkingEnabled: true)`.
     *
     * @return array<string, mixed>
     */
    public function baseArgs(): array
    {
        return [
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'signal' => $this->signal,
            'apiKey' => $this->apiKey,
            'cacheRetention' => $this->cacheRetention,
            'sessionId' => $this->sessionId,
            'metadata' => $this->metadata,
            'headers' => $this->headers,
            'timeoutMs' => $this->timeoutMs,
            'maxRetries' => $this->maxRetries,
            'maxRetryDelayMs' => $this->maxRetryDelayMs,
            'onPayload' => $this->onPayload,
            'onResponse' => $this->onResponse,
            'onProviderStreamEvent' => $this->onProviderStreamEvent,
            'env' => $this->env,
        ];
    }
}
