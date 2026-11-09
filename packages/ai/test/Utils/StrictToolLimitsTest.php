<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\StrictToolLimits;

/** Upstream's `strict-tool-limits.test.ts`. */
final class StrictToolLimitsTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        StrictToolLimits::cleanup();
    }

    public function testTheLimitMessagesAreRecognised(): void
    {
        $this->assertTrue(StrictToolLimits::isErrorMessage('The compiled grammar is too large, reduce the tools'));
        $this->assertTrue(StrictToolLimits::isErrorMessage('Too many strict tools (21). The limit is 20.'));
        $this->assertFalse(StrictToolLimits::isErrorMessage('messages: field required'));
    }

    public function testASessionIsRememberedUntilItIsCleanedUp(): void
    {
        StrictToolLimits::markExceeded(null);
        $this->assertFalse(StrictToolLimits::hasExceeded(null));

        StrictToolLimits::markExceeded('a');
        StrictToolLimits::markExceeded('b');
        $this->assertTrue(StrictToolLimits::hasExceeded('a'));

        StrictToolLimits::cleanup('a');
        $this->assertFalse(StrictToolLimits::hasExceeded('a'));
        $this->assertTrue(StrictToolLimits::hasExceeded('b'));

        StrictToolLimits::cleanup();
        $this->assertFalse(StrictToolLimits::hasExceeded('b'));
    }

    public function testTheOldestSessionGoesPastTenThousand(): void
    {
        for ($i = 0; $i <= 10_000; $i++) {
            StrictToolLimits::markExceeded("s{$i}");
        }

        $this->assertFalse(StrictToolLimits::hasExceeded('s0'));
        $this->assertTrue(StrictToolLimits::hasExceeded('s1'));
        $this->assertTrue(StrictToolLimits::hasExceeded('s10000'));
    }
}
