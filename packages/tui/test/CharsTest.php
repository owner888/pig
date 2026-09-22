<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Chars;

/**
 * The word scan both editors share.
 *
 * Input and Editor each exercise it through their own keys; this pins the boundaries
 * themselves, where the rules are easier to read than through a sequence of keystrokes.
 */
final class CharsTest extends TestCase
{
    /** @return list<array{string, int, int, string}> */
    public static function starts(): array
    {
        return [
            ['hello world', 11, 6, 'the last word'],
            ['hello world   ', 14, 6, 'trailing spaces are skipped first'],
            ['cd src/pig', 10, 7, 'a path comes off one segment at a time'],
            ['cd src/', 7, 6, 'the separator is a run of its own'],
            ['hello', 0, 0, 'nothing before the start'],
            ['   ', 3, 0, 'all whitespace'],
            ['中文 word', 11, 7, 'CJK is word characters like any other'],
            ['a👨‍👩‍👧‍👦b', 27, 0, 'a family emoji is one word character, not twenty-five'],
        ];
    }

    #[DataProvider('starts')]
    public function testWordStart(string $text, int $offset, int $expected, string $why): void
    {
        $this->assertSame($expected, Chars::wordStart($text, $offset), $why);
    }

    /** @return list<array{string, int, int, string}> */
    public static function ends(): array
    {
        return [
            ['hello world', 0, 5, 'the first word'],
            ['   hello', 0, 8, 'leading spaces are skipped first'],
            ['src/pig', 3, 4, 'the separator is a run of its own'],
            ['hello', 5, 5, 'nothing after the end'],
            ['a.b', 0, 1, 'a word stops where punctuation starts'],
        ];
    }

    #[DataProvider('ends')]
    public function testWordEnd(string $text, int $offset, int $expected, string $why): void
    {
        $this->assertSame($expected, Chars::wordEnd($text, $offset), $why);
    }

    public function testUnderscoreIsPartOfAWord(): void
    {
        // Unicode calls it punctuation; anyone editing code does not.
        $this->assertFalse(Chars::isPunctuation('_'));
        $this->assertTrue(Chars::isWord('_'));
        $this->assertSame(0, Chars::wordStart('some_name', 9));
    }

    public function testPrintableRejectsEveryKindOfControlByte(): void
    {
        $this->assertTrue(Chars::isPrintable('a中👍'));
        $this->assertFalse(Chars::isPrintable(''));

        foreach (["\x00", "\x1b", "\x7f", "\u{0085}", "a\x01"] as $data) {
            $this->assertFalse(Chars::isPrintable($data), json_encode($data));
        }
    }
}
