<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\Run;
use Pig\CodingAgent\Tools\Shell;
use Pig\CodingAgent\Tools\Truncate;
use Pig\Test\AssertsThrows;

final class BashToolTest extends ToolTestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Loop::reset();
    }

    /**
     * Run a command. Inside a coroutine, because the tool suspends on the loop rather
     * than blocking — which is the whole point of it.
     *
     * @param array<string, mixed> $arguments
     */
    private function bash(array $arguments, mixed $signal = null, ?\Closure $onUpdate = null): AgentToolResult
    {
        $tool = new BashTool($this->cwd);

        return Async::run(static fn (): AgentToolResult => $tool->execute('call-1', $arguments, $signal, $onUpdate));
    }

    public function testRunsACommandAndReturnsItsOutput(): void
    {
        $this->assertSame("hello\n", $this->textOf($this->bash(['command' => 'echo hello'])));
    }

    public function testTheCommandPrefixRunsInFrontOfTheCommand(): void
    {
        $tool = new BashTool($this->cwd, 'GREETING=hello');

        $result = Async::run(static fn (): AgentToolResult => $tool->execute('call-1', ['command' => 'echo $GREETING']));

        $this->assertSame("hello\n", $this->textOf($result));
    }

    public function testRunsInTheWorkingDirectory(): void
    {
        $this->file('marker.txt');

        $this->assertStringContainsString('marker.txt', $this->textOf($this->bash(['command' => 'ls'])));
    }

    public function testStderrComesBackWithStdout(): void
    {
        $output = $this->textOf($this->bash(['command' => 'echo out; echo err >&2']));

        // The model wants what the command said, not which pipe it said it on.
        $this->assertStringContainsString('out', $output);
        $this->assertStringContainsString('err', $output);
    }

    public function testWhatTheModelReadsHasNoEscapesOrProgressLinesInIt(): void
    {
        $output = $this->textOf($this->bash(['command' => "printf 'a\\033[32mb\\033[0m\\rc\\n'"]));

        // A deliberate step past upstream, which cleans a command's output at the source in
        // `bash-executor.ts` — the `!command` path — and not in `bash.ts`, where only the
        // component cleans it. So there the model reads `\e[32m` and a `\r` that redraws the
        // line it is on, and the screen reads neither. Two answers to one question; this is
        // the one with the reason written beside it.
        $this->assertSame("abc\n", $output);
    }

    public function testTheFullOutputFileIsCleanToo(): void
    {
        $total = Truncate::MAX_LINES + 100;

        $result = $this->bash(['command' => "for i in \$(seq 1 {$total}); do printf '\\033[32m%s\\033[0m\\n' \"\$i\"; done"]);
        $path = $result->details['fullOutputPath'];

        // It is named in the notice so the model can go and read the part that was cut, so it is
        // the same text as the part that was not. Upstream writes the cleaned chunks here too.
        $this->assertStringNotContainsString("\033", (string) file_get_contents($path));
        $this->assertSame($total, substr_count((string) file_get_contents($path), "\n"));

        unlink($path);
    }

    public function testACommandWithNoOutputSaysSo(): void
    {
        $this->assertSame('(no output)', $this->textOf($this->bash(['command' => 'true'])));
    }

    public function testAFailingCommandThrowsWithItsOutputAttached(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => 'echo what went wrong >&2; exit 3']),
            'exited with code 3',
        );

        // The output goes with the error: it is what the model needs to react to.
        $this->assertStringContainsString('what went wrong', $error->getMessage());
    }

    /** @return iterable<string, array{0: int, 1: int}> */
    public static function signals(): iterable
    {
        yield 'SIGKILL, which is what an out-of-memory kill looks like' => [9, 137];
        yield 'SIGSEGV' => [11, 139];
        yield 'SIGTERM' => [15, 143];
    }

    /**
     * A command the kernel killed reports what `$?` would have said.
     *
     * `proc_close()` hands back the *signal number* — 9 for a SIGKILL — where every shell on
     * earth reports 128 + the signal, and 137 is the number a model has seen a thousand times
     * and reads as "something killed it, probably the OOM killer". Told "code 9" it goes
     * looking for an exit code the program chose.
     */
    #[DataProvider('signals')]
    public function testACommandKilledBySignalReportsWhatTheShellWouldHaveSaid(int $signal, int $expected): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => "echo got this far; kill -{$signal} \$\$"]),
            "exited with code {$expected}",
        );

        $this->assertStringContainsString('got this far', $error->getMessage());
    }

    /**
     * The same, for a command whose output ends before it does.
     *
     * A command finishes twice over: its pipes close, and then the process exits. `Run`
     * waits for the first, so the status was asked for while the process was still there —
     * which answers `signaled: false`, and `proc_close()` then hands back the bare signal
     * number. Measured at about one run in eight on a loaded machine with the three rows
     * above; closing standard output first makes it every run.
     */
    public function testACommandWhoseOutputEndsBeforeItDoesStillReportsTheSignal(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => 'echo said this first; exec 1>&- 2>&-; sleep 0.2; kill -15 $$']),
            'exited with code 143',
        );

        $this->assertStringContainsString('said this first', $error->getMessage());
    }

    public function testTheLoopKeepsRunningWhileACommandOutlivesItsOutput(): void
    {
        $ticks = 0;
        $tool = new BashTool($this->cwd);

        Async::run(static function () use ($tool, &$ticks): void {
            Async::spawn(static function () use (&$ticks): void {
                for ($index = 0; $index < 5; $index++) {
                    Async::delay(0.02);
                    $ticks++;
                }
            });

            $tool->execute('call-1', ['command' => 'exec 1>&- 2>&-; sleep 0.3']);
        });

        // Reaching `proc_close()` to wait for it blocks the one thread for as long as the
        // command has left, which is the freeze this tool is built not to have — and with the
        // pipes already shut, nothing on the loop would have woken it either.
        $this->assertGreaterThan(1, $ticks);
    }

    public function testATimeoutStillReachesACommandThatOutlivedItsOutput(): void
    {
        $started = microtime(true);

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => 'exec 1>&- 2>&-; sleep 30', 'timeout' => 0.3]),
            'timed out',
        );

        // Waiting for the process has to happen while the timer is still armed, or a command
        // that shut its own output waits out its whole life and is then reported as having
        // worked — with escape unable to reach it either.
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertStringContainsString('0.3 seconds', $error->getMessage());
    }

    public function testEscapeStillReachesACommandThatOutlivedItsOutput(): void
    {
        $controller = new AbortController();
        $tool = new BashTool($this->cwd);
        $started = microtime(true);

        $this->assertThrows(
            AgentError::class,
            static function () use ($tool, $controller): void {
                Async::run(static function () use ($tool, $controller): void {
                    Async::spawn(static function () use ($controller): void {
                        Async::delay(0.15);
                        $controller->abort('Escape');
                    });

                    $tool->execute('call-1', ['command' => 'exec 1>&- 2>&-; sleep 30'], $controller->signal);
                });
            },
            'aborted',
        );

        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testAConfiguredShellIsTheOneThatRuns(): void
    {
        $marker = $this->cwd . '/which-shell';
        file_put_contents($marker, "#!/bin/sh\necho \"ran by a stand-in\"\n");
        chmod($marker, 0o755);

        Shell::useShellPath($marker);

        try {
            // macOS ships bash 3.2 as `/bin/bash`, and `bash()` prefers it — so somebody with a
            // homebrew bash 5 had no way to be given it. pig stored this setting and read it
            // nowhere.
            $this->assertStringContainsString('ran by a stand-in', $this->textOf($this->bash(['command' => 'anything'])));
        } finally {
            Shell::forget();
        }
    }

    public function testAConfiguredShellThatIsNotThereIsRefusedByName(): void
    {
        Shell::useShellPath($this->cwd . '/no-such-shell');

        try {
            $error = $this->assertThrows(
                AgentError::class,
                fn () => $this->bash(['command' => 'echo hi']),
                'not executable',
            );

            // Falling back to `/bin/bash` would be the bug they wrote the setting to work
            // around, back again with nothing on screen about it.
            $this->assertStringContainsString('settings.json', $error->getMessage());
        } finally {
            Shell::forget();
        }
    }

    public function testStdinIsNotTheTerminal(): void
    {
        // A command that decides to prompt would otherwise wait for a person who is not
        // watching it, and the agent would hang until the timeout.
        $this->assertSame('(no output)', $this->textOf($this->bash(['command' => 'cat'])));
    }

    public function testOutputArrivesWhileTheCommandIsStillRunning(): void
    {
        $seen = [];

        $this->bash(
            ['command' => 'echo one; sleep 0.2; echo two'],
            null,
            static function (AgentToolResult $partial) use (&$seen): void {
                $seen[] = $partial->content[0] instanceof TextContent ? $partial->content[0]->text : '';
            },
        );

        $this->assertNotSame([], $seen);
        // The first update landed before the command had finished.
        $this->assertStringContainsString('one', $seen[0]);
        $this->assertStringNotContainsString('two', $seen[0]);
    }

    public function testTheLoopKeepsRunningWhileACommandDoes(): void
    {
        $ticks = 0;
        $tool = new BashTool($this->cwd);

        Async::run(static function () use ($tool, &$ticks): void {
            Async::spawn(static function () use (&$ticks): void {
                for ($index = 0; $index < 5; $index++) {
                    Async::delay(0.02);
                    $ticks++;
                }
            });

            $tool->execute('call-1', ['command' => 'sleep 0.3']);
        });

        // A blocking read here would freeze the UI: no drawing, no keyboard, no Escape.
        $this->assertGreaterThan(1, $ticks);
    }

    public function testEscapeStopsACommandAndSaysSo(): void
    {
        $controller = new AbortController();
        $tool = new BashTool($this->cwd);
        $started = microtime(true);

        $error = $this->assertThrows(
            AgentError::class,
            static function () use ($tool, $controller): void {
                Async::run(static function () use ($tool, $controller): void {
                    Async::spawn(static function () use ($controller): void {
                        Async::delay(0.15);
                        $controller->abort('Escape');
                    });

                    $tool->execute('call-1', ['command' => 'sleep 30'], $controller->signal);
                });
            },
            'Command aborted',
        );

        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertStringContainsString('aborted', $error->getMessage());
    }

    public function testAbortingKillsWhatTheCommandStartedToo(): void
    {
        $marker = $this->cwd . '/still-running.txt';
        $controller = new AbortController();
        $tool = new BashTool($this->cwd);

        // The shell is the child and the subshell is the grandchild; killing only the
        // shell would leave the grandchild writing to a terminal that has moved on.
        $command = "(sleep 0.6; echo alive > " . escapeshellarg($marker) . ") & wait";

        $this->assertThrows(
            AgentError::class,
            static function () use ($tool, $controller, $command): void {
                Async::run(static function () use ($tool, $controller, $command): void {
                    Async::spawn(static function () use ($controller): void {
                        Async::delay(0.15);
                        $controller->abort('Escape');
                    });

                    $tool->execute('call-1', ['command' => $command], $controller->signal);
                });
            },
            'aborted',
        );

        usleep(900_000);

        $this->assertFileDoesNotExist($marker);
    }

    public function testATimeoutStopsACommandAndSaysHowLongItWaited(): void
    {
        $started = microtime(true);

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => 'sleep 30', 'timeout' => 0.3]),
            'timed out',
        );

        $this->assertStringContainsString('0.3 seconds', $error->getMessage());
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testACommandThatFinishesInTimeIsNotAffectedByTheTimeout(): void
    {
        $this->assertSame("ok\n", $this->textOf($this->bash(['command' => 'echo ok', 'timeout' => 10])));
    }

    public function testOutputIsCutFromTheTopNotTheBottom(): void
    {
        $total = Truncate::MAX_LINES + 100;
        $output = $this->textOf($this->bash(['command' => "seq 1 {$total}"]));

        // The interesting part of a failed build is the error at the bottom.
        $this->assertStringContainsString((string) $total, $output);
        $this->assertStringNotContainsString("\n1\n", $output);
    }

    public function testTruncatedOutputSaysWhereTheWholeOfItIs(): void
    {
        $total = Truncate::MAX_LINES + 100;

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => "seq 1 {$total}; exit 1"]),
            'Showing lines',
        );

        // Two thousand short lines is nowhere near 50KB and still gets truncated, so
        // the file has to be written on the line count too — upstream checks only bytes
        // and leaves this case with nowhere to look.
        $this->assertStringContainsString('Full output: ', $error->getMessage());

        preg_match('#Full output: (\S+\.log)#', $error->getMessage(), $match);
        $this->assertFileExists($match[1]);
        $this->assertSame($total, substr_count((string) file_get_contents($match[1]), "\n"));
        // Readable by the user alone: the temp directory is shared, and the file holds
        // whatever the command printed.
        $this->assertSame(0600, fileperms($match[1]) & 0777);
        unlink($match[1]);
    }

    public function testASmallOutputIsNotWrittenToAFile(): void
    {
        $result = $this->bash(['command' => 'echo small']);

        $this->assertNull($result->details);
    }

    public function testTheTruncationDetailsGoToTheUi(): void
    {
        $total = Truncate::MAX_LINES + 100;
        $result = $this->bash(['command' => "seq 1 {$total}"]);

        $this->assertTrue($result->details['truncation']->truncated);
        $this->assertSame('lines', $result->details['truncation']->truncatedBy);
        $this->assertFileExists($result->details['fullOutputPath']);

        // The same three keys every tool that cuts its own output carries. This one is not
        // read by the transcript — a command's preview keeps the tail, where the notice
        // already is — but a shape that holds for four tools and not the fifth is a puzzle.
        $this->assertStringStartsWith('Showing lines 102-2101 of 2101', $result->details['notice']);
        unlink($result->details['fullOutputPath']);
    }

    public function testAnAlreadyAbortedSignalStopsBeforeAnythingRuns(): void
    {
        $controller = new AbortController();
        $controller->abort('Escape');
        $this->file('marker.txt', 'untouched');

        $this->assertThrows(
            \Throwable::class,
            fn () => $this->bash(['command' => 'echo changed > marker.txt'], $controller->signal),
        );

        $this->assertSame('untouched', file_get_contents($this->cwd . '/marker.txt'));
    }

    public function testATimeoutOfNothingMeansNoTimeoutRatherThanNoTime(): void
    {
        // The schema says `number` and the description says there is none by default, so a model
        // spelling "no limit" as 0 is an ordinary thing to receive. Armed at zero the timer fires
        // on the next tick, and every command comes back `timed out after 0 seconds`.
        $this->assertSame("ok\n", $this->textOf($this->bash(['command' => 'sleep 0.1; echo ok', 'timeout' => 0])));

        // The other side of the same guard: something positive really does stop it.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->bash(['command' => 'sleep 30', 'timeout' => 0.2]),
            'timed out',
        );
    }

    public function testACommandThatBeatItsTimeoutLeavesTheLoopNothingToWaitFor(): void
    {
        $started = microtime(true);

        $this->assertSame("ok\n", $this->textOf($this->bash(['command' => 'echo ok', 'timeout' => 30])));

        // A timeout that was never needed is a timer still armed for half a minute, and a
        // pending timer keeps `isIdle()` false — which `bin/pig` waits on before it exits. So
        // the leak is not a slow command, it is a session that finishes and then will not quit.
        // Turned rather than asked once: a callback still queued is work, and the question is
        // whether the work *ends* — `BorderedLoader`'s own test learnt that the hard way.
        for ($tick = 0; $tick < 20 && !Loop::get()->isIdle(); $tick++) {
            // An expired timer of the test's own, or the poll below waits out whatever is armed.
            Loop::get()->delay(0.0, static fn () => null);
            Loop::get()->tick();
        }

        $this->assertTrue(Loop::get()->isIdle(), 'nothing is still armed');
        $this->assertLessThan(5.0, microtime(true) - $started, 'and it did not get there by waiting');
    }

    public function testACommandThatIsWideRatherThanLongIsStillWrittenOutInFull(): void
    {
        // The mirror of the two-thousand-short-lines case: one enormous line is nowhere near the
        // line limit and well past the byte one, so the count of bytes seen is what has to send
        // it to a file. Without it the notice names a path for output only the line count spills.
        $length = Truncate::MAX_BYTES * 4;
        $result = $this->bash(['command' => "head -c {$length} /dev/zero | tr '\\0' 'a'"]);

        $path = $result->details['fullOutputPath'] ?? null;

        $this->assertNotNull($path, 'a byte-heavy output has somewhere to look');
        $this->assertFileExists($path);
        // The whole of it, in one file: everything seen before the threshold goes in front, and
        // every chunk after it is appended to the same handle rather than starting a new one.
        $this->assertSame($length, strlen((string) file_get_contents($path)));
        unlink($path);
    }

    public function testOutputThatExactlyFillsTheBudgetIsNotWrittenOutAndOneByteMoreIs(): void
    {
        // Asked of `Run` rather than of the tool, because the tool cannot see this: `details` is
        // only built when something was cut, so at exactly the budget a spill file written for
        // nothing is a temp file nobody is ever told about — and asserting the bound only from
        // the side where it is out of range is not a test of the bound.
        $this->assertNull($this->spillOf(Truncate::MAX_BYTES), 'nothing will be cut, so there is nothing to spill');

        $path = $this->spillOf(Truncate::MAX_BYTES + 1);

        $this->assertNotNull($path);
        unlink((string) $path);
    }

    public function testTheLineCountHasTheSameTwoSidesAsTheByteCount(): void
    {
        $lines = Truncate::MAX_LINES;

        $this->assertNull($this->spill("seq 1 {$lines}"), 'exactly the line budget is not truncated');

        $path = $this->spill('seq 1 ' . ($lines + 1));

        $this->assertNotNull($path);
        unlink((string) $path);
    }

    /**
     * A background grandchild (`(sleep 5) &`) inherits the pipes unless explicitly closed.
     * When the main shell finishes, `feof($pipe)` stays false for as long as the background
     * child runs. Without polling the parent process's status, `Run::wait()` hangs indefinitely
     * waiting for pipe EOF.
     */
    public function testACommandThatSpawnsABackgroundGrandchildDoesNotHangWaitingForPipes(): void
    {
        $start = microtime(true);
        $output = $this->textOf($this->bash(['command' => 'sh -c "(sleep 5) & echo parent_done"']));
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('parent_done', $output);
        $this->assertLessThan(2.0, $elapsed, 'command finishes when the parent shell exits, not after 5 seconds');
    }

    /** Where `Run` put the whole output of a command that printed $bytes bytes, if anywhere. */
    private function spillOf(int $bytes): ?string
    {
        return $this->spill("head -c {$bytes} /dev/zero | tr '\\0' 'a'");
    }

    private function spill(string $command): ?string
    {
        $run = new Run($this->cwd, $command, null);

        Async::run(static function () use ($run): void {
            $run->start();
            $run->wait(null, null);
        });

        return $run->spillPath;
    }
}
