<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentEvent;

/**
 * A cache-warming refresh was written down — upstream's `entry_appended` for a `cache_warm` usage
 * entry, which its terminal turns into a "Cache warmed: $x" line behind `showCacheMissNotices`.
 * Only this one entry kind is announced here, because it is the only one a screen draws.
 */
final readonly class CacheWarmedEvent implements AgentEvent
{
    public function __construct(public UsageEntry $entry)
    {
    }
}
