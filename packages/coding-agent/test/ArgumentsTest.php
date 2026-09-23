<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\Arguments;

/**
 * What was typed on the command line.
 *
 * Most of these are one line each, and the file is here for one of them: the flag that ate
 * the prompt. Everything else is the shape around that.
 */
final class ArgumentsTest extends TestCase
{
    private function parse(string ...$arguments): Arguments
    {
        return Arguments::parse(array_values($arguments));
    }

    // ---- options ------------------------------------------------------------------------

    public function testAnOptionWithAValueTakesTheNextArgument(): void
    {
        $this->assertSame('sonnet', $this->parse('--model', 'sonnet')->value('model'));
    }

    public function testAnEqualsSignWorksToo(): void
    {
        $this->assertSame('sonnet:high', $this->parse('--model=sonnet:high')->value('model'));
    }

    public function testAFlagIsPresentWithNoValue(): void
    {
        $parsed = $this->parse('--read-only');

        $this->assertTrue($parsed->has('read-only'));
        $this->assertSame('', $parsed->value('read-only'));
    }

    public function testAnOptionThatWasNotGivenIsNull(): void
    {
        $this->assertNull($this->parse()->value('model'));
        $this->assertFalse($this->parse()->has('model'));
    }

    public function testAFlagDoesNotEatTheMessageAfterIt(): void
    {
        $parsed = $this->parse('--read-only', 'fix the bug');

        // The bug this file exists for. Inferring that `--read-only` takes a value because
        // `fix the bug` does not start with a dash lost the prompt and said nothing.
        $this->assertSame('', $parsed->value('read-only'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testAFlagBeforeAnOptionWithAValueStillWorks(): void
    {
        $parsed = $this->parse('--read-only', '--model', 'sonnet', '--no-save');

        $this->assertTrue($parsed->has('read-only'));
        $this->assertSame('sonnet', $parsed->value('model'));
        $this->assertTrue($parsed->has('no-save'));
        $this->assertSame([], $parsed->messages);
    }

    public function testAnOptionAtTheEndWithNothingAfterItIsEmptyRatherThanAnError(): void
    {
        // Empty, and whoever validates `--model` says what is wrong with it. A message
        // about array bounds from in here would be about the wrong thing.
        $this->assertSame('', $this->parse('--model')->value('model'));
    }

    public function testAShortOptionIsTheLongOneItMeans(): void
    {
        $this->assertTrue($this->parse('-p')->has('print'));
    }

    public function testSomethingThatIsNotAKnownShortOptionIsAMessage(): void
    {
        // Not silently dropped: `-3` is more likely part of what someone meant to say than
        // an option, and a message is the reading that loses nothing.
        $this->assertSame(['-3'], $this->parse('-3')->messages);
    }

    // ---- messages and files -------------------------------------------------------------

    public function testEverythingElseIsAMessageInOrder(): void
    {
        $this->assertSame(['one', 'two'], $this->parse('one', '--read-only', 'two')->messages);
    }

    public function testAnAtSignMakesItAFile(): void
    {
        $parsed = $this->parse('@src/Thing.php', 'why is this slow?');

        $this->assertSame(['src/Thing.php'], $parsed->files);
        $this->assertSame(['why is this slow?'], $parsed->messages);
    }

    public function testABareAtSignIsAMessage(): void
    {
        // A path to nothing. Reading it as a request to open a file called '' helps nobody,
        // and it is far more likely a typo in something that was going to be said.
        $this->assertSame(['@'], $this->parse('@')->messages);
        $this->assertSame([], $this->parse('@')->files);
    }

    public function testAnEmailAddressIsStillReadAsAFile(): void
    {
        // Documented rather than defended: `@` is how a file is named, and pig cannot tell
        // `@me@example.com` from a path. `--` is the way out.
        $this->assertSame(['me@example.com'], $this->parse('@me@example.com')->files);
    }

    public function testADoubleDashEndsTheOptionsSoAMessageCanStartWithADash(): void
    {
        $parsed = $this->parse('--read-only', '--', '--model', '-p', '@notafile');

        $this->assertTrue($parsed->has('read-only'));
        $this->assertFalse($parsed->has('model'));
        $this->assertFalse($parsed->has('print'));
        $this->assertSame(['--model', '-p', '@notafile'], $parsed->messages);
        $this->assertSame([], $parsed->files, 'past `--` an @ is text too');
    }

    // ---- which way in -------------------------------------------------------------------

    public function testNothingSaidIsTheTerminal(): void
    {
        $parsed = $this->parse('--read-only', 'have a look');

        $this->assertNull($parsed->mode());
        $this->assertTrue($parsed->isInteractive());
    }

    public function testPrintIsTheShortWayOfSayingModeText(): void
    {
        $parsed = $this->parse('-p', 'ask');

        $this->assertSame('text', $parsed->mode());
        $this->assertFalse($parsed->isInteractive());
    }

    public function testModeGivenAtAllLeavesTheTerminalBehind(): void
    {
        foreach (Arguments::MODES as $mode) {
            $parsed = $this->parse('--mode', $mode);

            $this->assertSame($mode, $parsed->mode());
            $this->assertFalse($parsed->isInteractive(), "--mode {$mode} is not interactive");
            $this->assertTrue($parsed->isKnownMode());
        }
    }

    public function testAnExplicitModeWinsOverTheShortFlag(): void
    {
        $this->assertSame('json', $this->parse('-p', '--mode', 'json')->mode());
    }

    public function testAModeThatIsNotAModeIsReportedRatherThanAssumed(): void
    {
        $parsed = $this->parse('--mode', 'interactive');

        $this->assertFalse($parsed->isKnownMode());
        $this->assertSame('interactive', $parsed->mode(), 'as it was written, so the error can quote it');
    }
}
