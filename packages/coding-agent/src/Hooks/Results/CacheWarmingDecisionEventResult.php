<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/** Whether this cache refresh is sent: `warm` or `stop`. Null leaves pig's decision. */
final readonly class CacheWarmingDecisionEventResult
{
    /** @param 'warm'|'stop'|null $action */
    public function __construct(public ?string $action = null)
    {
    }
}
