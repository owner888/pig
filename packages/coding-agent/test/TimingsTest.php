<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Timings;

/**
 * Where the startup time went, when anybody asked.
 *
 * The interesting half is that it is *off*: the marks sit in `bin/pig` unconditionally, so
 * "does nothing when the variable is unset" is the property that keeps them from costing
 * anything at every start.
 */
final class TimingsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        putenv('PIG_TIMING');
        Timings::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_TIMING');
        Timings::reset();
    }

    public function testNothingIsCollectedUnlessItWasAskedFor(): void
    {
        Timings::mark('one');
        Timings::mark('two');

        // The calls are unconditional in `bin/pig`, so this is what keeps them free.
        $this->assertSame([], Timings::steps());
    }

    public function testSomethingOtherThanOneIsStillOff(): void
    {
        // Upstream tests `=== "1"` and so does this: `PIG_TIMING=0` or `=true` meaning "on"
        // would be a variable that cannot be turned off the obvious way.
        putenv('PIG_TIMING=0');
        Timings::reset();
        Timings::mark('one');

        $this->assertSame([], Timings::steps());
    }

    public function testEachMarkRecordsTheGapSinceTheOneBeforeIt(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        Timings::mark('start');
        usleep(30_000);
        Timings::mark('settings');

        $steps = Timings::steps();

        $this->assertCount(2, $steps);
        $this->assertSame('start', $steps[0][0]);
        // The first is an anchor: there was nothing before it to measure from.
        $this->assertSame(0, $steps[0][1]);

        $this->assertSame('settings', $steps[1][0]);
        // Gaps, not moments. A list of timestamps would need subtracting by whoever read it,
        // which is the part nobody does.
        $this->assertGreaterThanOrEqual(20, $steps[1][1]);
    }

    public function testNothingMeasuredSaysNothing(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        // `PIG_TIMING=1 bin/pig --version` should not answer with a heading and no rows.
        $this->assertSame('', Timings::table());
    }

    public function testTheTableNamesEveryStepAndAddsThemUp(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        Timings::mark('start');
        usleep(20_000);
        Timings::mark('settings');
        usleep(20_000);
        Timings::mark('skills');

        $table = Timings::table();

        $this->assertStringContainsString('Startup timings', $table);
        $this->assertStringContainsString('settings', $table);
        $this->assertStringContainsString('skills', $table);
        $this->assertStringContainsString('TOTAL', $table);
        // Asserted against `table()` and not `report()`: the latter writes to standard error,
        // which `ob_start()` does not capture, so a test against it would pass whatever the
        // table said — which is a test that proves nothing while looking like it does.
        $this->assertMatchesRegularExpression('/TOTAL\s+\d+ms/', $table);
    }

    public function testWithNothingAskedForThereIsNoTableEither(): void
    {
        Timings::mark('one');

        $this->assertSame('', Timings::table());
    }
}
