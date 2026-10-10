<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/** Upstream's `CacheMiss`: one counted cache miss on a single assistant message. */
final readonly class CacheMiss
{
    /**
     * @param int   $missedTokens prompt tokens that were in the previous request's prompt but not read from cache
     * @param float $missedCost   extra dollars paid against a full cache hit; 0 when pricing is unknown
     * @param int   $idleMs       milliseconds since the previous request, which last refreshed the cache
     * @param bool  $modelChanged the model changed since the previous request
     */
    public function __construct(
        public int $missedTokens,
        public float $missedCost,
        public int $idleMs,
        public bool $modelChanged,
    ) {
    }
}
