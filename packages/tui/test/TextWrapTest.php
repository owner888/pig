<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\AnsiTracker;
use Pig\Tui\TextWrap;
use Pig\Tui\Width;

final class TextWrapTest extends TestCase
{
    public function testShortTextIsOneLine(): void
    {
        $this->assertSame(['hello'], TextWrap::wrap('hello', 10));
        $this->assertSame([''], TextWrap::wrap('', 10));
    }

    public function testWordsAreKeptWhole(): void
    {
        $this->assertSame(['hello', 'world'], TextWrap::wrap('hello world', 7));
    }

    public function testNoLineStartsWithTheSpaceThatBrokeIt(): void
    {
        foreach (TextWrap::wrap('aaa bbb ccc ddd', 7) as $line) {
            $this->assertStringStartsNotWith(' ', $line);
        }
    }

    public function testLiteralNewlinesAreKept(): void
    {
        $this->assertSame(['one', 'two'], TextWrap::wrap("one\ntwo", 20));
    }

    public function testAWordLongerThanTheLineIsCut(): void
    {
        $lines = TextWrap::wrap(str_repeat('x', 25), 10);

        $this->assertSame(['xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx'], $lines);
    }

    public function testNoLineEverExceedsTheWidth(): void
    {
        $text = 'The quick brown fox 中文字中文字 jumps 👨‍👩‍👧‍👦 over the exceedingly-long-hyphenated-word lazily';

        foreach (TextWrap::wrap($text, 12) as $line) {
            $this->assertLessThanOrEqual(12, Width::visible($line), "too wide: {$line}");
        }
    }

    public function testAWideCharacterIsNeverSplitInHalf(): void
    {
        // Five columns cannot hold three CJK characters; it must break after the second.
        $lines = TextWrap::wrap('中文字', 5);

        $this->assertSame(['中文', '字'], $lines);
    }

    public function testColourSurvivesAWrap(): void
    {
        $lines = TextWrap::wrap("\x1b[31maaaa bbbb cccc", 9);

        $this->assertCount(2, $lines);
        // The second line has to reopen red: the terminal forgot it at the newline.
        $this->assertStringContainsString("\x1b[31m", $lines[1]);
    }

    public function testUnderlineIsClosedAtEachLineEnd(): void
    {
        $lines = TextWrap::wrap("\x1b[4maaaa bbbb cccc", 9);

        // Underline is the one attribute a terminal draws across the padding, so it is
        // turned off before the break and turned back on after it.
        $this->assertStringEndsWith("\x1b[24m", $lines[0]);
        $this->assertStringContainsString("\x1b[4m", $lines[1]);
    }

    public function testColourIsNotClosedAtALineEnd(): void
    {
        $lines = TextWrap::wrap("\x1b[31maaaa bbbb cccc", 9);

        $this->assertStringEndsNotWith("\x1b[24m", $lines[0]);
    }

    /**
     * Inverse paints a blank cell, so it bleeds exactly as underline does.
     *
     * Upstream's comment says only underline bleeds ("Other attributes like colors don't visually
     * bleed to padding"), which is true of a colour and false of SGR 7: inverse swaps foreground
     * and background, so every padding space after the text is filled with the foreground colour.
     * `DiffView` marks a changed run with it, so an edited line long enough to wrap painted the
     * rest of two rows solid.
     */
    public function testInverseIsClosedAtEachLineEndToo(): void
    {
        $lines = TextWrap::wrap("\x1b[7maaaa bbbb cccc", 9);

        $this->assertStringEndsWith("\x1b[27m", $lines[0]);
        $this->assertStringContainsString("\x1b[7m", $lines[1]);
    }

    /** And strikethrough, which a terminal draws through the padding like a rule. */
    public function testStrikethroughIsClosedAtEachLineEndToo(): void
    {
        $lines = TextWrap::wrap("\x1b[9maaaa bbbb cccc", 9);

        $this->assertStringEndsWith("\x1b[29m", $lines[0]);
        $this->assertStringContainsString("\x1b[9m", $lines[1]);
    }

    /** Several at once close in one sequence, and only the ones that are on. */
    public function testOnlyTheAttributesThatPaintABlankCellAreClosed(): void
    {
        // Bold and a colour are invisible on a space; underline and inverse are not.
        $lines = TextWrap::wrap("\x1b[1;4;7;31maaaa bbbb cccc", 9);

        $this->assertStringEndsWith("\x1b[24;27m", $lines[0]);
    }

    public function testStyleCarriesOverALiteralNewline(): void
    {
        $lines = TextWrap::wrap("\x1b[1mbold\nstill bold", 20);

        $this->assertStringContainsString("\x1b[1m", $lines[1]);
    }

    public function testTheTrackerRemembersWhatIsOnAndForgetsWhatWasTurnedOff(): void
    {
        $tracker = new AnsiTracker();
        $tracker->processText("\x1b[1m\x1b[4m\x1b[31mtext\x1b[24m");

        $this->assertSame("\x1b[1;31m", $tracker->activeCodes());
        $this->assertSame('', $tracker->lineEndReset());
    }

    public function testTheTrackerKeepsExtendedColoursWhole(): void
    {
        $tracker = new AnsiTracker();
        $tracker->processText("\x1b[38;5;240m\x1b[48;2;10;20;30m");

        $this->assertSame("\x1b[38;5;240;48;2;10;20;30m", $tracker->activeCodes());
    }

    public function testAResetClearsEverything(): void
    {
        $tracker = new AnsiTracker();
        $tracker->processText("\x1b[1;31m\x1b[0m");

        $this->assertFalse($tracker->hasActiveCodes());
    }

    public function testCjkTextWithEnglishWordWrapsEnglishWordCleanlyToNextLine(): void
    {
        // 12 columns: "请确认提交" (10 cols), "commit-id" (9 cols) wraps cleanly as a whole word to line 2
        $lines = TextWrap::wrap('请确认提交commit-id之后查看', 12);

        $this->assertSame(['请确认提交', 'commit-id之', '后查看'], $lines);
    }
}
