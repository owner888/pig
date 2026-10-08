<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Async\AbortSignal;

/** What every provider understands. Providers extend this with their own knobs. */
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
     */
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?AbortSignal $signal = null,
        public ?string $apiKey = null,
        public ?string $cacheRetention = null,
        public ?string $sessionId = null,
        public ?array $metadata = null,
    ) {
    }

    /**
     * Upstream's `resolveCacheRetention()`, which the Anthropic and Responses providers each carry a
     * copy of: the option when given, else `long` for `PI_CACHE_RETENTION=long` "for backward
     * compatibility", else `short`.
     */
    public function resolvedCacheRetention(): string
    {
        if ($this->cacheRetention !== null && $this->cacheRetention !== '') {
            return $this->cacheRetention;
        }

        return getenv('PI_CACHE_RETENTION') === 'long' ? 'long' : 'short';
    }
}
