<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Graphemes;
use Pig\Tui\Width;

final class WidthTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Width::clearCache();
    }

    /** @return list<array{string, int, string}> */
    public static function widths(): array
    {
        return [
            ['', 0, 'nothing'],
            ['hello', 5, 'ascii'],
            ['中文字', 6, 'CJK takes two columns each'],
            ['ab中', 4, 'mixed'],
            ["e\u{0301}", 1, 'a combining accent adds no width'],
            ["\u{00e9}", 1, 'the precomposed form is the same width'],
            ['👍', 2, 'an emoji takes two'],
            ['👨‍👩‍👧‍👦', 2, 'a ZWJ family is one emoji, not four'],
            ['👍🏽', 2, 'a skin tone modifier adds nothing'],
            ['🇨🇳', 2, 'a flag is two regional indicators drawn as one'],
            ["\u{26a0}", 1, 'a pictograph with text presentation is narrow'],
            ["\u{26a0}\u{fe0f}", 2, 'the same pictograph with VS16 is an emoji'],
            ['→', 1, 'an arrow is narrow'],
            ["x\u{200b}y", 2, 'a zero-width space takes no column'],
            ["\x1b[31mred\x1b[0m", 3, 'colour codes are invisible'],
            ["\x1b]8;;http://example.com\x07link\x1b]8;;\x07", 4, 'an OSC 8 hyperlink is invisible'],
            ["a\tb", 5, 'a tab is three spaces'],
            ["a\nb", 2, 'a newline is not a column'],
            ["abc\n", 3, 'and it is still not one at the end — see the trailing-newline trap'],
            ["\n", 0, 'on its own it is nothing'],
            ["hello world\n", 11, 'the ascii fast path has to agree with the slow one'],
        ];
    }

    #[DataProvider('widths')]
    public function testVisibleWidth(string $text, int $expected, string $why): void
    {
        $this->assertSame($expected, Width::visible($text), $why);
    }

    public function testATrailingNewlineIsNotAColumnAnywhereItIsMeasured(): void
    {
        // PCRE's `$` matches *before* a trailing newline, so the printable-ASCII fast path
        // accepted `"abc\n"` and answered `strlen()` — four columns for three. The slow path
        // disagreed (a newline is `\p{Cc}`, which is zero), so the same string measured 4 alone
        // and 3 inside a longer one.
        $this->assertSame(Width::visible('abc'), Width::visible("abc\n"));

        // What that bought, where it shows: a padded line one space short of the width.
        $padded = Width::background("abc\n", 6, static fn (string $text): string => $text);

        $this->assertSame("abc\n   ", $padded);
    }

    public function testAStyledAsciiLineIsMeasuredWithoutSegmentingIt(): void
    {
        // Every line of a transcript carries a colour, so a styled line of printable ASCII is
        // the common case, and it used to go through grapheme segmentation because the escape
        // in it failed the plain-ASCII fast path. 12,800 such lines at a new width were 236ms
        // of a resize frame — spent measuring lines whose every character is one column wide.
        // The answer is the same; what this pins is that it is reached without the walk, which
        // only a time can show. A ratio, because a number is a fact about the machine.
        $styled = [];
        $wide = [];

        for ($index = 0; $index < 3000; $index++) {
            $styled[] = "\e[38;2;1;2;3mline {$index} of plain text that is styled\e[39m";
            $wide[] = "\e[38;2;1;2;3mline {$index} of text with a 你 in it\e[39m";
        }

        $plain = self::milliseconds(static function () use ($styled): void {
            foreach ($styled as $line) {
                Width::visible($line);
            }
        });
        $segmented = self::milliseconds(static function () use ($wide): void {
            foreach ($wide as $line) {
                Width::visible($line);
            }
        });

        $this->assertSame(35, Width::visible("\e[38;2;1;2;3mline 0 of plain text that is styled\e[39m"));
        $this->assertLessThan($segmented / 3, $plain, 'a styled ASCII line was segmented');
    }

    private static function milliseconds(\Closure $work): float
    {
        $start = microtime(true);
        $work();

        return (microtime(true) - $start) * 1000;
    }

    public function testTheCacheReturnsTheSameAnswerTwice(): void
    {
        $text = '中文 👍';

        $this->assertSame(Width::visible($text), Width::visible($text));
    }

    public function testGraphemesAreSplitTheWayATerminalDrawsThem(): void
    {
        $this->assertSame(['a', "e\u{0301}", '👨‍👩‍👧‍👦', '🇨🇳'], Graphemes::split("ae\u{0301}👨‍👩‍👧‍👦🇨🇳"));
        $this->assertSame([], Graphemes::split(''));
    }

    public function testTruncateLeavesShortTextAlone(): void
    {
        $this->assertSame('hello', Width::truncate('hello', 10));
        $this->assertSame('hello', Width::truncate('hello', 5));
    }

    public function testTruncateCutsToFitIncludingTheEllipsis(): void
    {
        $this->assertSame("hell\x1b[0m...", Width::truncate('hello world', 7));
        $this->assertSame(7, Width::visible(Width::truncate('hello world', 7)));
    }

    public function testTruncateNeverSplitsAWideCharacter(): void
    {
        // Six columns of CJK cut to five, minus three for the ellipsis, leaves one whole
        // character — half of the second would be a column the terminal cannot draw.
        $cut = Width::truncate('中文字', 5);

        $this->assertSame("中\x1b[0m...", $cut);
        $this->assertSame(5, Width::visible($cut));
    }

    public function testTruncateKeepsTheStylingItPassedThrough(): void
    {
        $cut = Width::truncate("\x1b[31mhello world\x1b[0m", 7);

        $this->assertStringStartsWith("\x1b[31m", $cut);
        $this->assertSame(7, Width::visible($cut));
    }

    public function testTruncateWithNoRoomForTextGivesBackTheEllipsisAlone(): void
    {
        $this->assertSame('..', Width::truncate('hello', 2));
    }

    public function testBackgroundPadsToWidthBeforeColouring(): void
    {
        $painted = Width::background('hi', 5, static fn (string $text): string => "[{$text}]");

        $this->assertSame('[hi   ]', $painted);
    }

    public function testBackgroundDoesNotPadWhatAlreadyFills(): void
    {
        $painted = Width::background('hello', 3, static fn (string $text): string => "[{$text}]");

        $this->assertSame('[hello]', $painted);
    }
    /** @return array<string, array{0: string, 1: int}> */
    public static function names(): array
    {
        return [
            'ascii' => ['review', 6],
            'chinese' => ['代码审查', 8],
            'japanese' => ['データ整理', 10],
            'a combining mark' => ["e\u{0301}tude", 5],
            'an emoji' => ['ship 🚀', 7],
            'already wider' => ['a-very-long-name-indeed', 23],
        ];
    }

    /**
     * Padding is measured in columns, which `str_pad()` cannot do.
     *
     * Every one of these is a name that can be in a `/skills` listing or a `models.json`, and every
     * one but the first is padded short by `str_pad()` — so the column after it starts early and
     * the table reads as broken.
     */
    #[DataProvider('names')]
    public function testPadFillsToTheColumnAndNotToTheByte(string $text, int $columns): void
    {
        $padded = Width::pad($text, 12);

        $this->assertSame(max(12, $columns), Width::visible($padded));
        $this->assertStringStartsWith($text, $padded);
    }

    public function testPadCutsNothingThatIsAlreadyTooWide(): void
    {
        // As `str_pad()` cuts nothing. A caller that needs it to fit asks `truncate()` first.
        $this->assertSame('hello', Width::pad('hello', 3));
    }

    public function testPadCountsWhatIsDrawnAndNotTheEscapes(): void
    {
        $padded = Width::pad("\e[32mhi\e[0m", 5);

        $this->assertSame(5, Width::visible($padded));
        $this->assertSame("\e[32mhi\e[0m   ", $padded);
    }
}
