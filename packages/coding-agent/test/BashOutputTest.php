<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\BashOutputComponent;
use Pig\CodingAgent\Tools\Run;
use Pig\Tui\Width;

/**
 * The tail of a typed command's output, on screen.
 *
 * The one transcript component that does **not** clean what it is given — `ToolExecutionComponent`,
 * `DiffView` and `HookMessageComponent` all go through `Shell::sanitize()` — and upstream's
 * `bash-execution.ts` does not either. That is only safe because the command runner cleans at the
 * source, which is what these cases are here to say.
 */
final class BashOutputTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    private function outputOf(string $command): string
    {
        return Async::run(static function () use ($command): string {
            $run = new Run(sys_get_temp_dir(), $command, null);
            $run->start();
            $run->wait(null, 5.0);

            return $run->output();
        });
    }

    public function testABinaryCommandsOutputIsDrawnRatherThanFatal(): void
    {
        $output = $this->outputOf('head -c 400 /dev/urandom');

        // `!head -c 400 /dev/urandom`, or a `!cat` of anything that is not text, used to end the
        // session: `Graphemes::split()` answers false on malformed UTF-8, and this component is
        // the one display path with no guard of its own. The fourth way into that killer, and the
        // only one that was never written down.
        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), 'the runner should have cleaned it');

        $component = new BashOutputComponent(5, null);
        $component->setText($output);

        $lines = $component->render(80);

        foreach ($lines as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
            $this->assertLessThanOrEqual(80, Width::visible($line));
        }
    }

    public function testAProgressBarIsOneLineRatherThanAHundred(): void
    {
        // `\r` redraws the line it is on, so a download or a build step writes the same row over
        // and over. Kept, it is a hundred rows of transcript; the runner takes it out.
        $output = $this->outputOf("printf 'step 1\\rstep 2\\rstep 3\\n'");

        $this->assertSame("step 1step 2step 3\n", $output);
    }

    public function testTheTailIsWhatIsKept(): void
    {
        $component = new BashOutputComponent(3, static fn (int $dropped): string => "... ({$dropped} earlier lines)");
        $component->setText("one\ntwo\nthree\nfour\nfive\n");

        $lines = $component->render(80);
        $joined = implode("\n", $lines);

        // A build says what happened at the end, so the end is what fits.
        $this->assertStringContainsString('five', $joined);
        $this->assertStringNotContainsString('one', $joined);
        $this->assertStringContainsString('earlier lines', $joined);
    }

    // ---- what a finished command costs to keep on screen ------------------------------

    public function testAFinishedCommandIsNotWrappedAgainOnEveryFrame(): void
    {
        // The wrap is over the *whole* output and keeps a handful of rows, so it costs what the
        // command printed rather than what is on screen. A resumed transcript is nothing but
        // finished commands, and one keystroke re-wrapped every one of them — measured on a real
        // session with 201 tool results: **1.5 seconds a keystroke**, three times over, because
        // `Container::rowOf()` renders the tree twice more to find the caret.
        $component = new BashOutputComponent(20, null);
        $component->setText(str_repeat(str_repeat('x', 400) . "\n", 2000));

        $first = self::milliseconds(static fn () => $component->render(120));
        $again = self::milliseconds(static fn () => $component->render(120));

        // A ratio rather than a number, because what a machine under load can do in a
        // millisecond is not a fact about this code. The gap it is standing in for is 800×.
        $this->assertLessThan($first / 10, $again, 'the output was wrapped a second time');
    }

    public function testTheSameOutputAtADifferentWidthIsWrappedAgain(): void
    {
        // The width is what decides how many rows a long line takes, so it is part of the key —
        // a cache that forgot it would keep drawing the last terminal's layout after a resize.
        $component = new BashOutputComponent(3, null);
        $component->setText(str_repeat('x', 30));

        $this->assertCount(1, $component->render(40));
        $this->assertCount(3, $component->render(10));
    }

    public function testNewOutputAndANewRowCountBothReachTheScreen(): void
    {
        $component = new BashOutputComponent(2, null);
        $component->setText("one\ntwo\nthree");

        $this->assertStringNotContainsString('one', implode("\n", $component->render(80)));

        $component->setRows(3);

        $this->assertStringContainsString('one', implode("\n", $component->render(80)));

        $component->setText("four\nfive\nsix");

        $this->assertStringContainsString('six', implode("\n", $component->render(80)));
        $this->assertStringNotContainsString('one', implode("\n", $component->render(80)));
    }

    private static function milliseconds(callable $call): float
    {
        $start = microtime(true);
        $call();

        return (microtime(true) - $start) * 1000;
    }
}
