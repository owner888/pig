<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Fiber;
use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Tui\Process;
use Pig\Test\AssertsThrows;
use Pig\Tui\TuiError;

/**
 * Running a command without stopping everything else.
 *
 * `run()` polls with `usleep()`, which is right for a two-second clipboard probe and wrong
 * for anything a person waits through. `runAsync()` is the same thing with the pipes on the
 * loop, and the property that matters is the one nothing else here can check: that the rest
 * of the program keeps running while the command does.
 */
final class ProcessTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runAsync(array $command, float $timeout = 2.0, ?string $cwd = null): array
    {
        return Async::run(static fn (): array => Process::runAsync($command, $timeout, $cwd));
    }

    public function testStandardOutputAndStandardErrorComeBackApart(): void
    {
        [$exit, $stdout, $stderr] = $this->runAsync(['/bin/sh', '-c', 'echo out; echo err >&2']);

        $this->assertSame(0, $exit);
        $this->assertSame("out\n", $stdout);
        $this->assertSame("err\n", $stderr);
    }

    public function testAFailingCommandKeepsItsExitCode(): void
    {
        [$exit, , $stderr] = $this->runAsync(['/bin/sh', '-c', 'echo why >&2; exit 3']);

        $this->assertSame(3, $exit);
        $this->assertSame("why\n", $stderr);
    }

    public function testTheRestOfTheProgramKeepsRunningWhileTheCommandDoes(): void
    {
        $ticks = 0;

        Async::run(static function () use (&$ticks): void {
            Async::spawn(static function () use (&$ticks): void {
                for ($index = 0; $index < 5; $index++) {
                    Async::delay(0.02);
                    $ticks++;
                }
            });

            Process::runAsync(['/bin/sh', '-c', 'sleep 0.3']);
        });

        // This is the whole point: with `run()` here the count is 0, because the one thread
        // is inside a `usleep()` — no keystrokes, no spinner, no escape.
        $this->assertGreaterThan(1, $ticks);
    }

    public function testMoreOutputThanOneReadStillArrivesWhole(): void
    {
        [$exit, $stdout] = $this->runAsync(['/bin/sh', '-c', 'yes abcdefgh | head -40000']);

        $this->assertSame(0, $exit);
        $this->assertSame(40000, substr_count($stdout, "\n"));
    }

    public function testACommandThatRunsTooLongIsStoppedAndKeepsWhatItSaid(): void
    {
        [$exit, $stdout] = $this->runAsync(['/bin/sh', '-c', 'echo first; sleep 5'], 0.3);

        $this->assertSame(Process::STOPPED, $exit);
        $this->assertSame("first\n", $stdout);
    }

    public function testACommandSomethingKilledReportsWhatTheShellWouldHaveSaid(): void
    {
        [$exit] = $this->runAsync(['/bin/sh', '-c', 'kill -9 $$']);

        $this->assertSame(137, $exit);
    }

    public function testAProgramThatIsNotOnThisMachineIsStopped(): void
    {
        [$exit, $stdout, $stderr] = $this->runAsync(['pig-no-such-program-anywhere']);

        $this->assertSame(Process::STOPPED, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    public function testItRunsWhereItWasToldTo(): void
    {
        $directory = sys_get_temp_dir();

        [, $stdout] = $this->runAsync(['/bin/sh', '-c', 'pwd'], 2.0, $directory);

        $this->assertStringContainsString(basename($directory), trim($stdout));
    }

    public function testTheLoopHasNothingLeftToWaitForAfterwards(): void
    {
        $started = microtime(true);

        Async::run(static function (): void {
            Process::runAsync(['/bin/sh', '-c', 'echo done'], 5.0);
        });

        // A watcher or a timer left behind keeps `Loop::isIdle()` false for the rest of those
        // five seconds, and `bin/pig` waits for the loop before it exits — so a leak here is a
        // program that does not quit. One tick to drain the completion callback, which goes
        // through `Loop::defer()` by design and is not a leak.
        Loop::get()->tick();

        $this->assertTrue(Loop::get()->isIdle());
        $this->assertLessThan(2.0, microtime(true) - $started, 'it waited out the timeout');
    }

    public function testWithNoFiberToSuspendItStillRuns(): void
    {
        // A hook factory runs at startup, long before `Async::run()`, and a command there has
        // nothing to be polite to.
        $this->assertNull(Fiber::getCurrent());

        [$exit, $stdout] = Process::runAsync(['/bin/sh', '-c', 'echo outside']);

        $this->assertSame(0, $exit);
        $this->assertSame("outside\n", $stdout);
    }

    public function testAnEmptyCommandIsRefused(): void
    {
        $this->assertThrows(TuiError::class, static fn () => Process::runAsync([]), 'needs a command');
    }
}
