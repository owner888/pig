<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Usage;

final class ModelTest extends TestCase
{
    public function testPricesUsageAtDollarsPerMillionTokens(): void
    {
        $model = $this->model(new Pricing(input: 3.0, output: 15.0, cacheRead: 0.3, cacheWrite: 3.75));
        $usage = new Usage(input: 1_000_000, output: 500_000, cacheRead: 2_000_000, cacheWrite: 400_000);

        $priced = $usage->withCost($model);

        $this->assertSame(3.0, $priced->cost->input);
        $this->assertSame(7.5, $priced->cost->output);
        $this->assertSame(0.6, $priced->cost->cacheRead);
        $this->assertSame(1.5, $priced->cost->cacheWrite);
        $this->assertSame(12.6, $priced->cost->total);

        // Token counts pass through untouched.
        $this->assertSame(1_000_000, $priced->input);
    }

    public function testAFreeModelCostsNothing(): void
    {
        $priced = (new Usage(input: 999, output: 999))->withCost($this->model(new Pricing()));

        $this->assertSame(0.0, $priced->cost->total);
    }

    public function testTotalTokensIsTheSumOfTheParts(): void
    {
        $usage = (new Usage(input: 10, output: 20, cacheRead: 30, cacheWrite: 40))->withTotalTokens();

        $this->assertSame(100, $usage->totalTokens);
    }

    public function testCapabilitiesComeFromTheModel(): void
    {
        $this->assertFalse($this->model(new Pricing())->acceptsImages());
        $this->assertTrue($this->model(new Pricing(), ['text', 'image'])->acceptsImages());
        $this->assertFalse($this->model(new Pricing())->supportsXhigh());
    }

    /** @param list<'text'|'image'> $input */
    // ---- what a model calls each thinking level -------------------------------------------

    /**
     * The three states, which are the whole of this field.
     *
     * A key that is absent and a key whose value is null are *different*, and every operator
     * that would be natural to reach for here — `??`, `isset()`, `empty()` — flattens them into
     * each other. Only `array_key_exists()` tells them apart.
     */
    public function testALevelTheMapDoesNotMentionSendsItsOwnName(): void
    {
        $model = $this->thinking(['high' => 'max']);

        $this->assertSame('medium', $model->thinkingEffort('medium'));
        $this->assertTrue($model->hasThinkingLevel('medium'));
    }

    public function testALevelTheMapRenamesSendsTheNewName(): void
    {
        $this->assertSame('max', $this->thinking(['high' => 'max'])->thinkingEffort('high'));
    }

    public function testALevelTheMapNullsIsOneTheModelDoesNotHave(): void
    {
        $model = $this->thinking(['minimal' => null]);

        $this->assertFalse($model->hasThinkingLevel('minimal'));
        $this->assertNull($model->thinkingEffort('minimal'));

        // And its neighbours are untouched: a partial map says only what is unusual.
        $this->assertTrue($model->hasThinkingLevel('low'));
    }

    public function testXhighComesFromTheMapWhenItSaysAnythingAtAll(): void
    {
        $this->assertTrue($this->thinking(['xhigh' => 'xhigh'])->supportsXhigh());
        $this->assertFalse($this->thinking(['xhigh' => null])->supportsXhigh());
    }

    public function testWithNoMapXhighIsNotOfferedWhateverTheId(): void
    {
        // This used to assert that `gpt-5.2` with no map still had xhigh, from an id list kept
        // because pig's generated OpenAI rows carried no maps. `Models` now writes upstream's
        // generator maps on those rows (`xhigh` from gpt-5.2 on), so the list is gone and the rule
        // is upstream's `getSupportedThinkingLevels()`: xhigh only when the map names it.
        $this->assertFalse($this->thinking([], 'gpt-5.2')->supportsXhigh());
        $this->assertFalse($this->thinking([], 'claude-sonnet-4-5')->supportsXhigh());
    }

    public function testAMapSayingNoIsTakenAtItsWord(): void
    {
        $this->assertFalse($this->thinking(['xhigh' => null], 'gpt-5.2')->supportsXhigh());
    }

    public function testTheSupportedLevelsAreUpstreamsSevenFilteredByTheMap(): void
    {
        // `getSupportedThinkingLevels()`: off for a model that does not reason; otherwise every
        // level the map does not null, xhigh and max only when it names them.
        $this->assertSame(['off', 'minimal', 'low', 'medium', 'high'], $this->thinking([])->supportedThinkingLevels());
        $this->assertSame(
            ['low', 'medium', 'high', 'xhigh', 'max'],
            $this->thinking(['off' => null, 'minimal' => null, 'xhigh' => 'xhigh', 'max' => 'max'])->supportedThinkingLevels(),
        );
    }

    public function testClampingGoesUpFromTheRequestFirstThenDown(): void
    {
        // `clampThinkingLevel()`: a model that refuses `minimal` gets `low`, not `off` — refusing
        // the amount is not refusing to think. And `xhigh` on a model without it comes down to high.
        $model = $this->thinking(['off' => null, 'minimal' => null]);

        $this->assertSame('low', $model->clampThinkingLevel('minimal'));
        $this->assertSame('low', $model->clampThinkingLevel('off'));
        $this->assertSame('high', $model->clampThinkingLevel('xhigh'));
        $this->assertSame('medium', $model->clampThinkingLevel('medium'));
    }

    /** @param array<string, string|null> $map */
    private function thinking(array $map, string $id = 'claude-sonnet-4-5'): Model
    {
        return new Model(
            $id,
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'https://api.anthropic.com',
            200_000,
            64_000,
            reasoning: true,
            thinkingLevelMap: $map,
        );
    }

    private function model(Pricing $pricing, array $input = ['text']): Model
    {
        return new Model(
            'claude-sonnet-4-5',
            'Claude Sonnet 4.5',
            Api::AnthropicMessages,
            'anthropic',
            'https://api.anthropic.com',
            200_000,
            64_000,
            reasoning: true,
            input: $input,
            pricing: $pricing,
        );
    }
}
