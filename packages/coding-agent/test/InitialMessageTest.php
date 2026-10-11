<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\InitialMessage;

/** Upstream's `buildInitialMessage()` and `readPipedStdin()`. */
final class InitialMessageTest extends TestCase
{
    public function testStdinThenFilesThenTheFirstMessageJoinedWithNothing(): void
    {
        $this->assertSame(
            ["diff\n<file name=\"a\">x</file>\nreview this", 'and then this'],
            InitialMessage::build("diff\n", "<file name=\"a\">x</file>\n", ['review this', 'and then this']),
        );
    }

    public function testNothingToSayIsNoFirstPrompt(): void
    {
        $this->assertSame([], InitialMessage::build(null, '', []));
        $this->assertSame(['piped'], InitialMessage::build('piped', '', []));
        $this->assertSame(['one', 'two'], InitialMessage::build(null, '', ['one', 'two']));
    }

    public function testPipedStdinIsReadTrimmedAndEmptyIsNothing(): void
    {
        $pipe = fopen('php://memory', 'r+');
        fwrite($pipe, "  what is this?\n\n");
        rewind($pipe);
        $this->assertSame('what is this?', InitialMessage::readPipedStdin($pipe));

        $empty = fopen('php://memory', 'r+');
        $this->assertNull(InitialMessage::readPipedStdin($empty));
    }
}
