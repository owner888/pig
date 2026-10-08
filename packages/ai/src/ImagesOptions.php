<?php

declare(strict_types=1);

namespace Pig\Ai;

use Closure;
use Pig\Async\AbortSignal;

/**
 * Upstream's `ImagesOptions`: the request options every provider takes (`fetch` and
 * `telemetryContext` not ported, as on `StreamOptions`) plus `metadata`.
 */
final readonly class ImagesOptions
{
    /**
     * @param array<string, mixed>|null $metadata "Providers extract the fields they understand and
     *        ignore the rest" — OpenRouter's reads none
     * @param array<string, string|null>|null $headers merged over the model's own, caller values winning
     * @param (Closure(mixed $payload, ImageModel $model): mixed)|null $onPayload
     * @param (Closure(array{status: int, headers: array<string, string>} $response, ImageModel $model): void)|null $onResponse
     * @param array<string, string>|null $env
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
        public ?array $metadata = null,
    ) {
    }

    /** @return array<string, mixed> the fields as named arguments, for a copy with some replaced */
    public function args(): array
    {
        return get_object_vars($this);
    }
}
