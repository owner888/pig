<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\ProgramStatus;

/** Upstream's `program-status.test.ts` (#10607). */
final class ProgramStatusTest extends TestCase
{
    public function testEncodesStateAppKindAndABase64Message(): void
    {
        $this->assertSame(
            "\x1b]7501;state=blocked:app=pi:kind=permission:msg=" . base64_encode('Allow bash?') . "\x1b\\",
            (new ProgramStatus('blocked', 'pi', 'permission', 'Allow bash?'))->format(),
        );
        $this->assertSame("\x1b]7501;state=clear\x1b\\", (new ProgramStatus('clear'))->format());
    }

    public function testOmitsKindOutsideBlockedInvalidAppNamesAndEmptyMessages(): void
    {
        $this->assertSame("\x1b]7501;state=working\x1b\\", (new ProgramStatus('working', 'my app', 'auth', " \n "))->format());
        $this->assertMatchesRegularExpression('/:app=a{32}\x1b/', (new ProgramStatus('idle', str_repeat('a', 32)))->format());
        $this->assertDoesNotMatchRegularExpression('/app=/', (new ProgramStatus('idle', str_repeat('a', 33)))->format());
    }

    public function testReplacesControlCharactersWhichMakeTerminalsDiscardTheReport(): void
    {
        $sequence = (new ProgramStatus('error', message: "first\nsecond\x1b[31m\u{009b}third\t"))->format();
        $this->assertSame('first second [31m third', self::decodeMessage($sequence));
    }

    public function testCutsLongMessagesAtAUtf8BoundaryWithinTheSpecLimits(): void
    {
        $sequence = (new ProgramStatus('working', 'pi', message: str_repeat('é', 2000)))->format();
        $message = self::decodeMessage($sequence);
        $this->assertSame(str_repeat('é', 1024), $message);
        $this->assertLessThanOrEqual(2048, strlen((string) $message));
        $this->assertLessThanOrEqual(4096, strlen($sequence));
    }

    public function testAcceptsTheQueryEchoWithEitherTerminatorAndFuturePairs(): void
    {
        $this->assertTrue(ProgramStatus::isReply("\x1b]7501;?\x1b\\"));
        $this->assertTrue(ProgramStatus::isReply("\x1b]7501;?\x07"));
        $this->assertTrue(ProgramStatus::isReply("\x1b]7501;?version=2\x1b\\"));
        $this->assertFalse(ProgramStatus::isReply("\x1b]7501;state=idle\x1b\\"));
        $this->assertFalse(ProgramStatus::isReply("\x1b]11;rgb:0000/0000/0000\x07"));
    }

    private static function decodeMessage(string $sequence): ?string
    {
        return preg_match('/:msg=([A-Za-z0-9+\/=]*)/', $sequence, $match) === 1 ? base64_decode($match[1], true) : null;
    }
}
