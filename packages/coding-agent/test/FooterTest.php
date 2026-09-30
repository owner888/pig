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
use Pig\CodingAgent\ModelChoice;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Palette;
use Pig\Test\WithoutProviderKeys;
use Pig\Tui\Ansi;

/** The two dim lines under everything: where you are, and what this has cost. */
final class FooterTest extends TestCase
{
    use WithoutProviderKeys;

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

    public function testTheSessionNameIsShownBesideTheBranchIfSet(): void
    {
        mkdir($this->cwd . '/.git', 0o755, true);
        file_put_contents($this->cwd . '/.git/HEAD', "ref: refs/heads/main\n");

        $session = $this->session();
        $session->setSessionName('仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms');

        $footer = new FooterComponent($session, $this->palette, $this->cwd);
        $topLine = Ansi::strip($footer->render(160)[0]);

        $this->assertStringContainsString('(main) • 仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms', $topLine);
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
        $this->assertStringContainsString('CH1.0%', $line);
    }

    public function testCacheHitRateIsFormattedAsCHPercentage(): void
    {
        $session = $this->session();
        // 9 input tokens + 991 cache read tokens = 1000 prompt tokens -> 99.1% hit rate
        $this->spent($session, new Usage(9, 100, 991, 0));

        $line = $this->lines($session)[1];

        $this->assertStringContainsString('CH99.1%', $line);
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

    public function testAThinkingModelWithThinkingOffSaysSoRatherThanNothing(): void
    {
        // Upstream's wording, and the more useful of the two: an empty corner cannot tell you
        // whether the model cannot think or is not being asked to.
        $session = $this->session(true);
        $this->assertStringContainsString('claude-test • thinking off', $this->lines($session)[1]);

        $session->setThinkingLevel(ThinkingLevel::High);
        $this->assertStringContainsString('claude-test • high', $this->lines($session)[1]);
    }

    public function testAModelThatCannotThinkNeverShowsALevel(): void
    {
        $session = $this->session();
        $session->setThinkingLevel(ThinkingLevel::High);

        $this->assertStringNotContainsString('•', $this->lines($session)[1]);
    }

    public function testTheProviderIsNamedWhenThereIsMoreThanOneToTellApart(): void
    {
        $session = $this->session();
        $this->spent($session, new Usage(100, 100));

        $this->assertStringEndsWith('(anthropic) claude-test', $this->lines($session)[1]);
    }

    public function testWithOneProviderOnOfferThePrefixWouldSayNothingAndIsLeftOut(): void
    {
        // `--models` narrows what is on offer, and narrowed to one provider the prefix cannot
        // distinguish anything — it is just three columns of noise in a corner that is short of
        // them. Upstream's condition, not an optimisation.
        $agent = new Agent(new AgentOptions(apiKey: 'k'));
        $model = new Model('claude-test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, false);
        $agent->setModel($model);
        $session = new AgentSession($agent, modelScope: [new ModelChoice($model)]);
        $this->spent($session, new Usage(100, 100));

        $line = $this->lines($session)[1];

        $this->assertStringEndsWith('claude-test', $line);
        $this->assertStringNotContainsString('(anthropic)', $line);
    }

    public function testWhenThePrefixWouldNotFitTheModelIdIsWhatSurvives(): void
    {
        // Dropped whole rather than cut: half a provider name in parentheses reads as a
        // different provider, and the id is the part you steer by.
        $session = $this->session();
        $this->spent($session, new Usage(999_999, 999_999, 999_999, 999_999, 0, new Cost(total: 123.456)));

        // Wide enough for `claude-test` but not for `(anthropic) claude-test`. Narrower than
        // this and the right-hand side goes entirely, which is a different branch.
        $line = Ansi::strip((new FooterComponent($session, $this->palette, $this->cwd))->render(81)[1]);

        $this->assertStringNotContainsString('(anthropic', $line);
        $this->assertStringEndsWith('claude-test', $line);
    }

    public function testASecondProviderSignedIntoMidSessionBringsThePrefixOut(): void
    {
        // The count is memoised, because this line is drawn twelve times a second. `invalidate()`
        // is what has to clear it — without that, signing in to a second provider leaves the
        // corner claiming there is still only one until the next run.
        $this->forgetProviderKeys();

        try {
            $auth = new Auth($this->cwd . '/auth.json');
            $auth->setRuntimeApiKey('anthropic', 'k');

            $session = $this->session();
            $footer = new FooterComponent($session, $this->palette, $this->cwd, null, $auth);
            $this->spent($session, new Usage(100, 100));

            $this->assertStringNotContainsString('(anthropic)', Ansi::strip($footer->render(self::WIDTH)[1]));

            $auth->setRuntimeApiKey('openai', 'k');
            $footer->invalidate();

            $this->assertStringEndsWith('(anthropic) claude-test', Ansi::strip($footer->render(self::WIDTH)[1]));
        } finally {
            $this->restoreProviderKeys();
        }
    }

    public function testWithNoModelItSaysSoRatherThanBeingBlank(): void
    {
        $agent = new Agent(new AgentOptions(apiKey: 'k'));

        // An `AgentState` fills in a default model, so a footer with none to name is one whose
        // model was lost — the registry no longer carrying the default it asked for.
        $agent->state->model = null;

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

    // ---- cutting a line that is too long -----------------------------------------------

    public function testNothingIsEverCutInsideACharacter(): void
    {
        // Three places here cut a line to fit and all three counted bytes. A cut that lands
        // between the bytes of one character hands the terminal a fragment that is not text in
        // any encoding, which is the one thing every other display path in this repository
        // sanitises against — and this one *made* it rather than passing it on.
        $session = $this->session();
        $this->spent($session, new Usage(999_999, 999_999, 999_999, 999_999, 0, new Cost(total: 123.456)));

        $footer = new FooterComponent($session, $this->palette, $this->cwd);
        $footer->setStatus('long', str_repeat('构建中', 60));

        // Every width, because where the cut lands moves one byte at a time and only some of
        // those bytes are a character boundary.
        for ($width = 8; $width <= 80; $width++) {
            foreach ($footer->render($width) as $line) {
                $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "at width {$width}");
                $this->assertLessThanOrEqual($width, mb_strwidth(Ansi::strip($line)), "at width {$width}");
            }
        }
    }

    public function testAModelNamedInAWideScriptStillFitsBesideTheCounts(): void
    {
        // The right-hand end is cut to what is left over, and the gap before it was sized with
        // `strlen()` — so a name that is not ASCII made this line *wider* than the terminal,
        // which the renderer refuses to draw at all rather than wrapping.
        // The **id** is what the corner shows, and a `models.json` declaring a local endpoint
        // can name a model anything at all — which is the only way a provider's id gets here in
        // a script where one character is not one byte, and it is a file people write by hand.
        $agent = new Agent(new AgentOptions(apiKey: 'k'));
        $agent->setModel(new Model('通义千问-长名字模型', '通义千问', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, false));
        $session = new AgentSession($agent);
        $this->spent($session, new Usage(999_999, 999_999, 999_999, 999_999, 0, new Cost(total: 123.456)));

        // Wide enough that there is room left for the name after the counts, and then one
        // column at a time through it: the cut only lands inside a character at some of them.
        for ($width = 58; $width <= 96; $width++) {
            foreach ((new FooterComponent($session, $this->palette, $this->cwd))->render($width) as $line) {
                $plain = Ansi::strip($line);

                $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "at width {$width}");
                $this->assertLessThanOrEqual($width, mb_strwidth($plain), "at width {$width}");

                // The name is right-aligned, so whenever any of it is on the line the line
                // reaches the right edge. Sizing the gap in bytes leaves it short instead,
                // which no assertion about overflowing can see.
                if (str_contains($plain, '通')) {
                    $this->assertSame($width, mb_strwidth($plain), "at width {$width}");
                }
            }
        }
    }
}
