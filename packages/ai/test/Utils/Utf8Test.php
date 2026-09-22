<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Utf8;

final class Utf8Test extends TestCase
{
    #[DataProvider('texts')]
    public function testLeavesValidTextAloneAndDropsTheRest(string $input, string $expected): void
    {
        $this->assertSame($expected, Utf8::sanitize($input));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function texts(): array
    {
        return [
            'plain ascii' => ['hello', 'hello'],
            'empty' => ['', ''],
            'CJK' => ['你好，世界', '你好，世界'],
            // Astral characters are four valid bytes in UTF-8 and must survive untouched.
            'emoji' => ['Hello 🙈 World', 'Hello 🙈 World'],
            'combining marks' => ["e\u{0301}", "e\u{0301}"],
            // A surrogate code point encoded as UTF-8 — what a JS string would call unpaired.
            'a surrogate encoded as UTF-8' => ["x\xED\xA0\xBDy", 'xy'],
            'a truncated multi-byte sequence' => ["abc\xF0\x9F", 'abc'],
            'a stray continuation byte' => ["a\x80b", 'ab'],
        ];
    }

    public function testTheResultAlwaysSurvivesJsonEncode(): void
    {
        $broken = "prompt \xED\xA0\xBD with \xF0\x9F junk";

        $this->assertFalse(json_encode($broken) !== false, 'the raw string should be unencodable');
        $this->assertTrue(json_encode(Utf8::sanitize($broken)) !== false);
    }

    public function testTheGlobalSubstituteCharacterIsPutBack(): void
    {
        // sanitize() flips a global mbstring setting to delete rather than replace.
        mb_substitute_character(0x3F);
        Utf8::sanitize("bad \xED\xA0\xBD bytes");

        $this->assertSame(0x3F, mb_substitute_character());
    }
}
