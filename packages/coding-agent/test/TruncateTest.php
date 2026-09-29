<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Tools\Truncate;

/**
 * What the model is shown of a file, a command's output and a search result.
 *
 * **This class had no test of its own**, which is how it came to be the densest arithmetic in the
 * tree with every one of its bounds checkable only through a tool. A mutation sweep put 78
 * mutations through it and 40 survived — the highest rate of the eight files swept, and nearly all
 * of them one shape: *a bound tested only from outside itself*. `<=` became `<` and `>` became `>=`
 * across `head()`, `tail()`, `line()`, `size()` and the byte accounting, and the whole suite stayed
 * green.
 *
 * Every bound turned out to be **right**. That is not an argument for leaving it alone, it is the
 * argument for this file: the correctness came from a run against upstream over 777 documents, the
 * run was not kept, and what a corpus proves it proves on the day it ran. The same three times over
 * now — `PartialJson`, `JsonSchema`, and here.
 *
 * So the rule these cases are written to, which is `JsonSchemaTest`'s: **asserting that a bound
 * rejects what is out of range is not a test of the bound.** Each case here pins the value that
 * exactly reaches the limit *and* the first one past it, because only the pair says where the edge
 * is.
 */
final class TruncateTest extends TestCase
{
    // ---- head(): keep the beginning -------------------------------------------------------

    public function testContentThatExactlyReachesTheLineLimitIsNotTruncated(): void
    {
        $five = implode("\n", array_fill(0, 5, 'x'));

        $this->assertFalse(Truncate::head($five, 5, 1000)->truncated, 'five lines against a limit of five');
        $this->assertTrue(Truncate::head($five . "\nx", 5, 1000)->truncated, 'and six is one too many');
    }

    public function testContentThatExactlyReachesTheByteLimitIsNotTruncated(): void
    {
        $hundred = str_repeat('a', 100);

        $this->assertFalse(Truncate::head($hundred, 50, 100)->truncated);
        $this->assertTrue(Truncate::head($hundred . 'a', 50, 100)->truncated);
    }

    public function testAFirstLineThatExactlyFillsTheLimitFitsRatherThanBeingTooBig(): void
    {
        // The `nothing whole fits` branch, which answers with empty content and a different
        // message. A line of exactly the limit is not that case — it fits, precisely.
        $exact = Truncate::head(str_repeat('a', 100) . "\nsecond", 50, 100);

        $this->assertFalse($exact->firstLineExceedsLimit);
        $this->assertSame(100, strlen($exact->content));

        $over = Truncate::head(str_repeat('a', 101) . "\nsecond", 50, 100);

        $this->assertTrue($over->firstLineExceedsLimit);
        $this->assertSame('', $over->content);
    }

    public function testTheFirstLineIsNotChargedForANewlineItDoesNotHave(): void
    {
        // `$cost = strlen($line) + ($index > 0 ? 1 : 0)` — charging index 0 for a newline too
        // makes everything one byte too expensive, so content that exactly fits is cut instead.
        $this->assertFalse(Truncate::head("abc\ndef", 50, 7)->truncated, 'three + newline + three = seven');
        $this->assertSame('abc', Truncate::head("abc\ndefg", 50, 7)->content, 'and eight does not fit');
    }

    // ---- tail(): keep the end -------------------------------------------------------------

    public function testTailLeavesContentAloneWhenItExactlyReachesBothLimits(): void
    {
        $five = implode("\n", array_fill(0, 5, 'x'));

        $this->assertFalse(Truncate::tail($five, 5, 1000)->truncated);
        $this->assertFalse(Truncate::tail($five, 50, strlen($five))->truncated);
    }

    public function testTailKeepsTheEndAndDropsTheStart(): void
    {
        $this->assertSame("bb\ncc\ndd", Truncate::tail("aa\nbb\ncc\ndd", 3, 100)->content);
    }

    public function testTheLastLineKeptIsNotChargedForANewlineEither(): void
    {
        // `tail()` builds backwards, so the *first* line it keeps is the one with no newline to
        // pay for — the mirror of `head()`'s rule, and the one that decides whether a line that
        // exactly fits is kept or dropped.
        $kept = Truncate::tail("xx\nbb", 5, 2);

        $this->assertSame('bb', $kept->content);
        // Charging it the newline makes the line not fit, and `tail()` answers the same text by a
        // different route — the half-a-line one — so the content alone cannot tell the two apart.
        $this->assertFalse($kept->lastLinePartial, 'kept whole, not cut down to size');
        $this->assertSame('bbb', Truncate::tail("xx\nbbb", 5, 3)->content);
    }

    public function testNothingIsSaidAboutALimitWhenNothingWasTruncated(): void
    {
        // `truncatedBy` is read by the tool that builds the notice, so "not truncated" has to be
        // distinguishable from "truncated by something I forgot to name".
        $this->assertNull(Truncate::head('short', 50, 100)->truncatedBy);
        $this->assertNull(Truncate::tail('short', 50, 100)->truncatedBy);
    }

    public function testTailSaysWhichLimitStoppedIt(): void
    {
        $this->assertSame('lines', Truncate::tail("aa\nbb\ncc\ndd", 3, 100)->truncatedBy);
        $this->assertSame('bytes', Truncate::tail("xx\naaa\nbb", 50, 6)->truncatedBy);
    }

    public function testHeadNeverCutsALineInHalf(): void
    {
        // Only `tail()` does, and only for the last line. A `head()` that reported a partial line
        // would have the read tool telling the model its first line is incomplete when it is not.
        $this->assertFalse(Truncate::head(str_repeat("ab\n", 50), 3, 1000)->lastLinePartial);
        $this->assertFalse(Truncate::head("abcdefghij\nxx", 50, 5)->lastLinePartial);
    }

    public function testALineThatExactlyUsesUpTheRemainingBytesIsKept(): void
    {
        // Three lines, six bytes allowed: `bb` costs 2, then `aaa` costs 2 + 1 + 3 = 6, which is
        // the limit rather than past it. Stopping here would hand back only the last line.
        $this->assertSame("aaa\nbb", Truncate::tail("xx\naaa\nbb", 5, 6)->content);
    }

    public function testTheLastLineIsTheOnlyOneEverCutInHalf(): void
    {
        $cut = Truncate::tail("head\n" . str_repeat('Z', 20), 50, 10);

        $this->assertTrue($cut->lastLinePartial);
        $this->assertSame(str_repeat('Z', 10), $cut->content, 'the end of it, which is the part that matters');
    }

    public function testACutEndOfLineStartsOnACharacterAndNotInsideOne(): void
    {
        // Cutting mid-character produces invalid UTF-8, which the JSON encoder on the way to the
        // model refuses outright — so the walk forward over continuation bytes is load-bearing.
        $cut = Truncate::tail("x\n" . str_repeat('中', 10), 50, 10);

        $this->assertTrue(mb_check_encoding($cut->content, 'UTF-8'));
        $this->assertSame('中中中', $cut->content, 'nine bytes, because a tenth would be a third of a character');
    }

    // ---- which limit did it ---------------------------------------------------------------

    public function testStoppingOnTheLineLimitIsReportedAsTheLineLimit(): void
    {
        $ten = implode("\n", array_fill(0, 10, 'ab'));

        $this->assertSame('lines', Truncate::head($ten, 3, 1000)->truncatedBy);
        $this->assertSame('bytes', Truncate::head($ten, 3, 5)->truncatedBy, 'when bytes ran out first');
    }

    // ---- line(): one over-long match ------------------------------------------------------

    public function testALineOfExactlyTheWidthIsNotCut(): void
    {
        [$text, $cut] = Truncate::line(str_repeat('z', 500), 500);

        $this->assertFalse($cut);
        $this->assertSame(500, strlen($text));

        [, $over] = Truncate::line(str_repeat('z', 501), 500);

        $this->assertTrue($over);
    }

    public function testTheWidthIsCountedInCharactersAndNotBytes(): void
    {
        // Ten Chinese characters are thirty bytes. A byte count would cut this; a character count
        // is what the number is for, and is the documented divergence from upstream's code units.
        [, $cut] = Truncate::line(str_repeat('中', 10), 10);

        $this->assertFalse($cut);
    }

    // ---- size(): the number a person and a model both read --------------------------------

    #[DataProvider('sizesAtTheirUnitBoundaries')]
    public function testEachUnitChangesExactlyWhereItShould(int $bytes, string $expected): void
    {
        $this->assertSame($expected, Truncate::size($bytes));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function sizesAtTheirUnitBoundaries(): array
    {
        return [
            'one under a kilobyte' => [1023, '1023B'],
            'exactly a kilobyte' => [1024, '1.0KB'],
            'one under a megabyte' => [1024 * 1024 - 1, '1024.0KB'],
            'exactly a megabyte' => [1024 * 1024, '1.0MB'],
            'nothing at all' => [0, '0B'],
        ];
    }
}
