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

        $this->assertCount(178, $models);

        foreach ($models as $model) {
            $this->assertNotSame('', $model->id, 'a model with no id cannot be selected');
            $this->assertNotSame('', $model->name);
            $this->assertGreaterThan(0, $model->contextWindow, $model->id);
            $this->assertGreaterThan(0, $model->maxTokens, $model->id);
            $this->assertGreaterThanOrEqual(0.0, $model->pricing->input, $model->id);
            $this->assertGreaterThanOrEqual(0.0, $model->pricing->output, $model->id);
        }
    }

    /**
     * How many of each provider's models are here, which is upstream's count for every one.
     *
     * The whole table is transcribed by hand out of `models.generated.ts`, so the way it goes
     * wrong is a row quietly missing or doubled — and nothing downstream would say so: a model
     * that is not in the table is "no such model", which reads like a typo in what was asked for.
     * The counts were checked against the anchor commit's generated file, provider by provider.
     */
    public function testEachProvidersCountIsUpstreams(): void
    {
        $counts = [];

        foreach (Models::all() as $model) {
            $counts[$model->provider] = ($counts[$model->provider] ?? 0) + 1;
        }

        ksort($counts);

        $this->assertSame(
            [
                'anthropic' => 21,
                'cerebras' => 3,
                'github-copilot' => 19,
                'google' => 21,
                'google-antigravity' => 7,
                'google-gemini-cli' => 5,
                'groq' => 15,
                'mistral' => 25,
                'openai' => 33,
                'xai' => 22,
                'zai' => 7,
            ],
            $counts,
        );
    }

    /**
     * One model per provider, to the number.
     *
     * A context window that is wrong by a factor is not a cosmetic error: it is where
     * compaction fires, so too small summarises a conversation that had room and too large
     * lets the provider refuse the request instead. A price that is wrong is a bill that is
     * wrong. Neither shows up as anything but a number nobody checks, which is why these
     * eleven rows are checked.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: float, 5: float, 6: float, 7: float}>
     */
    public static function spotValues(): array
    {
        return [
            ['anthropic', 'claude-opus-4-5', 200_000, 64_000, 5, 25, 0.5, 6.25],
            ['openai', 'gpt-5.2', 400_000, 128_000, 1.75, 14, 0.175, 0],
            ['google', 'gemini-3-pro-preview', 1_000_000, 64_000, 2, 12, 0.2, 0],
            ['github-copilot', 'gpt-5', 128_000, 128_000, 0, 0, 0, 0],
            ['google-gemini-cli', 'gemini-2.5-pro', 1_048_576, 65_535, 0, 0, 0, 0],
            ['google-antigravity', 'gemini-3-pro-high', 1_048_576, 65_535, 0, 0, 0, 0],
            ['groq', 'moonshotai/kimi-k2-instruct', 131_072, 16_384, 1, 3, 0, 0],
            ['mistral', 'mistral-large-latest', 262_144, 262_144, 0.5, 1.5, 0, 0],
            ['cerebras', 'zai-glm-4.6', 131_072, 40_960, 0, 0, 0, 0],
            ['xai', 'grok-4', 256_000, 64_000, 3, 15, 0.75, 0],
            ['zai', 'glm-4.6', 204_800, 131_072, 0.6, 2.2, 0.11, 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spotValues')]
    public function testTheNumbersAreUpstreams(
        string $provider,
        string $id,
        int $window,
        int $maxTokens,
        float $input,
        float $output,
        float $cacheRead,
        float $cacheWrite,
    ): void {
        $model = Models::find($provider, $id);

        $this->assertNotNull($model, "{$provider}/{$id}");
        $this->assertSame($window, $model->contextWindow);
        $this->assertSame($maxTokens, $model->maxTokens);
        $this->assertSame($input, $model->pricing->input);
        $this->assertSame($output, $model->pricing->output);
        $this->assertSame($cacheRead, $model->pricing->cacheRead);
        $this->assertSame($cacheWrite, $model->pricing->cacheWrite);
    }

    public function testEveryModelSpeaksAProtocolThatIsPorted(): void
    {
        foreach (Models::all() as $model) {
            // A model that can be selected and then not talked to is a worse answer than
            // "no such model" — which is the whole reason the table is not all 414.
            $this->assertContains(
                $model->api,
                [
                    Api::AnthropicMessages,
                    Api::OpenAiCompletions,
                    Api::OpenAiResponses,
                    Api::GoogleGenerativeAi,
                    Api::GoogleGeminiCli,
                ],
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

    public function testAnIdTwoProvidersClaimMeansTheDirectOne(): void
    {
        // Unique ids were what made `get()` honest, and Copilot's table is where that stopped
        // being true: it serves OpenAI's and Google's models under their own names. So the
        // rule is written down instead — `Models::RESOLD` — and this is what it buys.
        $this->assertSame('openai', Models::get('gpt-5')?->provider);
        $this->assertSame('google', Models::get('gemini-2.5-pro')?->provider);
        $this->assertSame('github-copilot', Models::find('github-copilot', 'gpt-5')?->provider);
    }

    public function testEveryDuplicatedIdIsOneOfTheResoldOnes(): void
    {
        $seen = [];

        foreach (Models::all() as $model) {
            $other = $seen[$model->id] ?? null;

            // The claim this replaces the uniqueness check with: two providers may share an
            // id only when one of them is reselling. Any other collision is a typo in a table
            // and would make `get()` answer with whichever was written first.
            if ($other !== null) {
                $this->assertTrue(
                    Models::isResold($model->provider) || Models::isResold($other),
                    "{$model->id} is claimed by {$other} and {$model->provider}, neither reselling",
                );
            }

            $seen[$model->id] = $model->provider;
        }
    }

    public function testAnIdOnlyTheResellerHasIsStillFound(): void
    {
        // Copilot's alone — it is VS Code's own preview model and no direct provider carries
        // it. A resold id is never the first answer, but it is still an answer.
        $this->assertSame('github-copilot', Models::get('oswe-vscode-prime')?->provider);

        // And one that looked Copilot-only and is not: `grok-code-fast-1` is in xAI's table
        // too, so a bare id means xAI's.
        $this->assertSame('xai', Models::get('grok-code-fast-1')?->provider);
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
            [
                'anthropic',
                'openai',
                'google',
                'cerebras',
                'groq',
                'mistral',
                'xai',
                'zai',
                'github-copilot',
                'google-gemini-cli',
                'google-antigravity',
            ],
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

    // ---- GitHub Copilot ------------------------------------------------------------------

    public function testCopilotsModelsAreUpstreamsNineteen(): void
    {
        $copilot = array_values(array_filter(
            Models::all(),
            static fn ($m): bool => $m->provider === 'github-copilot',
        ));

        $this->assertCount(19, $copilot);
    }

    public function testEveryCopilotModelCarriesTheHeadersTheEndpointDemands(): void
    {
        foreach (Models::all() as $model) {
            if ($model->provider !== 'github-copilot') {
                continue;
            }

            // The endpoint is VS Code's and answers a request that does not claim to be
            // VS Code with a 4xx. A model missing these is a model that cannot be used.
            $this->assertSame('vscode-chat', $model->headers['Copilot-Integration-Id'] ?? null, $model->id);
            $this->assertStringStartsWith('GitHubCopilotChat/', $model->headers['User-Agent'] ?? '', $model->id);
        }
    }

    public function testCopilotSpeaksBothOpenAiShapes(): void
    {
        $apis = [];

        foreach (Models::all() as $model) {
            if ($model->provider === 'github-copilot') {
                $apis[$model->api->value] = ($apis[$model->api->value] ?? 0) + 1;
            }
        }

        // Ten through completions and nine through Responses, which is why that table has an
        // API column and none of the others does.
        $this->assertSame(10, $apis['openai-completions'] ?? 0);
        $this->assertSame(9, $apis['openai-responses'] ?? 0);
    }

    public function testCopilotsCompletionsModelsSayWhatCopilotRejects(): void
    {
        $model = Models::find('github-copilot', 'claude-sonnet-4.5');

        $this->assertNotNull($model);
        $this->assertNotNull($model->compat);
        // Attached to the model rather than detected from the host, because the base URL is
        // whatever the token says: an enterprise install answers at `copilot-api.<domain>`.
        $this->assertFalse($model->compat->store);
        $this->assertFalse($model->compat->developerRole);
        $this->assertFalse($model->compat->reasoningEffort);
    }

    public function testCopilotsResponsesModelsHaveNoCompatBecauseItWouldMeanNothing(): void
    {
        $this->assertNull(Models::find('github-copilot', 'gpt-5')?->compat);
    }

    public function testACopilotConversationCostsNothingToReport(): void
    {
        $model = Models::find('github-copilot', 'gpt-5');

        $this->assertNotNull($model);
        // A subscription, so upstream's table is zeroes across the board. `/session` saying
        // $0.00 is the truth and not a column nobody filled in.
        $this->assertSame(0.0, $model->pricing->input);
        $this->assertSame(0.0, $model->pricing->output);
    }
}
