<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

/**
 * Upstream's `ModelAuth`: "Request auth for a single model request. If a value cannot be expressed
 * as `apiKey`, `headers`, or `baseUrl`, it is provider config, not auth."
 */
final readonly class ModelAuth
{
    /** @param array<string, string> $headers */
    public function __construct(
        public ?string $apiKey = null,
        public array $headers = [],
        public ?string $baseUrl = null,
    ) {
    }
}
