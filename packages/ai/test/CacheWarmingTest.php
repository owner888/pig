<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\CacheWarming;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Async\AbortController;

/**
 * Upstream's `cache-warming.ts` (#11857), the policy `CodingAgent\Session\CacheWarmer` schedules —
 * the scheduling and the decisions are `CacheWarmerTest`'s; this is the refresh's own options.
 */
final class CacheWarmingTest extends TestCase
{
    public function testARefreshIsTheRequestItselfCappedAtOneTokenWithoutRetries(): void
    {
        $signal = (new AbortController())->signal;
        $options = new SimpleStreamOptions(
            temperature: 0.5,
            maxTokens: 8_000,
            apiKey: 'k',
            reasoning: ReasoningEffort::High,
            toolChoice: 'auto',
            cacheRetention: 'long',
            sessionId: 's',
            maxRetries: 3,
            thinkingBudgets: ['high' => 12_000],
        );

        $refresh = CacheWarming::getCacheWarmingRequestOptions($options, $signal);

        $this->assertSame(1, $refresh->maxTokens);
        $this->assertSame(0, $refresh->maxRetries);
        $this->assertTrue($refresh->disableContinuation);
        $this->assertSame($signal, $refresh->signal);
        // Upstream spreads the request's options; a thinking budget left behind changed what the
        // refresh asked for, and with it the cache entry it was meant to keep.
        $this->assertSame(['high' => 12_000], $refresh->thinkingBudgets);
        $this->assertSame(ReasoningEffort::High, $refresh->reasoning);
        $this->assertSame('auto', $refresh->toolChoice);
        $this->assertSame('long', $refresh->cacheRetention);
        $this->assertSame('s', $refresh->sessionId);
        $this->assertSame(0.5, $refresh->temperature);
    }

    public function testTheDelayLeavesTenSecondsOrTenPercent(): void
    {
        $this->assertNull(CacheWarming::getCacheWarmingDelayMs(10_000));
        $this->assertSame(270_000, CacheWarming::getCacheWarmingDelayMs(300_000));
        $this->assertSame(10_000, CacheWarming::getCacheWarmingDelayMs(20_000));
    }
}
