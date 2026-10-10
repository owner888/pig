<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/** What the cache warmer is doing — upstream's `CacheWarmingStatus`. */
final readonly class CacheWarmingStatus
{
    /**
     * @param 'inactive'|'scheduled'|'refreshing' $state scheduled: a refresh timer is armed;
     *        refreshing: a warm request is in flight
     * @param string|null $reason why nothing is scheduled
     * @param int|null $nextWarmAt milliseconds since the epoch
     * @param CacheWarmingDecision|null $decision the pending decision, or the one that stopped warming
     * @param bool $extensionOverride a hook changed the decision's action
     */
    public function __construct(
        public string $state,
        public ?string $reason = null,
        public ?int $nextWarmAt = null,
        public ?CacheWarmingDecision $decision = null,
        public bool $extensionOverride = false,
    ) {
    }
}
