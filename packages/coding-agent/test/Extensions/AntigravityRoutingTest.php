<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use PigAntigravity\Routing;
use Pig\Ai\ProviderError;

/**
 * The three names one model choice has, and the table that has to agree with itself.
 *
 * The numbers here are the deployment's, taken from its catalogue. They are pinned rather than
 * recomputed because a regeneration that quietly changes which runtime id a level asks for is the
 * failure this cannot otherwise see: every request still succeeds, against a different model.
 */
final class AntigravityRoutingTest extends TestCase
{
    use LoadsAntigravity;

    #[\Override]
    protected function setUp(): void
    {
        self::loadAntigravity();
        // The built-in tables: another test may have installed a catalogue's.
        Routing::forgetTables();
    }

    public function testEveryRuntimeIdTheRoutingCanReachHasAnEnum(): void
    {
        // The invariant a regeneration breaks. Without it, a level nobody tested sends a request
        // with no model enum in it and the failure surfaces as the endpoint being unhelpful.
        foreach (Routing::models() as $model) {
            foreach ([null, 'off', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $level) {
                [$runtime, $enum] = Routing::resolve($model, $level);

                $this->assertNotSame('', $runtime, "{$model} at " . ($level ?? 'null'));
                $this->assertNotSame('', $enum, "{$model} at " . ($level ?? 'null'));
            }
        }
    }

    public function testTheCatalogueHasFourteenModels(): void
    {
        // Not a fact about the world — a fact about the last regeneration. If this fails, the
        // tables were refreshed and the expectations below are what to read next.
        $this->assertCount(14, Routing::models());
    }

    /** @return iterable<string, array{string, string|null, string, string}> */
    public static function resolutions(): iterable
    {
        yield 'the level picks a different model' => ['gemini-3.8-flash', 'medium', 'gemini-3.8-flash-medium', 'MODEL_PLACEHOLDER_M319'];
        yield 'and a different one again' => ['gemini-3.8-flash', 'high', 'gemini-3.8-flash-high', 'MODEL_PLACEHOLDER_M318'];
        yield 'off is its own target' => ['gemini-3.8-flash', 'off', 'gemini-3.8-flash-low', 'MODEL_PLACEHOLDER_M320'];
        yield 'null means off' => ['gemini-3.8-flash', null, 'gemini-3.8-flash-low', 'MODEL_PLACEHOLDER_M320'];
        yield 'xhigh can leave the family entirely' => ['gemini-3.1-pro', 'xhigh', 'gemini-pro-agent', 'MODEL_PLACEHOLDER_M16'];
        yield 'and high can too' => ['gemini-3.5-flash', 'high', 'gemini-3-flash-agent', 'MODEL_PLACEHOLDER_M84'];
        yield 'a model with one target for everything' => ['gemini-3-flash', 'medium', 'gemini-3-flash', 'MODEL_PLACEHOLDER_M18'];
        yield 'gpt-oss goes one place whatever is asked' => ['gpt-oss-120b', 'high', 'gpt-oss-120b-medium', 'MODEL_OPENAI_GPT_OSS_120B_MEDIUM'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resolutions')]
    public function testResolvesToTheCataloguesOwnAnswer(string $model, ?string $level, string $runtime, string $enum): void
    {
        $this->assertSame([$runtime, $enum], Routing::resolve($model, $level));
    }

    public function testAModelThatCannotBeTurnedOffFallsToItsDefaultTarget(): void
    {
        // `claude-opus-4-6` and `gpt-oss-120b` have no `off` in the catalogue, which is the
        // catalogue agreeing with its own `thinkingLevelMap` — both say `off` is not available.
        // Asking anyway is a floor, not a path: the picker will not offer it.
        $this->assertSame(['claude-opus-4-6-thinking', 'MODEL_PLACEHOLDER_M26'], Routing::resolve('claude-opus-4-6', 'off'));
        $this->assertSame(['gpt-oss-120b-medium', 'MODEL_OPENAI_GPT_OSS_120B_MEDIUM'], Routing::resolve('gpt-oss-120b', null));
    }

    public function testALevelTheModelHasNoTargetForFallsToItsDefault(): void
    {
        // `gpt-oss-120b` has no `xhigh` row. Falling to the default is the catalogue's own
        // `defaultRequestId` doing its job.
        $this->assertSame(['gpt-oss-120b-medium', 'MODEL_OPENAI_GPT_OSS_120B_MEDIUM'], Routing::resolve('gpt-oss-120b', 'xhigh'));
    }

    public function testAnUnknownModelIsRefusedRatherThanQuietlySwappedForAnother(): void
    {
        // The implementation this was ported from ends its chain with "otherwise Gemini 3.8
        // Flash". That is right for a gateway and wrong here: pig's registry is built from these
        // tables, so a miss is a bug in pig, and serving it a different model than the one on
        // screen is how that bug stays invisible.
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessageMatches('/no model .gemini-9\.9-nope./');

        Routing::resolve('gemini-9.9-nope', 'low');
    }

    public function testAKnownModelIsNotRefused(): void
    {
        $this->assertTrue(Routing::knows('gemini-3.8-flash'));
        $this->assertFalse(Routing::knows('gemini-3.8-flash-medium'), 'a runtime id is not a model anyone may choose');
    }

    // ---- how much thinking to ask for -----------------------------------------------------

    /** @return iterable<string, array{string, string|null, int}> */
    public static function budgets(): iterable
    {
        yield 'off is nothing, whatever the model' => ['gemini-3.8-flash-high', 'off', 0];
        yield 'and so is a null level' => ['claude-opus-4-6-thinking', null, 0];
        yield 'claude is a flat figure' => ['claude-opus-4-6-thinking', 'high', 1024];
        yield 'so is gpt-oss' => ['gpt-oss-120b-medium', 'low', 8192];
        yield 'gemini at the top is uncapped' => ['gemini-3.8-flash-high', 'high', -1];
        yield 'gemini in the middle' => ['gemini-3.8-flash-medium', 'medium', 4000];
        yield 'gemini at the bottom' => ['gemini-3.8-flash-low', 'low', 1000];
        // The 3.5 family is capped rather than uncapped at the top, and `-agent` counts as 3.5.
        yield '3.5 is capped at the top' => ['gemini-3.5-flash-low', 'high', 10_000];
        yield 'the agent variant is 3.5 too' => ['gemini-3-flash-agent', 'xhigh', 10_000];
        // One more than the Flash figures, in the original too.
        yield 'pro is one more than flash' => ['gemini-3.1-pro-low', 'high', 10_001];
        yield 'and one more at the bottom' => ['gemini-3.1-pro-low', 'low', 1_001];
        yield 'the pro agent is pro' => ['gemini-pro-agent', 'xhigh', 10_001];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('budgets')]
    public function testTheBudgetIsTheOneTheWorkingImplementationSends(string $runtime, ?string $level, int $budget): void
    {
        $this->assertSame($budget, Routing::thinkingBudget($runtime, $level));
    }

    public function testAnUnrecognisedFamilyAsksForNoThinking(): void
    {
        // Rather than guessing a figure for a model whose family this has never seen.
        $this->assertSame(0, Routing::thinkingBudget('something-else-entirely', 'high'));
    }
}
