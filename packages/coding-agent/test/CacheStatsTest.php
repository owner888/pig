<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\CodingAgent\Session\CacheStats;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\UsageEntry;

/** Upstream's `cache-stats.ts`: what counts as a prompt billed again rather than read. */
final class CacheStatsTest extends TestCase
{
    public function testAPromptThatShouldHaveBeenReadAndWasBilledIsAMiss(): void
    {
        $first = self::turn(cacheWrite: 50_000, at: 1_000);
        $second = self::turn(input: 52_000, at: 2_000);

        $miss = CacheStats::detect([$first, $second], $second);

        $this->assertNotNull($miss);
        $this->assertSame(50_000, $miss->missedTokens, 'only what the previous prompt held could have been read');
        $this->assertSame(1_000, $miss->idleMs);
        $this->assertFalse($miss->modelChanged);
        // Paid at the input rate (3/M). With no read on this turn the read rate comes from the
        // registry, which has no `anthropic/test`, so nothing is taken off.
        $this->assertEqualsWithDelta(50_000 * 3.0 / 1_000_000, $miss->missedCost, 1e-9);
    }

    public function testTheReadRateComesFromTheTurnsOwnReadsWhenItHasThem(): void
    {
        $first = self::turn(cacheWrite: 50_000);
        $second = self::turn(input: 30_000, cacheRead: 20_000);

        $this->assertEqualsWithDelta(30_000 * (3.0 - 0.3) / 1_000_000, CacheStats::detect([$first, $second], $second)?->missedCost, 1e-9);
    }

    public function testAMissAtOrBelowTheNoiseFloorIsNotOne(): void
    {
        $first = self::turn(cacheWrite: 50_000);
        $read = self::turn(input: 1_024, cacheRead: 48_976);
        $missed = self::turn(input: 1_025, cacheRead: 48_975);

        $this->assertNull(CacheStats::detect([$first, $read], $read));
        $this->assertSame(1_025, CacheStats::detect([$first, $missed], $missed)?->missedTokens);
    }

    public function testACompactionResetsTheScan(): void
    {
        $first = self::turn(cacheWrite: 50_000);
        $after = self::turn(input: 52_000);
        $summary = new CompactionSummary('summary');

        $this->assertNull(CacheStats::detect([$first, $summary, $after], $after));
    }

    public function testAModelSwitchIsAMissAndSaysSo(): void
    {
        $first = self::turn(cacheWrite: 50_000);
        $second = self::turn(input: 52_000, model: 'other');

        $this->assertTrue(CacheStats::detect([$first, $second], $second)?->modelChanged);
    }

    public function testAProviderThatNeverReportsCachingNeverMisses(): void
    {
        $first = self::turn(input: 50_000);
        $second = self::turn(input: 52_000);

        $this->assertNull(CacheStats::detect([$first, $second], $second));
    }

    public function testAWarmingRefreshIsTheRequestThatLastWroteTheCache(): void
    {
        $first = self::turn(cacheWrite: 50_000, at: 1_000);
        $warm = new UsageEntry('cache_warm', 'anthropic', 'test', new Usage(cacheRead: 50_000, output: 1), timestamp: 400_000);
        $second = self::turn(input: 52_000, at: 401_000);

        $this->assertSame(1_000, CacheStats::detect([$first, $warm, $second], $second)?->idleMs);
    }

    public function testWasteSumsEveryMissAndCollectKeysThemByMessage(): void
    {
        $a = self::turn(cacheWrite: 50_000);
        $b = self::turn(input: 52_000);
        $c = self::turn(cacheRead: 52_000, input: 1_000);
        $d = self::turn(input: 60_000);

        $waste = CacheStats::waste([$a, $b, $c, $d]);
        $misses = CacheStats::collect([$a, $b, $c, $d]);

        $this->assertSame(2, $waste['missCount']);
        $this->assertSame(50_000 + 53_000, $waste['missedTokens']);
        $this->assertTrue(isset($misses[$b]));
        $this->assertFalse(isset($misses[$c]));
        $this->assertTrue(isset($misses[$d]));
    }

    private static function turn(int $input = 0, int $cacheRead = 0, int $cacheWrite = 0, string $model = 'test', int $at = 0): AssistantMessage
    {
        $cost = new Cost(
            input: $input * 3.0 / 1_000_000,
            cacheRead: $cacheRead * 0.3 / 1_000_000,
            cacheWrite: $cacheWrite * 3.75 / 1_000_000,
        );

        return new AssistantMessage(
            [new TextContent('ok')],
            Api::AnthropicMessages,
            'anthropic',
            $model,
            new Usage(input: $input, output: 10, cacheRead: $cacheRead, cacheWrite: $cacheWrite, cost: $cost),
            StopReason::Stop,
            timestamp: $at,
        );
    }
}
