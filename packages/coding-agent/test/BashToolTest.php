<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\BashTool;
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
        $this->assertSame("hello\n", $this->output($this->bash(['command' => 'echo hello'])));
    }

    public function testRunsInTheWorkingDirectory(): void
    {
        $this->file('marker.txt');

        $this->assertStringContainsString('marker.txt', $this->output($this->bash(['command' => 'ls'])));
    }

    public function testStderrComesBackWithStdout(): void
    {
        $output = $this->output($this->bash(['command' => 'echo out; echo err >&2']));

        // The model wants what the command said, not which pipe it said it on.
        $this->assertStringContainsString('out', $output);
        $this->assertStringContainsString('err', $output);
    }

    public function testACommandWithNoOutputSaysSo(): void
    {
        $this->assertSame('(no output)', $this->output($this->bash(['command' => 'true'])));
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

    public function testStdinIsNotTheTerminal(): void
    {
        // A command that decides to prompt would otherwise wait for a person who is not
        // watching it, and the agent would hang until the timeout.
        $this->assertSame('(no output)', $this->output($this->bash(['command' => 'cat'])));
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
        $this->assertSame("ok\n", $this->output($this->bash(['command' => 'echo ok', 'timeout' => 10])));
    }

    public function testOutputIsCutFromTheTopNotTheBottom(): void
    {
        $total = Truncate::MAX_LINES + 100;
        $output = $this->output($this->bash(['command' => "seq 1 {$total}"]));

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
}
