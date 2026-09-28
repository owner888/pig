<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Models;
use Pig\CodingAgent\ModelResolver;
use Pig\Test\ProbeModels;

/**
 * Turning what someone typed into a model.
 *
 * **Against models this test owns, not against the catalogue.** Every case here used to name a
 * live id — `sonnet` resolves to `claude-sonnet-4-5`, `opus-4-1` resolves at all, Copilot resells
 * `gpt-5` — and the first regeneration of the registry from models.dev turned eleven of them red
 * with nothing wrong in the resolver: Anthropic had shipped a `claude-sonnet-5`, retired Opus 4.1,
 * and Copilot's catalogue had moved on. None of that is a fact about `ModelResolver`. The rules
 * are — an alias beats the dated build behind it, a bare id means the direct provider, a colon
 * sets the level — so `Pig\Test\ProbeModels` supplies a family to test them on and the rules stop
 * having an expiry date.
 *
 * What stays on the real table is the one thing that is about the real table: that a pattern
 * matching nothing is a warning and not a guess.
 */
final class ModelResolverTest extends TestCase
{
    use ProbeModels;

    #[\Override]
    protected function setUp(): void
    {
        self::registerProbeModels();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // `Models::register()` is static, so a registration that outlives its test is a model
        // the next one can see.
        Models::forgetRegistered();
    }

    public function testAnExactIdIsTakenAsItIs(): void
    {
        $this->assertSame('zzp-alpha-20250101', ModelResolver::parse('zzp-alpha-20250101')?->model->id);
    }

    public function testCaseDoesNotMatter(): void
    {
        $this->assertSame('zzp-alpha', ModelResolver::parse('ZZP-ALPHA')?->model->id);
    }

    public function testAPartOfTheNameIsEnough(): void
    {
        // Otherwise `--model` is a list of ids nobody remembers.
        $this->assertSame('zzp-alpha', ModelResolver::parse('alpha')?->model->id);
        $this->assertSame('zzp-plain', ModelResolver::parse('plain')?->model->id);
    }

    public function testTheAliasWinsOverTheDatedBuildsBehindIt(): void
    {
        // Someone who types the family name wants the current one, not whichever dated build
        // happens to sort first.
        $this->assertSame('zzp-alpha', ModelResolver::parse('zzp-alpha')?->model->id);
    }

    public function testWithNoAliasTheNewestDatedBuildWins(): void
    {
        // Nothing but dated builds matches this one, so the newest is the answer.
        $this->assertSame('zzp-beta-20250101', ModelResolver::parse('zzp-beta')?->model->id);
    }

    public function testTheDisplayNameMatchesToo(): void
    {
        $this->assertSame('zzp-alpha', ModelResolver::parse('Probe Alpha')?->model->id);
    }

    public function testProviderSlashIdIsUnderstood(): void
    {
        $this->assertSame('zzp-plain', ModelResolver::parse('anthropic/zzp-plain')?->model->id);
    }

    public function testNothingMatchingIsNullRatherThanAGuess(): void
    {
        // Falling back to "something close enough" would spend the person's money on a
        // model they did not ask for.
        $this->assertNull(ModelResolver::parse('no-such-model-anywhere'));
        $this->assertNull(ModelResolver::parse(''));
    }

    // ---- the thinking suffix ------------------------------------------------------------

    public function testAColonSetsTheThinkingLevelInTheSameBreath(): void
    {
        $choice = ModelResolver::parse('zzp-alpha:high');

        $this->assertSame('zzp-alpha', $choice?->model->id);
        $this->assertSame(ThinkingLevel::High, $choice?->thinking);
        $this->assertNull($choice?->warning);
    }

    public function testWithNoSuffixThinkingIsOff(): void
    {
        $this->assertSame(ThinkingLevel::Off, ModelResolver::parse('zzp-alpha')?->thinking);
    }

    public function testASuffixThatIsNotALevelIsSaidRatherThanGuessedAt(): void
    {
        $choice = ModelResolver::parse('zzp-alpha:hard');

        $this->assertSame('zzp-alpha', $choice?->model->id);
        $this->assertSame(ThinkingLevel::Off, $choice?->thinking);
        $this->assertStringContainsString('No thinking level called "hard"', (string) $choice?->warning);
    }

    public function testAnIdIsTriedWholeBeforeItIsSplitOnAColon(): void
    {
        // OpenRouter ids carry their own colons — `model:exacto` — so splitting first
        // would break every one of them the day that provider arrives.
        $choice = ModelResolver::parse('zzp-alpha-20250101');

        $this->assertSame('zzp-alpha-20250101', $choice?->model->id);
        $this->assertSame(ThinkingLevel::Off, $choice?->thinking);
    }

    public function testASuffixOnSomethingThatMatchesNothingIsStillNothing(): void
    {
        $this->assertNull(ModelResolver::parse('no-such-model-anywhere:high'));
    }

    // ---- an id two providers claim ------------------------------------------------------

    public function testABareIdMeansTheDirectProviderNotTheReseller(): void
    {
        // A reseller serves other companies' models under their own names, so a bare id had one
        // answer and now has two. The rule is `Models::RESOLD` and it lives in one place for
        // both readers.
        $this->assertSame('openai', ModelResolver::parse('zzp-shared')?->model->provider);
    }

    public function testTheResellerIsReachedByNamingIt(): void
    {
        $choice = ModelResolver::parse('github-copilot/zzp-shared');

        $this->assertSame('github-copilot', $choice?->model->provider);
        $this->assertSame('zzp-shared', $choice?->model->id);
    }

    public function testNamingTheResellerStillTakesAThinkingLevel(): void
    {
        $choice = ModelResolver::parse('github-copilot/zzp-shared:high');

        $this->assertSame('github-copilot', $choice?->model->provider);
        $this->assertSame(ThinkingLevel::High, $choice?->thinking);
    }

    public function testAnIdOnlyTheResellerHasStillResolves(): void
    {
        // A resold id is never the first answer, but it is still an answer.
        $this->assertSame('github-copilot', ModelResolver::parse('zzp-only')?->model->provider);
    }

    public function testASubstringMatchingBothPrefersTheDirectProvider(): void
    {
        // `shared` is in both providers' ids. The one an API key reaches wins.
        $this->assertSame('openai', ModelResolver::parse('shared')?->model->provider);
    }

    // ---- the next one along -------------------------------------------------------------

    public function testTheNextOneAlongWrapsRoundAtTheEnd(): void
    {
        [$one, $two, $three] = self::probeThree();
        $list = [$one, $two, $three];

        $this->assertSame($two, ModelResolver::next($list, $one));
        $this->assertSame($three, ModelResolver::next($list, $two));
        $this->assertSame($one, ModelResolver::next($list, $three), 'round the end');
    }

    public function testBackwardsWrapsRoundAtTheStart(): void
    {
        [$one, $two, $three] = self::probeThree();
        $list = [$one, $two, $three];

        $this->assertSame($one, ModelResolver::next($list, $two, backward: true));
        $this->assertSame($three, ModelResolver::next($list, $one, backward: true), 'round the start');
    }

    public function testOneModelHasNowhereToGoAndNeitherHasNone(): void
    {
        [$one] = self::probeThree();

        // Which is a real state, not a guard against nothing: a machine with one provider key.
        $this->assertNull(ModelResolver::next([$one], $one));
        $this->assertNull(ModelResolver::next([], null));
    }

    public function testAModelThatIsNotOnTheListCountsAsBeingAtTheStart(): void
    {
        [$one, $two, $three] = self::probeThree();

        // Upstream's `indexOf` answering -1, kept: `--model` pinned to a provider whose key has
        // since gone is how somebody gets here. It counts as being at position 0, so the next one
        // along is the **second** on the list rather than the first.
        $this->assertSame($three, ModelResolver::next([$two, $three], $one));
    }

    // ---- the scope `--models` sets ----------------------------------------------------------

    public function testEachPatternResolvesTheWayModelWouldAndTheOrderIsKept(): void
    {
        [$scope, $warnings] = ModelResolver::scope(['plain', 'zzp-beta'], Models::all());

        $this->assertSame([], $warnings);
        $this->assertSame(['zzp-plain', 'zzp-beta-20250101'], array_map(
            static fn (object $choice): string => $choice->model->id,
            $scope,
        ));
    }

    public function testAGlobTakesEveryModelItMatches(): void
    {
        [$scope, $warnings] = ModelResolver::scope(['anthropic/zzp-alpha*'], Models::all());

        $this->assertSame([], $warnings);
        $this->assertGreaterThan(1, count($scope));

        foreach ($scope as $choice) {
            $this->assertSame('anthropic', $choice->model->provider);
            $this->assertStringStartsWith('zzp-alpha', $choice->model->id);
        }
    }

    public function testAGlobMatchesTheBareIdAsWellAsProviderSlashId(): void
    {
        // So `*alpha*` works without anybody having to write `anthropic/*alpha*`, which is
        // `minimatch`'s two attempts upstream.
        [$scope] = ModelResolver::scope(['*zzp-plain*'], Models::all());

        $this->assertNotSame([], $scope);
        $this->assertContains('zzp-plain', array_map(
            static fn (object $choice): string => $choice->model->id,
            $scope,
        ));
    }

    public function testAThinkingLevelOnAGlobReachesEveryModelItMatched(): void
    {
        [$scope] = ModelResolver::scope(['anthropic/zzp-alpha*:high'], Models::all());

        $this->assertNotSame([], $scope);

        foreach ($scope as $choice) {
            $this->assertSame(ThinkingLevel::High, $choice->thinking);
        }
    }

    public function testASuffixThatIsNoLevelStaysPartOfTheGlob(): void
    {
        // An id can hold a colon — OpenRouter's `:exacto` — so a suffix is only stripped when it
        // is a level. `zzp-*:nonsense` is therefore a pattern matching nothing, not a pattern
        // for `zzp-*` with a bad level tacked on.
        [$scope, $warnings] = ModelResolver::scope(['anthropic/zzp-*:nonsense'], Models::all());

        $this->assertSame([], $scope);
        $this->assertSame(['No model matches "anthropic/zzp-*:nonsense".'], $warnings);
    }

    public function testTheSameModelReachedTwoWaysIsInTheScopeOnce(): void
    {
        [$scope] = ModelResolver::scope(['zzp-plain', 'anthropic/zzp-plain'], Models::all());

        $this->assertCount(1, $scope);
    }

    public function testOneTypoCostsItsOwnPatternAndNotTheRest(): void
    {
        [$scope, $warnings] = ModelResolver::scope(['plain', 'no-such-model-anywhere'], Models::all());

        // A session is still worth having: the other pattern is a scope, and the warning names
        // the one that found nothing.
        $this->assertCount(1, $scope);
        $this->assertSame('zzp-plain', $scope[0]->model->id);
        $this->assertSame(['No model matches "no-such-model-anywhere".'], $warnings);
    }
}
