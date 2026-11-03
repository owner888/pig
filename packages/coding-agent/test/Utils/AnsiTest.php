<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Tools\Shell;
use Pig\CodingAgent\Utils\Ansi;

/** Upstream's `test/ansi-utils.test.ts`; U+009B and U+009C are written as the characters they are. */
final class AnsiTest extends TestCase
{
    public function testRisAndSingleByteEscSequencesGoWithoutLeakingTheirFinalByte(): void
    {
        $this->assertSame('done', Ansi::strip("\x1bcdone"));

        foreach ([...range('g', 'm'), ...range('r', 't')] as $final) {
            $this->assertSame('ok', Ansi::strip("\x1b{$final}ok"));
        }
    }

    public function testCommonSequencesInToolOutputGo(): void
    {
        $this->assertSame('aredlinkz', Ansi::strip("a\x1b[31mred\x1b[0m\x1b]8;;https://example.com\x07link\x1b]8;;\x07z"));
        $this->assertSame('ab', Ansi::strip("a\x1b[?2004hb"));
        $this->assertSame('ab', Ansi::strip("a\x1b[2Ab"));
        $this->assertSame('ab', Ansi::strip("a\x1b[1;2:3mb"));
        $this->assertSame('ared', Ansi::strip("a\u{9b}31mred"));
        $this->assertSame('az', Ansi::strip("a\x1b]title\u{9c}z"));
    }

    public function testPrivateParameterCsiSequencesGoWithTheirParameters(): void
    {
        $this->assertSame('step 1', Ansi::strip("\x1b[<35;59;1Mstep 1"));
        $this->assertSame('ab', Ansi::strip("a\x1b[<0;10;20mb"));
        $this->assertSame('ab', Ansi::strip("a\x1b[>0;276;0cb"));
        $this->assertSame('ab', Ansi::strip("a\x1b[>cb"));
        $this->assertSame('ab', Ansi::strip("a\x1b[=5hb"));
        $this->assertSame('ab', Ansi::strip("a\u{9b}<35;59;1Mb"));
    }

    public function testDigitsAfterABareEscKeypadModeStay(): void
    {
        $this->assertSame('123 tests passed', Ansi::strip("\x1b[?1h\x1b=123 tests passed"));
        $this->assertSame('42 files', Ansi::strip("\x1b[?1l\x1b>42 files"));
        $this->assertSame('10 items', Ansi::strip("\x1b<10 items"));
    }

    public function testAnUnfinishedPrivateParameterCsiIsHeldBack(): void
    {
        $this->assertSame(['x', "\x1b[<35;59"], Ansi::splitIncompleteSuffix("x\x1b[<35;59"));
        $this->assertSame(["x\x1b=", ''], Ansi::splitIncompleteSuffix("x\x1b="));
        $this->assertSame(['x', "\x1b]8;;http"], Ansi::splitIncompleteSuffix("x\x1b]8;;http"));
        $this->assertSame(['plain', ''], Ansi::splitIncompleteSuffix('plain'));
    }

    public function testToolOutputIsCleanedWithThisStripper(): void
    {
        // A mouse report used to leave `35;59;1M` in front of the text.
        $this->assertSame('step 1', Shell::sanitize("\x1b[<35;59;1Mstep 1"));
        $this->assertSame('123 tests', Shell::sanitize("\x1b=123 tests"));
    }
}
