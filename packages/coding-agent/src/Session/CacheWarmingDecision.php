<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/** Inputs and outcome of one warm-or-stop decision, as `/session` shows it — upstream's `CacheWarmingDecision`. */
final readonly class CacheWarmingDecision
{
    /**
     * @param 'streaming'|'idle' $phase streaming while the run that sent the request is still going
     * @param float $warmCost  price of this refresh: a cache read of the prompt plus one output token
     * @param float $missCost  extra price of the next real request if the cache entry is lost
     * @param bool  $economicsAvailable false when the prompt size or the model's prices are unknown
     * @param 'warm'|'stop' $action warm when the expected saving is at least the threshold
     */
    public function __construct(
        public string $phase,
        public float $warmCost,
        public float $missCost,
        public float $continuationProbability,
        public float $expectedSavings,
        public bool $economicsAvailable,
        public string $action,
    ) {
    }
}
