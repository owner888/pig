<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Cost;
use Pig\Ai\Models;
use Pig\Ai\Usage;

/** The table of models, and what it costs to run one. */
final class ModelsTest extends TestCase
{
    public function testEveryModelInTheTableIsBuiltWhole(): void
    {
        $models = Models::all();

        $this->assertCount(21, $models);

        foreach ($models as $model) {
            $this->assertNotSame('', $model->id);
            $this->assertNotSame('', $model->name);
            $this->assertSame(Api::AnthropicMessages, $model->api);
            $this->assertSame(Models::ANTHROPIC, $model->provider);
            $this->assertGreaterThan(0, $model->contextWindow);
            $this->assertGreaterThan(0, $model->maxTokens);

            // A price of zero would silently report a free session.
            $this->assertGreaterThan(0.0, $model->pricing->input);
            $this->assertGreaterThan(0.0, $model->pricing->output);
        }
    }

    public function testTheFiguresAreTheModelsOwnAndNotOneSetForAll(): void
    {
        // The thing this table exists to fix: an invented 64k ceiling on a model that
        // caps at 4096 is a request the provider rejects, from a flag that looked fine.
        $this->assertSame(4_096, Models::get('claude-3-haiku-20240307')?->maxTokens);
        $this->assertSame(64_000, Models::get('claude-sonnet-4-5')?->maxTokens);
        $this->assertFalse(Models::get('claude-3-haiku-20240307')?->reasoning);
        $this->assertTrue(Models::get('claude-sonnet-4-5')?->reasoning);
    }

    public function testAnUnknownIdIsNullRatherThanAGuess(): void
    {
        $this->assertNull(Models::get('gpt-5.2'));
        $this->assertNull(Models::find('openai', 'claude-sonnet-4-5'));
        $this->assertNotNull(Models::find('anthropic', 'claude-sonnet-4-5'));
    }

    public function testOnlyTheProvidersThatCanBeTalkedToAreListed(): void
    {
        // 393 of upstream's 414 models belong to providers that are not ported. Offering
        // one and then failing to send the request is a worse answer than "no such model".
        $this->assertSame(['anthropic'], Models::providers());
    }

    public function testCostIsPerMillionTokens(): void
    {
        $model = Models::get('claude-sonnet-4-5');
        $this->assertNotNull($model);

        $cost = Models::cost($model, new Usage(1_000_000, 1_000_000, 1_000_000, 1_000_000));

        $this->assertSame(3.0, $cost->input);
        $this->assertSame(15.0, $cost->output);
        $this->assertSame(0.3, $cost->cacheRead);
        $this->assertSame(3.75, $cost->cacheWrite);
        $this->assertSame(22.05, round($cost->total, 2));
    }

    public function testTheUsageHandedInIsNotRewritten(): void
    {
        $model = Models::get('claude-sonnet-4-5');
        $this->assertNotNull($model);

        $usage = new Usage(1_000_000, 0, 0, 0, 0, new Cost());
        Models::cost($model, $usage);

        // Upstream's `calculateCost()` mutates its argument. A function that quietly
        // rewrites what it was given is how a total ends up counted twice.
        $this->assertSame(0.0, $usage->cost->total);
    }
}
