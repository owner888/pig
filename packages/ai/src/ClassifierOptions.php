<?php

declare(strict_types=1);

namespace Pig\Ai;

use Closure;
use Pig\Async\AbortSignal;

/**
 * Upstream's `ClassifierOptions`: the request options every provider takes
 * (`ProviderRequestOptions` — `fetch` and `telemetryContext` not ported, as on `StreamOptions`)
 * plus `temperature`.
 */
final readonly class ClassifierOptions
{
    /**
     * @param float|null $temperature "Divides the answer logits by this value before they are
     *        normalized into probabilities. Values above 1 soften the distribution; values below 1
     *        sharpen it. Must be positive. APIs that cannot apply it ignore it."
     * @param array<string, string|null>|null $headers merged over the model's own, caller values
     *        winning; a null suppresses a default header of that name
     * @param (Closure(mixed $payload, ClassifierModel $model): mixed)|null $onPayload sees the
     *        request body before it is sent; a non-null answer replaces it
     * @param (Closure(array{status: int, headers: array<string, string>} $response, ClassifierModel $model): void)|null $onResponse
     * @param array<string, string>|null $env provider-scoped environment values that win over the
     *        process environment
     */
    public function __construct(
        public ?AbortSignal $signal = null,
        public ?string $apiKey = null,
        public ?array $env = null,
        public ?Closure $onPayload = null,
        public ?Closure $onResponse = null,
        public ?array $headers = null,
        public ?int $timeoutMs = null,
        public ?int $maxRetries = null,
        public ?int $maxRetryDelayMs = null,
        public ?float $temperature = null,
    ) {
    }

    /** @return array<string, mixed> the fields as named arguments, for a copy with some replaced */
    public function args(): array
    {
        return get_object_vars($this);
    }
}
