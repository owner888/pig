<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\CodingAgent\ModelResolver;

/** Turning what someone typed into a model. */
final class ModelResolverTest extends TestCase
{
    public function testAnExactIdIsTakenAsItIs(): void
    {
        $this->assertSame('claude-sonnet-4-5-20250929', ModelResolver::parse('claude-sonnet-4-5-20250929')?->model->id);
    }

    public function testCaseDoesNotMatter(): void
    {
        $this->assertSame('claude-sonnet-4-5', ModelResolver::parse('CLAUDE-SONNET-4-5')?->model->id);
    }

    public function testAPartOfTheNameIsEnough(): void
    {
        // Otherwise `--model` is a list of twenty-one ids nobody remembers.
        $this->assertSame('claude-sonnet-4-5', ModelResolver::parse('sonnet')?->model->id);
        $this->assertSame('claude-haiku-4-5', ModelResolver::parse('haiku')?->model->id);
        $this->assertSame('claude-opus-4-5', ModelResolver::parse('opus')?->model->id);
    }

    public function testTheAliasWinsOverTheDatedBuildBehindIt(): void
    {
        // Someone who types `sonnet` wants the current one, not the June 2024 build that
        // happens to sort first.
        $this->assertSame('claude-sonnet-4-5', ModelResolver::parse('sonnet')?->model->id);
    }

    public function testWithNoAliasTheNewestDatedBuildWins(): void
    {
        // Every Opus 4.1 match: the alias `claude-opus-4-1` and the dated build.
        $this->assertSame('claude-opus-4-1', ModelResolver::parse('opus-4-1')?->model->id);

        // Nothing but dated builds matches this one.
        $this->assertSame('claude-3-5-sonnet-20241022', ModelResolver::parse('3-5-sonnet')?->model->id);
    }

    public function testTheDisplayNameMatchesToo(): void
    {
        $this->assertSame('claude-opus-4-5', ModelResolver::parse('Opus 4.5')?->model->id);
    }

    public function testProviderSlashIdIsUnderstood(): void
    {
        $this->assertSame('claude-haiku-4-5', ModelResolver::parse('anthropic/claude-haiku-4-5')?->model->id);
    }

    public function testNothingMatchingIsNullRatherThanAGuess(): void
    {
        // Falling back to "something close enough" would spend the person's money on a
        // model they did not ask for.
        $this->assertNull(ModelResolver::parse('llama-9'));
        $this->assertNull(ModelResolver::parse(''));
    }

    // ---- the thinking suffix ------------------------------------------------------------

    public function testAColonSetsTheThinkingLevelInTheSameBreath(): void
    {
        $choice = ModelResolver::parse('sonnet:high');

        $this->assertSame('claude-sonnet-4-5', $choice?->model->id);
        $this->assertSame(ThinkingLevel::High, $choice?->thinking);
        $this->assertNull($choice?->warning);
    }

    public function testWithNoSuffixThinkingIsOff(): void
    {
        $this->assertSame(ThinkingLevel::Off, ModelResolver::parse('sonnet')?->thinking);
    }

    public function testASuffixThatIsNotALevelIsSaidRatherThanGuessedAt(): void
    {
        $choice = ModelResolver::parse('opus:hard');

        $this->assertSame('claude-opus-4-5', $choice?->model->id);
        $this->assertSame(ThinkingLevel::Off, $choice?->thinking);
        $this->assertStringContainsString('No thinking level called "hard"', (string) $choice?->warning);
    }

    public function testAnIdIsTriedWholeBeforeItIsSplitOnAColon(): void
    {
        // OpenRouter ids carry their own colons — `model:exacto` — so splitting first
        // would break every one of them the day that provider arrives.
        $choice = ModelResolver::parse('claude-sonnet-4-5');

        $this->assertSame('claude-sonnet-4-5', $choice?->model->id);
        $this->assertSame(ThinkingLevel::Off, $choice?->thinking);
    }

    public function testASuffixOnSomethingThatMatchesNothingIsStillNothing(): void
    {
        $this->assertNull(ModelResolver::parse('llama-9:high'));
    }

    // ---- an id two providers claim ------------------------------------------------------

    public function testABareIdMeansTheDirectProviderNotTheReseller(): void
    {
        // Copilot serves `gpt-5` under OpenAI's own name, so this had one answer and now has
        // two. The rule is `Models::RESOLD` and it lives in one place for both readers.
        $this->assertSame('openai', ModelResolver::parse('gpt-5')?->model->provider);
        $this->assertSame('google', ModelResolver::parse('gemini-2.5-pro')?->model->provider);
    }

    public function testTheResellerIsReachedByNamingIt(): void
    {
        $choice = ModelResolver::parse('github-copilot/gpt-5');

        $this->assertSame('github-copilot', $choice?->model->provider);
        $this->assertSame('gpt-5', $choice?->model->id);
    }

    public function testNamingTheResellerStillTakesAThinkingLevel(): void
    {
        $choice = ModelResolver::parse('github-copilot/gpt-5:high');

        $this->assertSame('github-copilot', $choice?->model->provider);
        $this->assertSame(ThinkingLevel::High, $choice?->thinking);
    }

    public function testAnIdOnlyTheResellerHasStillResolves(): void
    {
        $this->assertSame('github-copilot', ModelResolver::parse('oswe-vscode-prime')?->model->provider);
    }

    public function testASubstringMatchingBothPrefersTheDirectProvider(): void
    {
        // `codex` is in OpenAI's ids and in Copilot's. The one an API key reaches wins.
        $this->assertSame('openai', ModelResolver::parse('codex')?->model->provider);
    }
}
