<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\Model;

/**
 * Which levels a model has, and what happens when it does not have the one you asked for.
 *
 * Upstream's `getSupportedThinkingLevels` and `clampThinkingLevel`. Both used to be answered by a
 * hardcoded list here, which was the same answer only while every reasoning model had every
 * level.
 */
final class ThinkingLevelTest extends TestCase
{
    public function testAModelThatCannotReasonHasExactlyOneLevelAndItIsOff(): void
    {
        // Not the empty list: "one level, and it is off" is a different statement from "no
        // levels at all", and `AgentSession` keeps the second for itself.
        $this->assertSame([ThinkingLevel::Off], ThinkingLevel::supportedBy($this->model(reasoning: false)));
    }

    public function testAModelWithNoMapHasEverythingExceptXhigh(): void
    {
        $this->assertSame(
            [ThinkingLevel::Off, ThinkingLevel::Minimal, ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High],
            ThinkingLevel::supportedBy($this->model()),
        );
    }

    public function testXhighHasToBeSaidOutLoud(): void
    {
        $levels = ThinkingLevel::supportedBy($this->model(map: ['xhigh' => 'xhigh']));

        $this->assertContains(ThinkingLevel::Xhigh, $levels);
        $this->assertNotContains(ThinkingLevel::Xhigh, ThinkingLevel::supportedBy($this->model()));
    }

    public function testCopilotsGptSixOffersXhighAndClampsByItsMap(): void
    {
        // The built-in rows carry upstream's generator maps now: Copilot's GPT-6 Sol has `xhigh`,
        // no `minimal` and an `off` it calls `none`, so the picker offers xhigh and a request for
        // minimal comes up to low. Before, the row had no map and the picker had no xhigh for it.
        $model = \Pig\Ai\Models::find('github-copilot', 'gpt-6-sol');
        $this->assertNotNull($model);

        $this->assertSame(
            [ThinkingLevel::Off, ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High, ThinkingLevel::Xhigh],
            ThinkingLevel::supportedBy($model),
        );
        $this->assertSame(ThinkingLevel::Low, ThinkingLevel::clampedFor($model, ThinkingLevel::Minimal));

        // And gpt-5, which cannot be switched off, is not offered off.
        $gpt5 = \Pig\Ai\Models::find('openai', 'gpt-5');
        $this->assertNotNull($gpt5);
        $this->assertSame(ThinkingLevel::Minimal, ThinkingLevel::clampedFor($gpt5, ThinkingLevel::Off));
    }

    public function testEveryLevelButOffReachesTheProviderUnderItsOwnName(): void
    {
        $this->assertNull(ThinkingLevel::Off->toReasoning());

        foreach ([ThinkingLevel::Minimal, ThinkingLevel::Low, ThinkingLevel::Medium, ThinkingLevel::High, ThinkingLevel::Xhigh] as $level) {
            $this->assertSame($level->value, $level->toReasoning()?->value);
        }
    }

    public function testALevelTheMapNullsIsNotOffered(): void
    {
        $levels = ThinkingLevel::supportedBy($this->model(map: ['off' => null, 'minimal' => null]));

        $this->assertNotContains(ThinkingLevel::Off, $levels);
        $this->assertNotContains(ThinkingLevel::Minimal, $levels);
        $this->assertContains(ThinkingLevel::Low, $levels);
    }

    public function testTheOrderIsTheEnumsAndNotTheMapsButTheLevelsAreStillOrdered(): void
    {
        // A map written in any order must not reorder the menu: these are a scale, and a picker
        // that lists them out of order is a picker nobody can use by muscle memory.
        $levels = ThinkingLevel::supportedBy($this->model(map: ['xhigh' => 'x', 'off' => 'none']));

        $this->assertSame(ThinkingLevel::cases(), $levels);
    }

    // ---- clamping -------------------------------------------------------------------------

    public function testALevelTheModelHasIsLeftAlone(): void
    {
        $this->assertSame(
            ThinkingLevel::Medium,
            ThinkingLevel::clampedFor($this->model(), ThinkingLevel::Medium),
        );
    }

    public function testAMissingLevelGoesUpBeforeItGoesDown(): void
    {
        // Upstream's order, and the argument for it: a model that will not do `medium` is far
        // likelier to be one that insists on thinking hard than one that cannot think.
        $model = $this->model(map: ['medium' => null]);

        $this->assertSame(ThinkingLevel::High, ThinkingLevel::clampedFor($model, ThinkingLevel::Medium));
    }

    public function testItComesBackDownWhenThereIsNothingAbove(): void
    {
        $model = $this->model(map: ['xhigh' => null]);

        $this->assertSame(ThinkingLevel::High, ThinkingLevel::clampedFor($model, ThinkingLevel::Xhigh));
    }

    public function testAModelThatAlwaysThinksCannotBeTurnedOff(): void
    {
        // The Antigravity flash models are exactly this shape: `off` is null, so asking for off
        // lands on the cheapest level they do have rather than pretending thinking is off.
        $model = $this->model(map: ['off' => null, 'minimal' => null]);

        $this->assertSame(ThinkingLevel::Low, ThinkingLevel::clampedFor($model, ThinkingLevel::Off));
    }

    public function testAModelThatCannotReasonClampsEverythingToOff(): void
    {
        $model = $this->model(reasoning: false);

        $this->assertSame(ThinkingLevel::Off, ThinkingLevel::clampedFor($model, ThinkingLevel::High));
    }

    /** @param array<string, string|null> $map */
    private function model(array $map = [], bool $reasoning = true): Model
    {
        return new Model(
            'test-model',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'https://example.invalid',
            200_000,
            64_000,
            reasoning: $reasoning,
            thinkingLevelMap: $map,
        );
    }
}
