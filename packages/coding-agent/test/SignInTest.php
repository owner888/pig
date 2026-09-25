<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Cli\SignIn;

/**
 * `pig-ai`, the second entry point.
 *
 * The file exists because the first version of this was inline in `bin/pig-ai` and had a closure
 * that captured nothing — so the moment Anthropic's flow asked for the pasted code, it called
 * null. A script ending in `exit()` cannot be reached twice by a test; this can.
 */
final class SignInTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];

    /** @var list<string> */
    private array $warned = [];

    /** @var list<string> */
    private array $asked = [];

    /** @var list<?string> */
    private array $answers = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->said = [];
        $this->warned = [];
        $this->asked = [];
        $this->answers = [];
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, ?Auth $auth = null): int
    {
        $signIn = new SignIn(
            $auth ?? Auth::inMemory(),
            function (string $question): ?string {
                $this->asked[] = $question;

                return array_shift($this->answers);
            },
            function (string $line): void {
                $this->said[] = $line;
            },
            function (string $line): void {
                $this->warned[] = $line;
            },
        );

        // A fiber, as `bin/pig-ai` does: two of the four flows park one.
        return (int) Async::run(static fn (): int => $signIn->run($arguments));
    }

    private function output(): string
    {
        return implode("\n", $this->said);
    }

    // ---- the commands ---------------------------------------------------------------------

    public function testNoArgumentsIsTheUsage(): void
    {
        $this->assertSame(0, $this->run([]));
        $this->assertStringContainsString('pig-ai login <provider>', $this->output());
    }

    public function testHelpInAllThreeSpellings(): void
    {
        foreach (['help', '--help', '-h'] as $spelling) {
            $this->said = [];

            $this->assertSame(0, $this->run([$spelling]), $spelling);
            $this->assertStringContainsString('pig-ai list', $this->output(), $spelling);
        }
    }

    public function testTheUsageSaysWhereTheTokenGoes(): void
    {
        $this->run(['help']);

        // Which is the one thing somebody reading this on a fresh machine needs, and the one
        // place this differs from upstream — whose own writes `auth.json` into the current
        // directory.
        $this->assertStringContainsString('~/.pi/agent/auth.json', $this->output());
        $this->assertStringContainsString('~/.pig/auth.json', $this->output());
    }

    public function testListNamesEveryProviderByIdAndLabel(): void
    {
        $this->assertSame(0, $this->run(['list']));

        $listed = $this->output();

        foreach (Provider::cases() as $provider) {
            $this->assertStringContainsString($provider->value, $listed, $provider->value);
            $this->assertStringContainsString($provider->label(), $listed, $provider->label());
        }
    }

    public function testACommandThatIsNotOneIsNamedAndFails(): void
    {
        $this->assertSame(1, $this->run(['frobnicate']));
        $this->assertStringContainsString("No command called 'frobnicate'", implode("\n", $this->warned));
    }

    // ---- choosing a provider ---------------------------------------------------------------

    public function testAProviderThatIsNotOneIsNamedAndFails(): void
    {
        $this->assertSame(1, $this->run(['login', 'nonsense']));
        $this->assertStringContainsString("No provider called 'nonsense'", implode("\n", $this->warned));
        $this->assertSame([], $this->asked, 'and nothing was asked');
    }

    public function testLoginWithNoProviderOffersANumberedList(): void
    {
        $this->answers = ['2'];

        $this->run(['login']);

        $this->assertStringContainsString('1. Anthropic (Claude Pro/Max)', $this->output());
        $this->assertStringContainsString('4. Antigravity', $this->output());
        $this->assertStringContainsString('(1-4)', $this->asked[0], 'the numbered one comes first');

        // Two is Copilot, and reaching its own first question — which is what the second entry in
        // `asked` is — proves the number resolved to a flow rather than to a label.
        $this->assertStringContainsString('Signing in to GitHub Copilot', $this->output());
        $this->assertCount(2, $this->asked, 'and then the flow asked something of its own');
    }

    public function testANumberThatIsNotOnTheListSaysBothWaysWork(): void
    {
        $this->answers = ['9'];

        $this->assertSame(1, $this->run(['login']));
        $this->assertStringContainsString('pig-ai login <provider>', implode("\n", $this->warned));
    }

    public function testEndOfInputAtTheChoiceIsNotASignIn(): void
    {
        // A pipe that closed, which is what happens to a `pig-ai login` in a script.
        $this->answers = [null];

        $this->assertSame(1, $this->run(['login']));
        $this->assertSame([], array_filter($this->said, static fn (string $l): bool => str_contains($l, 'Signed in')));
    }

    // ---- the flow -------------------------------------------------------------------------

    public function testAnthropicShowsAUrlAndThenAsksForThePaste(): void
    {
        // The bug this file was written for: the `$onPrompt` closure captured nothing, so the
        // first flow to ask a question called null. Escaping the paste box reaches it.
        $this->answers = [''];

        $this->assertSame(1, $this->run(['login', 'anthropic']));

        $this->assertStringContainsString('Open this in your browser:', $this->output());
        $this->assertStringContainsString('claude.ai/oauth/authorize', $this->output());
        $this->assertCount(1, $this->asked);
        $this->assertStringContainsString('code#state', $this->asked[0]);
        $this->assertStringContainsString('Nothing was signed in.', implode("\n", $this->warned));
    }

    public function testCopilotIsAskedWhichGitHubFirst(): void
    {
        // Escaped at the domain question, before any request is made — which is what makes this
        // testable without a server.
        $this->answers = [null];

        $this->assertSame(1, $this->run(['login', 'github-copilot']));
        $this->assertNotSame([], $this->asked);
        $this->assertStringContainsString('Nothing was signed in.', implode("\n", $this->warned));
    }

    public function testAFlowWithNoClientCredentialsSaysWhatIsMissing(): void
    {
        $this->assertSame(1, $this->run(['login', 'google-gemini-cli']));

        $warned = implode("\n", $this->warned);

        // The `OauthError` arm: named rather than a stack trace, because every one of these says
        // what is absent.
        $this->assertStringContainsString('GEMINI_CLI_CLIENT_ID', $warned);
        $this->assertSame([], $this->asked, 'refused before anybody was sent anywhere');
    }
}
