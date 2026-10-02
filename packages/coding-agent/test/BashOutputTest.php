<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\BashOutputComponent;
use Pig\CodingAgent\Tools\Run;
use Pig\Tui\Ansi;
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

    public function testTheDroppedCountIsCountedInRowsWhetherOrNotTheHeadWasWrapped(): void
    {
        // The head is no longer wrapped — only the tail that will be shown is — so the note's
        // count has to come from somewhere else, and it has to be the same number the old whole
        // wrap gave: *rows*, not logical lines. Two 55-column lines at width 22 are six rows.
        $component = new BashOutputComponent(2, static fn (int $dropped): string => "... ({$dropped} earlier lines)");
        $component->setText(str_repeat('a', 55) . "\n" . str_repeat('b', 55) . "\nlast\nline");

        $lines = $component->render(22);

        $this->assertSame('... (6 earlier lines)', trim(Ansi::strip($lines[0])));
        $this->assertStringContainsString('last', $lines[1]);
        $this->assertStringContainsString('line', $lines[2]);
    }

    public function testATailThatWrapsPastTheRowsIsCutAndCountedToo(): void
    {
        // The last logical line alone wraps to three rows against a budget of two, so one row of
        // it goes — and is counted with the dropped head, as the whole wrap counted it.
        $component = new BashOutputComponent(2, static fn (int $dropped): string => "... ({$dropped} earlier lines)");
        $component->setText("first\n" . str_repeat('x', 50));

        $lines = $component->render(22);

        $this->assertSame('... (2 earlier lines)', trim(Ansi::strip($lines[0])));
        $this->assertSame([str_repeat('x', 22), str_repeat('x', 6)], array_map(static fn (string $line): string => trim(Ansi::strip($line)), [$lines[1], $lines[2]]));
    }

    public function testCollapsingALongOutputCostsTheTailAndNotTheWholeOfIt(): void
    {
        // A 50KB build log kept to five rows was wrapped whole at every new width: 280 of them
        // in one resumed session made a resize frame take 560ms, and the right-hand side of a
        // widened window stayed blank for that long. A ratio, for the reason the test above
        // gives — the gap it stands in for is about 50×.
        $short = new BashOutputComponent(5, null);
        $short->setText(str_repeat(str_repeat('x', 200) . "\n", 5));
        $long = new BashOutputComponent(5, null);
        $long->setText(str_repeat(str_repeat('x', 200) . "\n", 2000));

        $tail = self::milliseconds(static fn () => $short->render(120));
        $whole = self::milliseconds(static fn () => $long->render(120));

        $this->assertLessThan(max(1.0, $tail * 20), $whole, 'the whole output was wrapped to show its tail');
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
