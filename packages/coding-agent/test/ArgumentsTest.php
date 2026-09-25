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

    public function testAllFiveOfUpstreamsShortOptionsAreThere(): void
    {
        foreach (Arguments::SHORT as $short => $long) {
            $this->assertTrue($this->parse('-' . $short)->has($long), "-{$short} should mean --{$long}");
        }

        $this->assertSame(['c', 'h', 'p', 'r', 'v'], array_keys(Arguments::SHORT));
    }

    public function testAShortOptionTakesAValueWhenItsLongFormDoes(): void
    {
        // The bug this guards: every short option used to be treated as a valueless flag, so
        // `-r some/path.jsonl` set `resume` to `''` — the picker — and read the path as a
        // message. A short option is an abbreviation and nothing else.
        $this->assertSame('some/path.jsonl', $this->parse('-r', 'some/path.jsonl')->value('resume'));
        $this->assertSame([], $this->parse('-r', 'some/path.jsonl')->messages);
    }

    public function testAShortOptionWithNothingAfterItIsEmptyNotAbsent(): void
    {
        // Empty is what `bin/pig` reads as "show me the list", which is the whole point of
        // a bare `-r`. Absent would mean a new session.
        $this->assertSame('', $this->parse('-r')->value('resume'));
        $this->assertTrue($this->parse('-r')->has('resume'));
    }

    public function testAShortFlagStillDoesNotEatWhatFollows(): void
    {
        $parsed = $this->parse('-c', 'carry on from here');

        $this->assertSame('', $parsed->value('continue'));
        $this->assertSame(['carry on from here'], $parsed->messages);
    }

    public function testTheShortAndLongFormsAgreeOnEverything(): void
    {
        foreach (Arguments::SHORT as $short => $long) {
            $short = $this->parse('-' . $short, 'a value');
            $whole = $this->parse('--' . $long, 'a value');

            // One table decides whether a name takes a value, and both spellings ask it.
            $this->assertSame($whole->options, $short->options, "-{$long} and --{$long} differ");
            $this->assertSame($whole->messages, $short->messages);
        }
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

    // ---- the key ------------------------------------------------------------------------

    public function testTheApiKeyFlagTakesItsValueAndNotTheMessage(): void
    {
        $parsed = $this->parse('--api-key', 'sk-ant-abc', 'fix the bug');

        // The reason `api-key` had to be added to `TAKES_A_VALUE` rather than left to the
        // general rule: a key is exactly the kind of thing that does not start with a dash.
        $this->assertSame('sk-ant-abc', $parsed->value('api-key'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testTheApiKeyFlagWithNothingAfterItIsPresentAndEmpty(): void
    {
        $parsed = $this->parse('--api-key');

        // Present-but-empty rather than absent, because `bin/pig` refuses it by name — an
        // empty runtime key would beat the environment and then fail as a missing one.
        $this->assertTrue($parsed->has('api-key'));
        $this->assertSame('', $parsed->value('api-key'));
    }

    // ---- does it need a message ---------------------------------------------------------

    public function testRpcModeNeedsNoMessageBecauseItsMessagesArriveAsCommands(): void
    {
        // The bug this exists for: `bin/pig --mode rpc` was refused for having no message while
        // `bin/pig --mode rpc "hello"` was refused for having one, so rpc mode — one of the three
        // ways in, with its own class and its own twenty-two commands — could not be started at all.
        $this->assertFalse($this->parse('--mode', 'rpc')->needsAMessage());
    }

    public function testEveryOtherModeStillNeedsOne(): void
    {
        $this->assertTrue($this->parse('--mode', 'text')->needsAMessage());
        $this->assertTrue($this->parse('--mode', 'json')->needsAMessage());
        $this->assertTrue($this->parse('-p')->needsAMessage(), 'the short way of saying --mode text');
    }

    public function testTheTerminalNeedsNoMessageEither(): void
    {
        // Somebody will type one.
        $this->assertFalse($this->parse()->needsAMessage());
    }

    // ---- the proxy ----------------------------------------------------------------------

    public function testTheProxyFlagTakesItsValueAndNotTheMessage(): void
    {
        $parsed = $this->parse('--proxy', 'socks5://127.0.0.1:7891', 'fix the bug');

        $this->assertSame('socks5://127.0.0.1:7891', $parsed->value('proxy'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testNoProxyIsAFlagAndNotAnOptionWithAValue(): void
    {
        $parsed = $this->parse('--no-proxy', 'fix the bug');

        // The mistake this rules out is the one the whole `TAKES_A_VALUE` list exists for:
        // `--no-proxy "fix the bug"` reading the prompt as a proxy URL and then having no prompt.
        $this->assertTrue($parsed->has('no-proxy'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }
}
