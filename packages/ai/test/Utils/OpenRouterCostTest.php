<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Usage;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\OpenRouterCost;

/** Upstream's `openrouter-cost.test.ts` (#10286). */
final class OpenRouterCostTest extends TestCase
{
    public function testBothTheBuiltInProviderAndCustomProvidersOnOpenRoutersEndpointAreRecognized(): void
    {
        $this->assertTrue(OpenRouterCost::isOpenRouterModel(self::model('openrouter', 'https://example.com')));
        $this->assertTrue(OpenRouterCost::isOpenRouterModel(self::model('openrouter-work', 'https://openrouter.ai/api/v1')));
        $this->assertFalse(OpenRouterCost::isOpenRouterModel(self::model('custom', 'https://example.com/v1')));
    }

    public function testANonByokRequestCostsWhatOpenRouterCharged(): void
    {
        $priced = OpenRouterCost::apply(self::usage(), [
            'cost' => 0,
            'is_byok' => false,
            'cost_details' => ['upstream_inference_cost' => 0, 'upstream_inference_prompt_cost' => 0, 'upstream_inference_completions_cost' => 0],
        ]);

        $this->assertSame(0.0, $priced->cost->total);
        $this->assertTrue($priced->cost->totalFromProvider);
        $this->assertSame(0.25, $priced->cost->input, 'the components stay catalog estimates');
    }

    public function testAByokRequestAddsTheUpstreamInferenceCost(): void
    {
        $priced = OpenRouterCost::apply(self::usage(), [
            'cost' => 0.1,
            'is_byok' => true,
            'cost_details' => ['upstream_inference_cost' => 0.9, 'upstream_inference_prompt_cost' => 0.6, 'upstream_inference_completions_cost' => 0.3],
        ]);

        $this->assertEqualsWithDelta(1.0, $priced->cost->total, 1e-12);
        $this->assertTrue($priced->cost->totalFromProvider);

        // Its parts when OpenRouter leaves out their total.
        $parts = OpenRouterCost::apply(self::usage(), [
            'cost' => 0.1,
            'is_byok' => true,
            'cost_details' => ['upstream_inference_cost' => null, 'upstream_inference_prompt_cost' => 0.6, 'upstream_inference_completions_cost' => 0.3],
        ]);
        $this->assertEqualsWithDelta(1.0, $parts->cost->total, 1e-12);
    }

    public function testIncompleteAccountingKeepsTheCatalogTotal(): void
    {
        foreach ([
            ['cost' => null, 'is_byok' => false, 'cost_details' => null],
            ['cost' => 0.1, 'is_byok' => true, 'cost_details' => null],
            ['cost' => -1, 'is_byok' => false],
            ['cost' => 0.1],
        ] as $reported) {
            $kept = OpenRouterCost::apply(self::usage(), $reported);
            $this->assertSame(1.0, $kept->cost->total);
            $this->assertFalse($kept->cost->totalFromProvider);
        }
    }

    public function testTheFlagSurvivesTheSessionFile(): void
    {
        $priced = OpenRouterCost::apply(self::usage(), ['cost' => 0.5, 'is_byok' => false]);
        $encoded = MessageJson::encodeUsage($priced);

        $this->assertTrue($encoded['cost']['totalFromProvider']);
        $this->assertTrue(MessageJson::decodeUsage($encoded)->cost->totalFromProvider);
        $this->assertArrayNotHasKey('totalFromProvider', MessageJson::encodeUsage(self::usage())['cost']);
    }

    private static function usage(): Usage
    {
        return new Usage(1, 1, 0, 0, 2, new Cost(0.25, 0.75, 0.0, 0.0, 1.0));
    }

    private static function model(string $provider, string $baseUrl): Model
    {
        return new Model('m', 'M', Api::OpenAiCompletions, $provider, $baseUrl, 1000, 100, false, ['text'], new Pricing());
    }
}
