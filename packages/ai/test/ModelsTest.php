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

        $this->assertCount(147, $models);

        foreach ($models as $model) {
            $this->assertNotSame('', $model->id, 'a model with no id cannot be selected');
            $this->assertNotSame('', $model->name);
            $this->assertGreaterThan(0, $model->contextWindow, $model->id);
            $this->assertGreaterThan(0, $model->maxTokens, $model->id);
            $this->assertGreaterThanOrEqual(0.0, $model->pricing->input, $model->id);
            $this->assertGreaterThanOrEqual(0.0, $model->pricing->output, $model->id);
        }
    }

    public function testEveryModelSpeaksAProtocolThatIsPorted(): void
    {
        foreach (Models::all() as $model) {
            // A model that can be selected and then not talked to is a worse answer than
            // "no such model" — which is the whole reason the table is not all 414.
            $this->assertContains(
                $model->api,
                [Api::AnthropicMessages, Api::OpenAiCompletions, Api::OpenAiResponses, Api::GoogleGenerativeAi],
                $model->id . ' speaks ' . $model->api->value,
            );
        }
    }

    public function testAnthropicsOwnModelsAreAllPricedAndAllSpeakItsApi(): void
    {
        $anthropic = array_filter(Models::all(), static fn ($m): bool => $m->provider === Models::ANTHROPIC);

        $this->assertCount(21, $anthropic);

        foreach ($anthropic as $model) {
            $this->assertSame(Api::AnthropicMessages, $model->api);

            // None of these is free, so a zero here would quietly report a free session.
            $this->assertGreaterThan(0.0, $model->pricing->input, $model->id);
            $this->assertGreaterThan(0.0, $model->pricing->output, $model->id);
        }
    }

    public function testNoIdIsClaimedByTwoProviders(): void
    {
        // `get()` takes an id alone, which is only honest while they are unique. A new
        // provider's table is where that stops being true.
        $ids = array_map(static fn ($m): string => $m->id, Models::all());

        $this->assertSame(array_values(array_unique($ids)), $ids);
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
        $this->assertNull(Models::get('no-such-model'));
        $this->assertNull(Models::find('openai', 'claude-sonnet-4-5'));
        $this->assertNotNull(Models::find('anthropic', 'claude-sonnet-4-5'));
    }

    public function testOnlyTheProvidersThatCanBeTalkedToAreListed(): void
    {
        // Every provider here speaks a protocol that is ported. Google's and OpenAI's own
        // arrive with theirs.
        $this->assertSame(
            ['anthropic', 'openai', 'google', 'cerebras', 'groq', 'mistral', 'xai', 'zai'],
            Models::providers(),
        );
    }

    public function testAProviderAndIdTogetherFindExactlyOneModel(): void
    {
        $this->assertSame('llama-3.3-70b-versatile', Models::find('groq', 'llama-3.3-70b-versatile')?->id);
        $this->assertNull(Models::find('anthropic', 'llama-3.3-70b-versatile'));
    }

    public function testOpenAisOwnModelsSpeakTheResponsesApi(): void
    {
        // The two OpenAI protocols are not interchangeable: gpt-5 is on the newer one,
        // and everything else in the table that says "openai" is a different company.
        $this->assertSame(Api::OpenAiResponses, Models::get('gpt-5.2')?->api);
        $this->assertSame('openai', Models::get('gpt-5.2')?->provider);
        $this->assertTrue(Models::get('gpt-5.2')?->supportsXhigh());
    }

    public function testAnOpenAiCompatibleModelCarriesItsOwnEndpoint(): void
    {
        $model = Models::get('grok-4');

        $this->assertSame(Api::OpenAiCompletions, $model?->api);
        $this->assertSame('https://api.x.ai/v1', $model?->baseUrl);
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
