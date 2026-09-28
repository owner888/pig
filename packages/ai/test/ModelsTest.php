<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\Usage;

/**
 * The table of models, and what it costs to run one.
 *
 * **This file used to assert what the catalogue contained**: 178 models, a count per provider, and
 * eleven named rows checked to the token and the cent. Those assertions were right for a table
 * transcribed by hand — the docblock on the count said so, *"the way it goes wrong is a row quietly
 * missing or doubled"* — and the first regeneration from models.dev turned sixteen of them red
 * without a single thing being wrong with pig. A test that names `claude-opus-4-1` is a test that
 * expires when Anthropic retires it.
 *
 * So what is asserted here now is what pig does, not what providers sell: every row is built whole,
 * every provider pig claims is present, the resale rule holds, the figures are per model rather than
 * one set for all, and the money arithmetic is the money arithmetic. Where a case needs *a* model
 * with some property, it **finds one in the table** rather than naming one — and says so loudly if
 * the table cannot supply it, because that absence is itself a finding.
 */
final class ModelsTest extends TestCase
{
    public function testEveryModelInTheTableIsBuiltWhole(): void
    {
        $models = Models::all();

        // No count, on purpose — see the class docblock. But an empty table has to fail, or every
        // `foreach` below would pass by having nothing to walk.
        $this->assertNotEmpty($models);

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
     * Every provider pig says it can talk to has something to talk to.
     *
     * This is the half of the old count test that is pig's business rather than models.dev's: a
     * *whole provider* going missing is a bug — a regeneration that quietly emptied one, a
     * renamed key in the catalogue, a table whose markers were lost — and it reads downstream as
     * "no such model" for everything that provider sells.
     */
    public function testEveryProviderPigClaimsCanBeTalkedToHasModels(): void
    {
        $counts = [];

        foreach (Models::all() as $model) {
            $counts[$model->provider] = ($counts[$model->provider] ?? 0) + 1;
        }

        foreach (Models::providers() as $provider) {
            $this->assertArrayHasKey($provider, $counts, "{$provider} is listed and has no models");
            $this->assertGreaterThan(0, $counts[$provider]);
        }

        // And nothing in the table names a provider that is not on the list, which would be a
        // model nothing knows how to authenticate.
        foreach (array_keys($counts) as $provider) {
            $this->assertContains($provider, Models::providers(), "{$provider} is in the table and not listed");
        }
    }

    public function testEveryModelSpeaksAProtocolThatIsPorted(): void
    {
        foreach (Models::all() as $model) {
            // A model that can be selected and then not talked to is a worse answer than
            // "no such model" — which is the whole reason the table is not every model there is.
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
        $anthropic = self::of(Models::ANTHROPIC);

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
        // being true: it serves OpenAI's and Google's models under their own names. So the rule
        // is written down instead — `Models::RESOLD` — and this is what it buys. The id is found
        // rather than named, because which ids Copilot resells is the catalogue's business.
        [$id, $direct] = self::sharedId();

        $this->assertSame($direct, Models::get($id)?->provider, $id);
        $this->assertSame('github-copilot', Models::find('github-copilot', $id)?->provider, $id);
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

    public function testAnIdOnlyAResellerHasIsStillFound(): void
    {
        // A resold id is never the first answer, but it is still an answer. Which id that is
        // changes with the catalogue — it was `oswe-vscode-prime` when this was written and that
        // model is gone — so one is found.
        $models = Models::all();
        $direct = [];

        foreach ($models as $model) {
            if (!Models::isResold($model->provider)) {
                $direct[$model->id] = true;
            }
        }

        foreach ($models as $model) {
            if (Models::isResold($model->provider) && !isset($direct[$model->id])) {
                $this->assertSame($model->provider, Models::get($model->id)?->provider, $model->id);

                return;
            }
        }

        // Not a skip: every resold id also being a direct one would mean the fallback in `get()`
        // is unreachable, which is worth knowing rather than passing over.
        $this->fail('no reseller has an id of its own, so the fallback in get() is dead code');
    }

    public function testTheFiguresAreTheModelsOwnAndNotOneSetForAll(): void
    {
        // The thing this table exists to fix: an invented 64k ceiling on a model that caps lower
        // is a request the provider rejects, from a flag that looked fine. Asserted as spread
        // rather than as two named models, since which models exist is not pig's to decide.
        $windows = [];
        $outputs = [];
        $reasoning = [];

        foreach (Models::all() as $model) {
            $windows[$model->contextWindow] = true;
            $outputs[$model->maxTokens] = true;
            $reasoning[$model->reasoning ? 'yes' : 'no'] = true;
        }

        $this->assertGreaterThan(1, count($windows), 'every model has the same context window');
        $this->assertGreaterThan(1, count($outputs), 'every model has the same output cap');
        $this->assertCount(2, $reasoning, 'reasoning is the same for every model in the table');
    }

    public function testAnUnknownIdIsNullRatherThanAGuess(): void
    {
        $model = self::anyFrom(Models::ANTHROPIC);

        $this->assertNull(Models::get('no-such-model'));
        $this->assertNull(Models::find('openai', $model->id));
        $this->assertNotNull(Models::find(Models::ANTHROPIC, $model->id));
    }

    public function testOnlyTheProvidersThatCanBeTalkedToAreListed(): void
    {
        // Hand-written and not generated: which providers exist is a question about which
        // protocols are ported, which is the anchor's business and not models.dev's.
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
        $groq = self::anyFrom('groq');

        $this->assertSame($groq->id, Models::find('groq', $groq->id)?->id);
        $this->assertNull(Models::find('anthropic', $groq->id));
    }

    public function testOpenAisOwnModelsSpeakTheResponsesApi(): void
    {
        // The two OpenAI protocols are not interchangeable, and everything else in the table
        // that speaks `openai-completions` is a different company.
        foreach (self::of('openai') as $model) {
            $this->assertSame(Api::OpenAiResponses, $model->api, $model->id);
        }
    }

    public function testAnOpenAiCompatibleModelCarriesItsOwnEndpoint(): void
    {
        // Each of the five has an endpoint of its own; a shared default would send every one of
        // them to whichever was written first.
        $urls = [];

        foreach (['cerebras', 'groq', 'mistral', 'xai', 'zai'] as $provider) {
            $model = self::anyFrom($provider);

            $this->assertSame(Api::OpenAiCompletions, $model->api, $provider);
            $this->assertNotSame('', $model->baseUrl, $provider);

            $urls[$model->baseUrl] = true;
        }

        $this->assertCount(5, $urls, 'two of the compatible providers share a base URL');
    }

    public function testCostIsPerMillionTokens(): void
    {
        // Built here rather than taken from the table: the arithmetic is what is under test, and
        // a model priced by somebody else is a fixture that changes when they change their prices.
        $model = new Model(
            'priced',
            'Priced',
            Api::AnthropicMessages,
            'anthropic',
            'https://example.invalid',
            200_000,
            64_000,
            true,
            ['text'],
            new Pricing(3.0, 15.0, 0.3, 3.75),
        );

        $cost = Models::cost($model, new Usage(1_000_000, 1_000_000, 1_000_000, 1_000_000));

        $this->assertSame(3.0, $cost->input);
        $this->assertSame(15.0, $cost->output);
        $this->assertSame(0.3, $cost->cacheRead);
        $this->assertSame(3.75, $cost->cacheWrite);
        $this->assertSame(22.05, round($cost->total, 2));
    }

    public function testTheUsageHandedInIsNotRewritten(): void
    {
        $model = self::anyFrom(Models::ANTHROPIC);
        $usage = new Usage(1_000_000, 0, 0, 0, 0, new Cost());

        Models::cost($model, $usage);

        // Upstream's `calculateCost()` mutates its argument. A function that quietly
        // rewrites what it was given is how a total ends up counted twice.
        $this->assertSame(0.0, $usage->cost->total);
    }

    // ---- GitHub Copilot ------------------------------------------------------------------

    public function testEveryCopilotModelCarriesTheHeadersTheEndpointDemands(): void
    {
        foreach (self::of(Models::COPILOT) as $model) {
            // The endpoint is VS Code's and answers a request that does not claim to be
            // VS Code with a 4xx. A model missing these is a model that cannot be used.
            $this->assertSame('vscode-chat', $model->headers['Copilot-Integration-Id'] ?? null, $model->id);
            $this->assertStringStartsWith('GitHubCopilotChat/', $model->headers['User-Agent'] ?? '', $model->id);
        }
    }

    public function testCopilotSpeaksBothOpenAiShapesAndTheIdDecidesWhich(): void
    {
        $apis = [];

        foreach (self::of(Models::COPILOT) as $model) {
            $apis[$model->api->value] = true;

            // The generator's rule, because Copilot's catalogue does not say which shape a
            // model speaks: `gpt-5…` and `oswe…` are on Responses and the rest on completions.
            $expected = str_starts_with($model->id, 'gpt-5') || str_starts_with($model->id, 'oswe')
                ? Api::OpenAiResponses
                : Api::OpenAiCompletions;

            $this->assertSame($expected, $model->api, $model->id);
        }

        // Both shapes present, which is why that table has an API column and none of the
        // others does. Counted as "both" rather than to a number — see the class docblock.
        $this->assertArrayHasKey('openai-completions', $apis);
        $this->assertArrayHasKey('openai-responses', $apis);
    }

    public function testCopilotsCompletionsModelsSayWhatCopilotRejects(): void
    {
        $model = self::copilotSpeaking(Api::OpenAiCompletions);

        $this->assertNotNull($model->compat, $model->id);
        // Attached to the model rather than detected from the host, because the base URL is
        // whatever the token says: an enterprise install answers at `copilot-api.<domain>`.
        $this->assertFalse($model->compat->store);
        $this->assertFalse($model->compat->developerRole);
        $this->assertFalse($model->compat->reasoningEffort);
    }

    public function testCopilotsResponsesModelsHaveNoCompatBecauseItWouldMeanNothing(): void
    {
        $this->assertNull(self::copilotSpeaking(Api::OpenAiResponses)->compat);
    }

    public function testACopilotConversationCostsNothingToReport(): void
    {
        foreach (self::of(Models::COPILOT) as $model) {
            // A subscription, so the prices are zeroes across the board. `/session` saying
            // $0.00 is the truth and not a column nobody filled in.
            $this->assertSame(0.0, $model->pricing->input, $model->id);
            $this->assertSame(0.0, $model->pricing->output, $model->id);
        }
    }

    // ---- finding a model to make a point with --------------------------------------------

    /** @return list<Model> */
    private static function of(string $provider): array
    {
        $models = array_values(array_filter(
            Models::all(),
            static fn (Model $model): bool => $model->provider === $provider,
        ));

        self::assertNotEmpty($models, "the table has no {$provider} models at all");

        return $models;
    }

    private static function anyFrom(string $provider): Model
    {
        return self::of($provider)[0];
    }

    private static function copilotSpeaking(Api $api): Model
    {
        foreach (self::of(Models::COPILOT) as $model) {
            if ($model->api === $api) {
                return $model;
            }
        }

        self::fail("Copilot has no {$api->value} models, so the rule about them is untested");
    }

    /**
     * An id a reseller and a direct provider both claim, and whose the direct one is.
     *
     * @return array{0: string, 1: string}
     */
    private static function sharedId(): array
    {
        $direct = [];

        foreach (Models::all() as $model) {
            if (!Models::isResold($model->provider)) {
                $direct[$model->id] ??= $model->provider;
            }
        }

        foreach (Models::all() as $model) {
            if (Models::isResold($model->provider) && isset($direct[$model->id])) {
                return [$model->id, $direct[$model->id]];
            }
        }

        // Not a skip: no shared id means `RESOLD` has nothing to arbitrate and the rule it
        // exists for is untested, which is worth failing over.
        self::fail('no id is claimed by both a reseller and a direct provider');
    }
}
