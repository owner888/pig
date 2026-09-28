<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Fiber;
use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
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
        $started = microtime(true);

        [$exit, $stdout] = $this->runAsync(['/bin/sh', '-c', 'echo first; sleep 5'], 0.3);

        $this->assertSame(Process::STOPPED, $exit);
        $this->assertSame("first\n", $stdout);
        // And it comes back when it says it does. Waiting for the pipes to close after the
        // kill would have waited for `sleep`, which inherited them and was never killed.
        $this->assertLessThan(2.0, microtime(true) - $started, 'it waited for the grandchild');
    }

    public function testACommandSomethingKilledReportsWhatTheShellWouldHaveSaid(): void
    {
        [$exit] = $this->runAsync(['/bin/sh', '-c', 'kill -9 $$']);

        $this->assertSame(137, $exit);
    }

    /**
     * A command whose output ends before it does still reports the signal.
     *
     * The pipes closing and the process exiting are two events, and the first can arrive
     * first — so the status is asked for while the process is still there, which answers
     * `signaled: false`, and `proc_close()` hands back the bare signal number. Under load
     * that happened to `kill -15 $$` about one run in eight; a command that closes its own
     * standard output first widens the window to the whole of its remaining life, which is
     * the same defect made reproducible.
     */
    public function testACommandWhoseOutputEndsBeforeItDoesStillReportsTheSignal(): void
    {
        [$exit, $stdout] = $this->runAsync(
            ['/bin/sh', '-c', 'echo said this first; exec 1>&- 2>&-; sleep 0.2; kill -15 $$'],
            5.0,
        );

        $this->assertSame(143, $exit);
        $this->assertSame("said this first\n", $stdout);
    }

    public function testTheLoopKeepsTurningWhileACommandOutlivesItsOutput(): void
    {
        $ticks = 0;

        Async::run(static function () use (&$ticks): void {
            Async::spawn(static function () use (&$ticks): void {
                for ($index = 0; $index < 5; $index++) {
                    Async::delay(0.02);
                    $ticks++;
                }
            });

            Process::runAsync(['/bin/sh', '-c', 'exec 1>&- 2>&-; sleep 0.3'], 5.0);
        });

        // Waiting for the process by reaching `proc_close()` blocks the one thread for as
        // long as it takes, which is the freeze `runAsync()` exists to avoid — and here the
        // pipes are shut, so nothing on the loop would have woken it either.
        $this->assertGreaterThan(1, $ticks);
    }

    public function testATimeoutStillReachesACommandThatOutlivedItsOutput(): void
    {
        $started = microtime(true);

        [$exit] = $this->runAsync(['/bin/sh', '-c', 'exec 1>&- 2>&-; sleep 5'], 0.3);

        // The wait for the process has to happen while the timer is still armed, or a command
        // that shut its own output waits out its whole life and is reported as having worked.
        $this->assertSame(Process::STOPPED, $exit);
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function testAnAbortKillsItAndSaysSo(): void
    {
        $controller = new AbortController();
        $started = microtime(true);

        [$exit, $stdout] = Async::run(static function () use ($controller): array {
            Async::spawn(static function () use ($controller): void {
                Async::delay(0.05);
                $controller->abort('escape');
            });

            return Process::runAsync(['/bin/sh', '-c', 'echo first; sleep 5'], 5.0, null, $controller->signal);
        });

        $this->assertSame(Process::STOPPED, $exit);
        $this->assertSame("first\n", $stdout);
        $this->assertLessThan(2.0, microtime(true) - $started, 'it waited out the timeout instead');
    }

    public function testASignalAlreadyAbortedMeansItNeverStarts(): void
    {
        $controller = new AbortController();
        $controller->abort('escape');
        $marker = sys_get_temp_dir() . '/pig-abort-' . bin2hex(random_bytes(4));

        [$exit] = Async::run(static fn (): array => Process::runAsync(
            ['/bin/sh', '-c', 'touch ' . escapeshellarg($marker)],
            2.0,
            null,
            $controller->signal,
        ));

        $this->assertSame(Process::STOPPED, $exit);
        $this->assertFileDoesNotExist($marker);
    }

    public function testTheAbortListenerIsNotLeftOnTheSignal(): void
    {
        $controller = new AbortController();

        Async::run(static function () use ($controller): void {
            Process::runAsync(['/bin/sh', '-c', 'echo quick'], 2.0, null, $controller->signal);
        });

        // A listener left behind would fire into a command that has already been reaped, and
        // the next `proc_terminate()` would be aimed at whatever has that pid now.
        $controller->abort('later');

        $this->assertTrue($controller->signal->aborted());
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

    public function testTheSameKilledCommandIsReportedTheSameWithAFiberAndWithout(): void
    {
        $command = ['/bin/sh', '-c', 'kill -15 $$'];

        [$inside] = $this->runAsync($command);
        // `runAsync()` with no fiber *is* `run()`, and the translation lived in `runAsync()`
        // alone — so the same command through the same call reported 143 during a turn and 15
        // from a hook factory at startup.
        [$outside] = Process::runAsync($command);

        $this->assertSame(143, $inside);
        $this->assertSame($inside, $outside);
    }

    public function testAKilledCommandStreamingItsOutputReportsTheSignalToo(): void
    {
        $lines = [];

        [$exit] = Process::stream(
            ['/bin/sh', '-c', 'echo one; kill -15 $$'],
            static function (string $line) use (&$lines): bool {
                $lines[] = $line;

                return true;
            },
        );

        // `grep` puts this number in front of the model, so it is the same number here.
        $this->assertSame(143, $exit);
        $this->assertSame(['one'], $lines);
    }

    public function testAnEmptyCommandIsRefused(): void
    {
        $this->assertThrows(TuiError::class, static fn () => Process::runAsync([]), 'needs a command');
        $this->assertThrows(TuiError::class, static fn () => Process::streamAsync([], static fn () => true), 'needs a command');
    }

    // ---- streaming on the loop ------------------------------------------------------------

    public function testStreamingOnTheLoopLeavesItFreeToDoAnythingElse(): void
    {
        // The whole point of the method, and the only assertion that can see it: `stream()` and
        // `streamAsync()` take the same wall-clock time and deliver the same lines, so nothing
        // about the output tells them apart. What differs is whether anything *else* can run —
        // measured on this command, 0 ticks against 24, which for a `grep` over a large tree is
        // the keyboard, the spinner and escape all dead until it finishes.
        $ticks = 0;
        $arm = function () use (&$arm, &$ticks): void {
            Loop::get()->delay(0.02, function () use (&$arm, &$ticks): void {
                $ticks++;
                $arm();
            });
        };
        $arm();

        $lines = [];
        Async::run(static function () use (&$lines): void {
            Process::streamAsync(
                ['sh', '-c', 'sleep 0.3; printf "done\n"'],
                static function (string $line) use (&$lines): bool {
                    $lines[] = $line;

                    return true;
                },
                5.0,
            );
        });

        // The command really did take its time, so this cannot pass by returning early.
        $this->assertSame(['done'], $lines);
        $this->assertGreaterThan(5, $ticks, 'the loop was blocked while the command ran');
    }

    public function testStreamingAsyncSplitsLinesStopsEarlyAndKeepsStandardError(): void
    {
        // The same contract as `stream()`, because `GrepTool` was moved from one to the other and
        // a difference in any of these is a difference in what the model is told.
        Async::run(function (): void {
            $seen = [];
            [$exit, $errors] = Process::streamAsync(
                ['sh', '-c', 'printf "a\nb\nc\n"; echo oops >&2'],
                static function (string $line) use (&$seen): bool {
                    $seen[] = $line;

                    return true;
                },
                5.0,
            );

            $this->assertSame(['a', 'b', 'c'], $seen);
            $this->assertSame(0, $exit);
            $this->assertSame('oops', trim($errors), 'standard error is kept, not just drained');

            $enough = [];
            [$stopped] = Process::streamAsync(
                ['sh', '-c', 'printf "1\n2\n3\n"'],
                static function (string $line) use (&$enough): bool {
                    $enough[] = $line;

                    return count($enough) < 2;
                },
                5.0,
            );

            $this->assertSame(['1', '2'], $enough, 'false stops the reading there and then');
            $this->assertSame(Process::STOPPED, $stopped);

            $tail = [];
            Process::streamAsync(
                ['sh', '-c', 'printf "no-newline-at-the-end"'],
                static function (string $line) use (&$tail): bool {
                    $tail[] = $line;

                    return true;
                },
                5.0,
            );

            // A last line with nothing after it is still a line, as `stream()` delivers it.
            $this->assertSame(['no-newline-at-the-end'], $tail);
        });
    }

    public function testWithNoFiberStreamingAsyncIsTheBlockingOne(): void
    {
        // A test that calls a tool directly has no fiber to suspend, so nothing outside a
        // session changes — the same fallback `runAsync()` has, and for the same reason.
        $seen = [];
        [$exit] = Process::streamAsync(
            ['sh', '-c', 'printf "x\n"'],
            static function (string $line) use (&$seen): bool {
                $seen[] = $line;

                return true;
            },
            5.0,
        );

        $this->assertSame(['x'], $seen);
        $this->assertSame(0, $exit);
    }
}
