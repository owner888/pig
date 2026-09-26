<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Async\Loop;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;

/** The two dim lines under everything: where you are, and what this has cost. */
final class FooterTest extends TestCase
{
    private const int WIDTH = 76;

    private Palette $palette;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->palette = Palette::dark(true);
        $this->cwd = sys_get_temp_dir() . '/pig-footer-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['/.git/HEAD', '/.git', ''] as $part) {
            $path = $this->cwd . $part;

            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    private function session(bool $reasoning = false): AgentSession
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));
        $agent->setModel(new Model(
            'claude-test',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            200_000,
            64_000,
            $reasoning,
        ));

        return new AgentSession($agent);
    }

    private function spent(AgentSession $session, Usage $usage, StopReason $stop = StopReason::Stop): void
    {
        $session->agent->appendMessage(new AssistantMessage(
            [new TextContent('x')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            $usage,
            $stop,
        ));
    }

    /** @return list<string> */
    private function lines(AgentSession $session): array
    {
        return array_map(
            Ansi::strip(...),
            (new FooterComponent($session, $this->palette, $this->cwd))->render(self::WIDTH),
        );
    }

    private function raw(AgentSession $session): string
    {
        return implode("\n", (new FooterComponent($session, $this->palette, $this->cwd))->render(self::WIDTH));
    }

    // ---- where you are -----------------------------------------------------------------

    public function testTheBranchComesFromGitHeadDirectly(): void
    {
        // Not from running `git`: this is re-read on nearly every frame, and a process
        // launch per frame is a lot to pay for a word in the corner.
        mkdir($this->cwd . '/.git', 0o755, true);
        file_put_contents($this->cwd . '/.git/HEAD', "ref: refs/heads/feature/x\n");

        $this->assertStringContainsString('(feature/x)', $this->lines($this->session())[0]);
    }

    public function testADetachedHeadIsNamedAsSuch(): void
    {
        mkdir($this->cwd . '/.git', 0o755, true);
        file_put_contents($this->cwd . '/.git/HEAD', "9fceb02d0ae598e95dc970b74767f19372d61af8\n");

        $this->assertStringContainsString('(detached)', $this->lines($this->session())[0]);
    }

    public function testOutsideARepositoryThereIsJustThePath(): void
    {
        $this->assertStringNotContainsString('(', $this->lines($this->session())[0]);
    }

    public function testACheckoutBetweenFramesIsNoticed(): void
    {
        mkdir($this->cwd . '/.git', 0o755, true);
        file_put_contents($this->cwd . '/.git/HEAD', "ref: refs/heads/main\n");

        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $this->assertStringContainsString('(main)', Ansi::strip($footer->render(self::WIDTH)[0]));

        file_put_contents($this->cwd . '/.git/HEAD', "ref: refs/heads/other\n");
        $footer->invalidate();

        $this->assertStringContainsString('(other)', Ansi::strip($footer->render(self::WIDTH)[0]));
    }

    public function testATooLongPathIsCutFromTheMiddle(): void
    {
        // The end of a path says more than its middle does.
        $footer = new FooterComponent($this->session(), $this->palette, '/' . str_repeat('directory/', 20) . 'here');
        $line = Ansi::strip($footer->render(40)[0]);

        $this->assertStringContainsString('...', $line);
        $this->assertStringEndsWith('here', $line);
        $this->assertLessThanOrEqual(40, mb_strwidth($line));
    }

    // ---- what it has cost ----------------------------------------------------------------

    public function testAPathInAWideScriptIsCutByColumnsNotCharacters(): void
    {
        // Two columns per character, so cutting by length overflows the line.
        $footer = new FooterComponent($this->session(), $this->palette, '/' . str_repeat('目录/', 20) . 'here');

        $this->assertLessThanOrEqual(40, mb_strwidth(Ansi::strip($footer->render(40)[0])));
    }

    public function testTokenCountsAreShortenedToFitACorner(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(950, 9_500, 95_000, 9_500_000));

        $line = $this->lines($session)[1];

        $this->assertStringContainsString('↑950', $line);
        $this->assertStringContainsString('↓9.5k', $line);
        $this->assertStringContainsString('R95k', $line);
        $this->assertStringContainsString('W9.5M', $line);
    }

    public function testACountOfZeroIsLeftOutRatherThanShownAsZero(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(100, 50));

        $this->assertStringNotContainsString('R0', $this->lines($session)[1]);
    }

    public function testTheContextIsTheLastTurnNotTheWholeSession(): void
    {
        // The percentage answers "will the next request fit", so it is what the last
        // complete turn carried — not the sum of every turn that has already gone.
        $session = $this->session();
        $this->spent($session, new Usage(50_000, 0, 0, 0));
        $this->spent($session, new Usage(60_000, 0, 0, 0));

        $this->assertStringContainsString('30.0%/200k', $this->lines($session)[1]);
    }

    public function testAnAbortedTurnIsNotWhatTheNextOneWillLookLike(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(60_000, 0, 0, 0));
        $this->spent($session, new Usage(1_000, 0, 0, 0), StopReason::Aborted);

        $this->assertStringContainsString('30.0%/200k', $this->lines($session)[1]);
    }

    public function testAutoCompactionSaysSoBesideThePercentage(): void
    {
        // The percentage means two different things depending on this: with auto-compaction on,
        // reaching the top is a pause and a summary, and with it off it is a request that gets
        // refused. The footer is the only place that says which, and the setting is readable
        // from anywhere — `/settings` can turn it off mid-session.
        $session = $this->session();
        $this->spent($session, new Usage(50_000, 0, 0, 0));

        $settings = Settings::inMemory();

        $footer = new FooterComponent($session, $this->palette, $this->cwd, $settings);
        $this->assertStringContainsString('25.0%/200k (auto)', Ansi::strip($footer->render(self::WIDTH)[1]));

        $settings->setCompactionEnabled(false);
        $this->assertStringNotContainsString('(auto)', Ansi::strip($footer->render(self::WIDTH)[1]));
    }

    public function testWithNoSettingsAtAllItIsOnBecauseThatIsTheDefault(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(50_000, 0, 0, 0));

        $this->assertStringContainsString('(auto)', $this->lines($session)[1]);
    }

    public function testASubscriptionSaysTheMoneyIsNotReal(): void
    {
        // Signed in rather than paying per token, so the number beside it is what the same
        // conversation *would have* cost. Without the marker a Max subscriber reads a bill.
        $session = $this->session();
        $this->spent($session, new Usage(100, 50, 0, 0, 150, new Cost(0.5, 0.25, 0, 0, 0.75)));

        $auth = Auth::inMemory();
        $auth->setCredentials(Provider::Anthropic, new Credentials('r', 'a', 0));

        $footer = new FooterComponent($session, $this->palette, $this->cwd, null, $auth);

        $this->assertStringContainsString('$0.750 (sub)', Ansi::strip($footer->render(self::WIDTH)[1]));
    }

    public function testASubscriptionIsSaidEvenBeforeAnythingIsSpent(): void
    {
        $auth = Auth::inMemory();
        $auth->setCredentials(Provider::Anthropic, new Credentials('r', 'a', 0));

        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd, null, $auth);

        // Upstream's condition is "cost or subscription", not "cost": the interesting fact on a
        // fresh screen is that this one is not being billed.
        $this->assertStringContainsString('$0.000 (sub)', Ansi::strip($footer->render(self::WIDTH)[1]));
    }

    public function testAStoredApiKeyIsNotASubscription(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(100, 50, 0, 0, 150, new Cost(0.5, 0.25, 0, 0, 0.75)));

        $auth = Auth::inMemory();
        $auth->setApiKey('anthropic', 'sk-test');

        $footer = new FooterComponent($session, $this->palette, $this->cwd, null, $auth);

        $this->assertStringNotContainsString('(sub)', Ansi::strip($footer->render(self::WIDTH)[1]));
    }

    public function testAFullContextIsColouredAndAnEmptyOneIsNot(): void
    {
        $quiet = $this->session();
        $this->spent($quiet, new Usage(20_000, 0, 0, 0));
        $this->assertStringNotContainsString("\e[38;2;204;102;102m", $this->raw($quiet));

        $warm = $this->session();
        $this->spent($warm, new Usage(150_000, 0, 0, 0));
        $this->assertStringContainsString("\e[38;2;255;255;0m", $this->raw($warm));

        $full = $this->session();
        $this->spent($full, new Usage(195_000, 0, 0, 0));
        $this->assertStringContainsString("\e[38;2;204;102;102m", $this->raw($full));
    }

    public function testTheCostIsShownToATenthOfACent(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(1, 1, 0, 0, 0, new Cost(total: 0.4213)));

        $this->assertStringContainsString('$0.421', $this->lines($session)[1]);
    }

    // ---- the model ---------------------------------------------------------------------------

    public function testTheModelSitsAtTheRightHandEnd(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(100, 100));

        $this->assertStringEndsWith('claude-test', $this->lines($session)[1]);
        // Measured in columns: the arrows in the token counts are three bytes each.
        $this->assertSame(self::WIDTH, mb_strwidth($this->lines($session)[1]));
    }

    public function testTheThinkingLevelIsShownOnlyWhenItIsOn(): void
    {
        $session = $this->session(true);
        $this->assertStringNotContainsString('•', $this->lines($session)[1]);

        $session->setThinkingLevel(ThinkingLevel::High);
        $this->assertStringContainsString('claude-test • high', $this->lines($session)[1]);
    }

    public function testAModelThatCannotThinkNeverShowsALevel(): void
    {
        $session = $this->session();
        $session->setThinkingLevel(ThinkingLevel::High);

        $this->assertStringNotContainsString('•', $this->lines($session)[1]);
    }

    public function testWithNoModelItSaysSoRatherThanBeingBlank(): void
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));

        $this->assertStringContainsString('no-model', $this->lines(new AgentSession($agent))[1]);
    }

    // ---- what a hook or a tool put there -----------------------------------------------

    public function testThereAreTwoLinesUntilSomethingHasStatusToReport(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);

        $this->assertCount(2, $footer->render(self::WIDTH));
    }

    public function testAStatusAddsAThirdLine(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('watcher', '3 files changed');

        $lines = array_map(Ansi::strip(...), $footer->render(self::WIDTH));

        $this->assertCount(3, $lines);
        $this->assertSame('3 files changed', $lines[2]);
    }

    /** Keyed, so a hook that updates its line replaces it rather than adding another. */
    public function testTheSameKeyReplacesRatherThanRepeats(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('watcher', '3 files changed');
        $footer->setStatus('watcher', '4 files changed');

        $lines = array_map(Ansi::strip(...), $footer->render(self::WIDTH));

        $this->assertCount(3, $lines);
        $this->assertSame('4 files changed', $lines[2]);
    }

    public function testTwoKeysShareTheLine(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('watcher', 'watching');
        $footer->setStatus('deploy', 'idle');

        $this->assertSame('watching · idle', array_map(Ansi::strip(...), $footer->render(self::WIDTH))[2]);
    }

    public function testClearingTheLastStatusTakesTheLineAway(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('watcher', 'watching');
        $footer->setStatus('watcher', null);

        $this->assertCount(2, $footer->render(self::WIDTH));
    }

    public function testAnEmptyStatusClearsItTheSameWay(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('watcher', 'watching');
        $footer->setStatus('watcher', '   ');

        $this->assertCount(2, $footer->render(self::WIDTH));
    }

    /** Two lines here would push the editor off the bottom of a fixed layout. */
    public function testNewlinesAreFlattenedRatherThanDrawn(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('noisy', "first\nsecond\tthird");

        $lines = array_map(Ansi::strip(...), $footer->render(self::WIDTH));

        $this->assertCount(3, $lines);
        $this->assertSame('first second third', $lines[2]);
    }

    public function testALongStatusIsCutToTheWidth(): void
    {
        $footer = new FooterComponent($this->session(), $this->palette, $this->cwd);
        $footer->setStatus('long', str_repeat('status ', 40));

        foreach ($footer->render(self::WIDTH) as $line) {
            $this->assertLessThanOrEqual(self::WIDTH, mb_strwidth(Ansi::strip($line)));
        }
    }

    public function testTheLineNeverOverflowsTheTerminal(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(999_999, 999_999, 999_999, 999_999, 0, new Cost(total: 123.456)));

        foreach ([20, 40, 76] as $width) {
            $footer = new FooterComponent($session, $this->palette, $this->cwd);

            foreach ($footer->render($width) as $line) {
                $this->assertLessThanOrEqual($width, mb_strwidth(Ansi::strip($line)), "at {$width}");
            }
        }
    }
}
