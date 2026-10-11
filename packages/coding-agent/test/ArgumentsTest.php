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

    public function testApproveAndNoApproveHaveUpstreamsShortForms(): void
    {
        $this->assertArrayHasKey('approve', Arguments::parse(['-a'])->options);
        $this->assertArrayHasKey('no-approve', Arguments::parse(['-na'])->options);
        $this->assertArrayHasKey('no-approve', Arguments::parse(['--no-approve', 'hi'])->options);
    }

    public function testSessionDirTakesTheNextWordAsItsValue(): void
    {
        $arguments = Arguments::parse(['--session-dir', '/tmp/s', 'hello']);

        $this->assertSame('/tmp/s', $arguments->options['session-dir']);
        $this->assertSame(['hello'], $arguments->messages);
    }

    public function testExportTakesThePathAfterItAndNotTheOneAfterThat(): void
    {
        // The trap `TAKES_A_VALUE` exists for: a session path does not begin with a dash, so an
        // `export` left off that list would have swallowed nothing and read the file as a message
        // — the flag then means "export" with no file and the file means "say this to the model".
        $parsed = Arguments::parse(['--export', 'a.jsonl', 'out.html']);

        $this->assertSame('a.jsonl', $parsed->value('export'));
        $this->assertSame(['out.html'], $parsed->messages, 'the second word is where it goes');
    }

    public function testSessionTakesTheIdOrPathAfterIt(): void
    {
        $parsed = Arguments::parse(['--session', '01a0ee50-aabf-7649-b059-925b25a6a31b']);

        $this->assertSame('01a0ee50-aabf-7649-b059-925b25a6a31b', $parsed->value('session'));
    }

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
        $parsed = $this->parse('--read-only', '--model', 'sonnet', '--no-session');

        $this->assertTrue($parsed->has('read-only'));
        $this->assertSame('sonnet', $parsed->value('model'));
        $this->assertTrue($parsed->has('no-session'));
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

    public function testAllOfUpstreamsShortOptionsAreThere(): void
    {
        foreach (Arguments::SHORT as $short => $long) {
            // With a word after it, since `-n` with nothing is upstream's `--name requires a value`.
            $this->assertTrue($this->parse('-' . $short, 'x')->has($long), "-{$short} should mean --{$long}");
        }

        $this->assertSame(['a', 'na', 'c', 'e', 'h', 'n', 'nbt', 'nc', 'ne', 'np', 'ns', 'nt', 'p', 'r', 't', 'v', 'xt'], array_keys(Arguments::SHORT));
    }

    public function testAShortOptionTakesAValueWhenItsLongFormDoes(): void
    {
        // The bug this guards: every short option used to be treated as a valueless flag, so
        // `-t read,grep` set `tools` to `''` and read the list as a message. A short option is
        // an abbreviation and nothing else.
        $this->assertSame('read,grep', $this->parse('-t', 'read,grep')->value('tools'));
        $this->assertSame([], $this->parse('-t', 'read,grep')->messages);
    }

    public function testResumeTakesNoValueAsUpstreamsDoesNot(): void
    {
        // Upstream's `-r` opens the list; a word after it is a message, not a session path —
        // `--session` is what takes one.
        $parsed = $this->parse('-r', 'carry on');

        $this->assertSame('', $parsed->value('resume'));
        $this->assertSame(['carry on'], $parsed->messages);
        $this->assertNotContains('resume', Arguments::TAKES_A_VALUE);
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

    public function testAnUnknownShortOptionIsAnError(): void
    {
        // Upstream's `Unknown option: -x`. Read as a message, a typo became the prompt; `--` is
        // the way to say something that starts with a dash.
        $parsed = $this->parse('-x', 'hello');

        $this->assertSame(['Unknown option: -x'], $parsed->errors());
        $this->assertSame(['hello'], $parsed->messages);
        $this->assertSame(['-3'], $this->parse('--', '-3')->messages);
    }

    public function testAnUnknownLongOptionIsKeptForTheExtensionsWithTheValueAfterIt(): void
    {
        // Upstream's `unknownFlags`: the next word is its value unless it is an option or a file,
        // and `--name=value` says so outright. Whether an extension registered it is decided later.
        $parsed = $this->parse('--plan', 'big', '--dry', '--', 'go', '--depth=3');

        $this->assertSame(['plan' => 'big', 'dry' => true], $parsed->unknownFlags);
        $this->assertSame('big', $parsed->value('plan'));
        $this->assertSame('', $parsed->value('dry'));
        $this->assertSame(['go', '--depth=3'], $parsed->messages);
        $this->assertSame([], $parsed->errors());

        $this->assertSame(['depth' => '3'], $this->parse('--depth=3')->unknownFlags);
        $this->assertSame(['plan' => true], $this->parse('--plan', '@file')->unknownFlags);
        $this->assertSame(['file'], $this->parse('--plan', '@file')->files);
    }

    public function testNoSaveIsNotAnOptionAnyMore(): void
    {
        // Renamed to upstream's `--no-session`, with nothing reading the old spelling.
        $this->assertSame(['no-save' => true], $this->parse('--no-save')->unknownFlags);
        $this->assertContains('no-session', Arguments::FLAGS);
    }

    public function testUpstreamsNewOptionsTakeTheirValues(): void
    {
        $parsed = $this->parse(
            '--provider', 'openai', '--system-prompt', 'Be brief.', '--append-system-prompt', 'a.md',
            '--append-system-prompt', 'More.', '--session-id', 'my-id', '--fork', 'abc', '--tui-mode', 'regular',
            '--verbose', '--offline', '--no-session', 'go',
        );

        $this->assertSame('openai', $parsed->value('provider'));
        $this->assertSame('Be brief.', $parsed->value('system-prompt'));
        $this->assertSame(['a.md', 'More.'], $parsed->values('append-system-prompt'));
        $this->assertSame('my-id', $parsed->value('session-id'));
        $this->assertSame('abc', $parsed->value('fork'));
        $this->assertSame('regular', $parsed->value('tui-mode'));
        $this->assertTrue($parsed->has('verbose'));
        $this->assertTrue($parsed->has('offline'));
        $this->assertTrue($parsed->has('no-session'));
        $this->assertSame(['go'], $parsed->messages);
        $this->assertSame([], $parsed->diagnostics);
    }

    public function testTheValidatedOptionsSayWhatIsWrongAsUpstreamsDo(): void
    {
        $this->assertSame(['Invalid TUI mode "wide". Valid values: regular, fullscreen'], $this->parse('--tui-mode', 'wide')->errors());
        $this->assertSame(['--tui-mode requires regular or fullscreen'], $this->parse('--tui-mode')->errors());
        $this->assertSame(['Invalid mode "interactive". Valid values: text, json, rpc, web, mcp'], $this->parse('--mode', 'interactive')->errors());
        $this->assertSame(['--mode requires text, json, rpc, web, or mcp'], $this->parse('--mode', '-p')->errors());
        $this->assertSame(['--use-theme requires a theme name'], $this->parse('--use-theme')->errors());
        $this->assertSame(['--name requires a value'], $this->parse('--name')->errors());

        // A thinking level that is not one is a warning, and no level.
        $thinking = $this->parse('--thinking', 'loud');
        $this->assertSame([], $thinking->errors());
        $this->assertSame('warning', $thinking->diagnostics[0]['type']);
        $this->assertFalse($thinking->has('thinking'));
    }

    public function testListModelsTakesASearchOnlyWhenOneFollows(): void
    {
        $this->assertSame('', $this->parse('--list-models', '--offline')->value('list-models'));
        $this->assertTrue($this->parse('--list-models', '--offline')->has('offline'));
        $this->assertSame('', $this->parse('--list-models')->value('list-models'));
    }

    public function testPrintTakesTheMessageAfterIt(): void
    {
        // Upstream's `-p "prompt"`, and `---` is text rather than an option.
        $this->assertSame(['hi'], $this->parse('-p', 'hi')->messages);
        $this->assertSame(['--- a rule'], $this->parse('-p', '--- a rule')->messages);
        $this->assertTrue($this->parse('-p', '--offline')->has('offline'));
        $this->assertSame(['f.txt'], $this->parse('-p', '@f.txt')->files);
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
        $parsed = $this->parse('--read-only', '--', '--model', '-p', '@afile');

        $this->assertTrue($parsed->has('read-only'));
        $this->assertFalse($parsed->has('model'));
        $this->assertFalse($parsed->has('print'));
        $this->assertSame(['--model', '-p'], $parsed->messages);
        $this->assertSame(['afile'], $parsed->files, 'past `--` an @ is still a file, as upstream reads it');
    }

    // ---- which way in -------------------------------------------------------------------

    public function testBothStreamsTerminalsAndNothingSaidIsTheTerminal(): void
    {
        $this->assertSame('interactive', $this->parse('--read-only', 'have a look')->appMode(true, true));
    }

    public function testModeTextOnATerminalStillOpensTheTerminal(): void
    {
        // Upstream's `resolveAppMode()`: `--mode text` is the default output, not print mode.
        $this->assertSame('interactive', $this->parse('--mode', 'text', 'hi')->appMode(true, true));
        $this->assertSame('print', $this->parse('--mode', 'text', '-p', 'hi')->appMode(true, true));
    }

    public function testPrintOrAStreamThatIsNotATerminalIsPrintMode(): void
    {
        $this->assertSame('print', $this->parse('-p', 'ask')->appMode(true, true));
        $this->assertSame('print', $this->parse('ask')->appMode(false, true), 'stdin piped');
        $this->assertSame('print', $this->parse('ask')->appMode(true, false), 'stdout redirected');
    }

    public function testTheProtocolModesAreWhatTheySayWhateverTheStreams(): void
    {
        foreach (['json', 'rpc', 'web', 'mcp'] as $mode) {
            $this->assertSame($mode, $this->parse('--mode', $mode)->appMode(false, false));
            $this->assertSame($mode, $this->parse('-p', '--mode', $mode)->appMode(true, true));
        }
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

    public function testMcpPortTakesAValue(): void
    {
        $this->assertSame('8089', $this->parse('--mode', 'mcp', '--mcp-port', '8089')->options['mcp-port']);
    }

    // ---- the proxy ----------------------------------------------------------------------

    public function testTheProxyFlagTakesItsValueAndNotTheMessage(): void
    {
        $parsed = $this->parse('--proxy', 'socks5://127.0.0.1:7891', 'fix the bug');

        $this->assertSame('socks5://127.0.0.1:7891', $parsed->value('proxy'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testToolsTakesItsListAndNotThePromptAfterIt(): void
    {
        $parsed = $this->parse('--tools', 'read,grep', 'fix the bug');

        // The same trap as `--api-key`: a value-taking option missing from `TAKES_A_VALUE` eats
        // the next word, and here that would be the list of tools eating nothing and the prompt
        // becoming the list.
        $this->assertSame('read,grep', $parsed->options['tools']);
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testExcludeToolsTakesItsListToo(): void
    {
        $parsed = $this->parse('--exclude-tools', 'bash,mcp__*', 'fix the bug');

        $this->assertSame('bash,mcp__*', $parsed->options['exclude-tools']);
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testNoMcpIsAFlag(): void
    {
        $parsed = $this->parse('--no-mcp', 'fix the bug');

        $this->assertTrue($parsed->has('no-mcp'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testNoToolFilesIsAFlagAndNoToolsIsUpstreams(): void
    {
        // `--no-tool-files` skips the tools somebody wrote in `~/.pig/agent/tools`; `--no-tools`
        // is upstream's, every tool off. Both flags, and neither eats the prompt.
        $parsed = $this->parse('--no-tool-files', '-nt', 'fix the bug');

        $this->assertTrue($parsed->has('no-tool-files'));
        $this->assertTrue($parsed->has('no-tools'));
        $this->assertFalse(isset($parsed->options['tools']));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testTheRepeatableOptionsKeepEveryValueInOrder(): void
    {
        $parsed = $this->parse('-e', 'a', '--skill', 's1', '--skill=s2', '--prompt-template', 'p', '--theme', 't1', '--theme', 't2', '--use-theme', 'dark', 'go');

        $this->assertSame(['a'], $parsed->values('extension'));
        $this->assertSame(['s1', 's2'], $parsed->values('skill'));
        $this->assertSame(['p'], $parsed->values('prompt-template'));
        $this->assertSame(['t1', 't2'], $parsed->values('theme'));
        $this->assertSame('t2', $parsed->value('theme'), 'the last one, for whoever reads one');
        $this->assertSame('dark', $parsed->value('use-theme'));
        $this->assertSame([], $parsed->values('name'));
        $this->assertSame(['go'], $parsed->messages);
    }

    public function testShortToolsAndNameTakeTheirValues(): void
    {
        $parsed = $this->parse('-t', 'read,bash', '-xt', 'bash', '-n', 'Refactor auth', 'go');

        $this->assertSame('read,bash', $parsed->value('tools'));
        $this->assertSame('bash', $parsed->value('exclude-tools'));
        $this->assertSame('Refactor auth', $parsed->value('name'));
        $this->assertSame(['go'], $parsed->messages);
    }

    public function testNoUpdateCheckIsAFlagAndLeavesThePromptAlone(): void
    {
        $parsed = $this->parse('--no-update-check', 'fix the bug');

        // The fourth of the `--no-…` switches, and the one whose value-taking sibling does not
        // exist: there is nothing to say about the check except whether to make it, so it is a
        // flag and `TAKES_A_VALUE` must never learn its name — that list is what decides whether
        // an option eats the word after it, which here is the whole prompt.
        $this->assertTrue($parsed->has('no-update-check'));
        $this->assertSame(['fix the bug'], $parsed->messages);
        $this->assertNotContains('no-update-check', Arguments::TAKES_A_VALUE);
    }

    public function testModelsAndListModelsAreTwoDifferentOptions(): void
    {
        $scope = $this->parse('--models', "sonnet,anthropic/*", 'fix the bug');

        // `--models` narrows the session and leaves the prompt alone; it is safe in the list for
        // the ordinary reason rather than the `--list-models` one: a comma-separated list of
        // patterns is never the message.
        $this->assertSame('sonnet,anthropic/*', $scope->options['models']);
        $this->assertSame(['fix the bug'], $scope->messages);

        // And `--list-models` prints and exits, so there is no prompt left for it to eat — the
        // same argument `--export` and `--resume` rest on. They swapped names with upstream's:
        // pig's `--models` used to be the listing.
        $listing = $this->parse('--list-models', 'gem pro');

        $this->assertSame('gem pro', $listing->options['list-models']);
        $this->assertSame([], $listing->messages);
        $this->assertFalse(isset($listing->options['models']));
    }

    public function testNoProxyIsAFlagAndNotAnOptionWithAValue(): void
    {
        $parsed = $this->parse('--no-proxy', 'fix the bug');

        // The mistake this rules out is the one the whole `TAKES_A_VALUE` list exists for:
        // `--no-proxy "fix the bug"` reading the prompt as a proxy URL and then having no prompt.
        $this->assertTrue($parsed->has('no-proxy'));
        $this->assertSame(['fix the bug'], $parsed->messages);
    }

    public function testExtensionTakesAValueAndNoExtensionsIsAFlag(): void
    {
        $this->assertContains('extension', Arguments::TAKES_A_VALUE);
        $this->assertNotContains('no-extensions', Arguments::TAKES_A_VALUE);

        $parsed = $this->parse('--extension', '/path/to/ext.php', 'say hello');
        $this->assertSame('/path/to/ext.php', $parsed->value('extension'));
        $this->assertSame(['say hello'], $parsed->messages);

        $flagOnly = $this->parse('--no-extensions', 'say hello');
        $this->assertTrue($flagOnly->has('no-extensions'));
        $this->assertSame(['say hello'], $flagOnly->messages);
    }

    public function testAMessageFromTheShellArrivesAsTextRatherThanAsBytes(): void
    {
        // A shell hands over bytes: `pig $'ask about \xe9 this'` is a perfectly ordinary thing to
        // type in a latin-1 locale. Node decodes `process.argv` as UTF-8 and substitutes U+FFFD, so
        // upstream never sees it; here it has to be said.
        $arguments = $this->parse("ask about \xe9 this");

        $this->assertTrue(mb_check_encoding($arguments->messages[0], 'UTF-8'));
        $this->assertStringContainsString('ask about', $arguments->messages[0]);
        $this->assertStringContainsString('this', $arguments->messages[0]);
    }
}
