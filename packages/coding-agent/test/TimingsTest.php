<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Timings;

/**
 * Where the startup time went, when anybody asked — upstream's `core/timings.ts`.
 *
 * The interesting half is that it is *off*: the calls sit in `bin/pig` unconditionally, so
 * "does nothing when the variable is unset" is the property that keeps them from costing
 * anything at every start.
 */
final class TimingsTest extends TestCase
{
    private ?string $root = null;

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

        if ($this->root !== null) {
            foreach (glob("{$this->root}/*/*") ?: [] as $file) {
                unlink($file);
            }
            foreach (glob("{$this->root}/*") ?: [] as $dir) {
                rmdir($dir);
            }
            rmdir($this->root);
        }
    }

    public function testNothingIsCollectedUnlessItWasAskedFor(): void
    {
        Timings::resetTimings();
        Timings::time('one');
        Timings::time('two', 'extensions');

        // The calls are unconditional in `bin/pig`, so this is what keeps them free.
        $this->assertSame([], Timings::timings());
        $this->assertSame([], Timings::timings('extensions'));
        $this->assertSame('', Timings::table());
    }

    public function testSomethingOtherThanOneIsStillOff(): void
    {
        // Upstream tests `=== "1"` and so does this: `PIG_TIMING=0` or `=true` meaning "on"
        // would be a variable that cannot be turned off the obvious way.
        putenv('PIG_TIMING=0');
        Timings::reset();
        Timings::time('one');

        $this->assertSame([], Timings::timings());
    }

    public function testEachTimeRecordsTheGapSinceTheResetOrTheOneBeforeIt(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        Timings::resetTimings();
        usleep(30_000);
        Timings::time('parseArgs');
        Timings::time('settings');

        $timings = Timings::timings();
        $this->assertSame(['parseArgs', 'settings'], array_column($timings, 'label'));
        // Gaps, not moments: the first from the reset, the second from the first.
        $this->assertGreaterThanOrEqual(20, $timings[0]['ms']);
        $this->assertLessThan(20, $timings[1]['ms']);
    }

    public function testANamespaceUsedBeforeAResetStartsItsClockAtItsFirstTime(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        Timings::time('a', 'extensions');

        $this->assertSame([['label' => 'a', 'ms' => 0]], Timings::timings('extensions'));
    }

    public function testNothingMeasuredSaysNothing(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        // `PIG_TIMING=1 bin/pig --version` should not answer with a heading and no rows.
        $this->assertSame('', Timings::table());
    }

    /** Upstream's `printTimingGroup()`, one group per namespace in first-use order. */
    public function testTheTableIsUpstreamsOneGroupPerNamespace(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();

        Timings::resetTimings();
        Timings::time('parseArgs');
        Timings::time('settings');
        Timings::resetTimings('extensions');
        Timings::time('/x/copy.php module import', 'extensions');

        $main = Timings::timings();
        $extensions = Timings::timings('extensions');

        $this->assertSame(
            "\n--- Startup Timings: main ---\n"
            . "  parseArgs: {$main[0]['ms']}ms\n"
            . "  settings: {$main[1]['ms']}ms\n"
            . '  TOTAL: ' . ($main[0]['ms'] + $main[1]['ms']) . "ms\n"
            . "-----------------------------\n\n"
            . "\n--- Startup Timings: extensions ---\n"
            . "  /x/copy.php module import: {$extensions[0]['ms']}ms\n"
            . "  TOTAL: {$extensions[0]['ms']}ms\n"
            . "-----------------------------------\n\n",
            Timings::table(),
        );
    }

    /** Upstream's loader: a `module import` and a `factory` line per extension, reset at each load. */
    public function testEachExtensionIsTimedByTheLoader(): void
    {
        putenv('PIG_TIMING=1');
        Timings::reset();
        $this->root = sys_get_temp_dir() . '/pig-timings-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/home/extensions", 0o777, true);
        mkdir("{$this->root}/project", 0o777, true);
        rmdir("{$this->root}/home/extensions");
        mkdir("{$this->root}/extensions", 0o777, true);
        $file = "{$this->root}/extensions/timed.php";
        file_put_contents($file, "<?php\nreturn static function (\$api): void { usleep(20_000); };\n");

        ExtensionLoader::load("{$this->root}/project", configured: [$file], home: "{$this->root}/home");

        $timings = Timings::timings('extensions');
        $this->assertSame(["{$file} module import", "{$file} factory"], array_column($timings, 'label'));
        $this->assertGreaterThanOrEqual(15, $timings[1]['ms']);

        // A second load is measured on its own.
        ExtensionLoader::load("{$this->root}/project", configured: [$file], home: "{$this->root}/home");
        $this->assertCount(2, Timings::timings('extensions'));
    }
}
