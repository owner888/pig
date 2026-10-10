<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Events;

use Pig\CodingAgent\Hooks\HookEvent;

/**
 * About to refresh the provider's prompt cache — upstream's `cache_warming_decision`.
 *
 * Fired before each refresh with pig's decision filled in: `$action` is `warm` when the expected
 * saving (`continuationProbability × missCost − warmCost`) is at least $0.05, `stop` otherwise. A
 * handler returning a `CacheWarmingDecisionEventResult` changes it; `stop` ends warming until the
 * next real request. Everything else a hook might want (the model, the context) is on the context.
 */
final readonly class CacheWarmingDecisionEvent implements HookEvent
{
    /** @param 'warm'|'stop' $action */
    public function __construct(
        public float $warmCost,
        public float $missCost,
        public float $continuationProbability,
        public string $action,
    ) {
    }

    public function type(): string
    {
        return 'cache_warming_decision';
    }
}
