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
        ];
    }

    #[DataProvider('widths')]
    public function testVisibleWidth(string $text, int $expected, string $why): void
    {
        $this->assertSame($expected, Width::visible($text), $why);
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
}
