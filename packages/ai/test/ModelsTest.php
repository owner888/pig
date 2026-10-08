<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\BedrockCompat;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
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
                    Api::MistralConversations,
                    Api::GoogleVertex,
                    Api::BedrockConverseStream,
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
        [$id, $direct, $reseller] = self::sharedId();

        $this->assertSame($direct, Models::get($id)?->provider, $id);
        $this->assertSame($reseller, Models::find($reseller, $id)?->provider, $id);

        // Vertex AI serves Google's ids too, and is on the list for it.
        $this->assertSame('google', Models::get('gemini-2.5-flash')?->provider);
        $this->assertSame('google-vertex', Models::find('google-vertex', 'gemini-2.5-flash')?->provider);
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

    /**
     * Which built-in models take strict tools, as upstream's generated catalogue says: OpenAI's own
     * Responses models, and every `openai-completions` provider but Cerebras (and Moonshot,
     * Together, Cloudflare's gateway and NVIDIA, which pig does not ship). Copilot's completions
     * models too — detection at generation time gives them the flag. Everything else is false by
     * default. Without this the `constrainedSampling` the built-in tools declare reached nothing.
     */
    public function testTheBuiltInModelsThatTakeStrictToolsSaySo(): void
    {
        foreach (Models::all() as $model) {
            $expected = match (true) {
                $model->api === Api::OpenAiResponses => $model->provider === 'openai',
                $model->api === Api::OpenAiCompletions => $model->provider !== 'cerebras',
                $model->api === Api::AnthropicMessages => $model->provider === Models::ANTHROPIC,
                default => false,
            };
            $actual = match (true) {
                $model->compat instanceof OpenAiCompat => $model->compat->strictMode ?? false,
                $model->compat instanceof AnthropicCompat => $model->compat->strictTools ?? false,
                default => false,
            };

            $this->assertSame($expected, $actual, "{$model->provider}/{$model->id}");
        }
    }

    /**
     * Upstream's generator sets `forceAdaptiveThinking` on the Anthropic-API models whose id
     * `isAnthropicAdaptiveThinkingModel()` names, and on no other. pig decided this per request
     * from four id fragments, which missed Opus 4.6, Sonnet 4.6 and Opus 5 — all sent a token
     * budget they do not take.
     */
    public function testTheAnthropicModelsThatThinkAdaptivelySaySo(): void
    {
        $adaptive = [];

        foreach (Models::all() as $model) {
            if ($model->compat instanceof AnthropicCompat && $model->compat->forceAdaptiveThinking === true) {
                $adaptive[] = $model->id;
            }
        }

        foreach (['claude-opus-4-6', 'claude-opus-4-7', 'claude-opus-5', 'claude-sonnet-4-6', 'claude-sonnet-5', 'claude-fable-5-1'] as $id) {
            $this->assertContains($id, $adaptive);
        }

        foreach (['claude-opus-4-5', 'claude-sonnet-4-5', 'claude-haiku-4-5'] as $id) {
            $this->assertNotContains($id, $adaptive, 'budget thinking, as upstream');
        }
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
                'google-vertex',
                'cerebras',
                'groq',
                'mistral',
                'xai',
                'zai',
                'amazon-bedrock',
                'github-copilot',
            ],
            Models::providers(),
        );
    }

    public function testVertexRowsAreUpstreamsCatalogueRows(): void
    {
        // Pinned from upstream's published catalogue (`google-vertex.json`, pi-ai 1.1.0): the base
        // URL is the location template the provider fills, and 2.5 Flash's cache read is upstream's
        // correction of models.dev's.
        $model = Models::find(Models::GOOGLE_VERTEX, 'gemini-2.5-flash');

        $this->assertNotNull($model);
        $this->assertSame(Api::GoogleVertex, $model->api);
        $this->assertSame('https://{location}-aiplatform.googleapis.com', $model->baseUrl);
        $this->assertSame([0.3, 2.5, 0.03, 0.0], [$model->pricing->input, $model->pricing->output, $model->pricing->cacheRead, $model->pricing->cacheWrite]);
        $this->assertSame(['images' => ['resize' => ['maxWidth' => 2000, 'maxHeight' => 2000, 'maxBytes' => 4_718_592, 'jpegQuality' => 80]]], $model->inputLimits);
        $this->assertCount(14, self::of(Models::GOOGLE_VERTEX));
    }

    public function testBedrockRowsAreUpstreamsCatalogueRows(): void
    {
        // Pinned from upstream's published catalogue (`amazon-bedrock.json`, pi-ai 1.1.0).
        $this->assertCount(193, self::of(Models::AMAZON_BEDROCK));

        $opus = Models::find(Models::AMAZON_BEDROCK, 'global.anthropic.claude-opus-4-6-v1');
        $this->assertNotNull($opus);
        $this->assertSame(Api::BedrockConverseStream, $opus->api);
        $this->assertSame('https://bedrock-runtime.us-east-1.amazonaws.com', $opus->baseUrl);
        $this->assertInstanceOf(BedrockCompat::class, $opus->compat);
        $this->assertTrue($opus->compat->supportsStrictMode);
        // `applyThinkingLevelMetadata()`: Opus 4.6 takes adaptive thinking up to `max`.
        $this->assertSame('max', $opus->thinkingLevelMap['max'] ?? null);
        $this->assertSame(['images' => ['maxPerMessage' => 20, 'resize' => ['maxWidth' => 2000, 'maxHeight' => 2000, 'maxBytes' => 4_718_592, 'jpegQuality' => 80]]], $opus->inputLimits);

        // `getBedrockBaseUrl()`: an `eu.` profile is served from Frankfurt.
        $eu = Models::find(Models::AMAZON_BEDROCK, 'eu.anthropic.claude-opus-4-6-v1');
        $this->assertNotNull($eu);
        $this->assertSame('https://bedrock-runtime.eu-central-1.amazonaws.com', $eu->baseUrl);

        // Without `structured_output` there is no compat at all.
        $nova = Models::find(Models::AMAZON_BEDROCK, 'amazon.nova-pro-v1:0');
        $this->assertNotNull($nova);
        $this->assertNull($nova->compat);
        $this->assertSame([], $nova->thinkingLevelMap);

        // The tier upstream's catalogue carries.
        $haiku = Models::find(Models::AMAZON_BEDROCK, 'anthropic.claude-haiku-5-5');
        $this->assertNotNull($haiku);
        $this->assertCount(1, $haiku->pricing->tiers);
        $this->assertSame(100_000, $haiku->pricing->tiers[0]->inputTokensAbove);

        // `BEDROCK_INFERENCE_PROFILE_ONLY_MODEL_IDS`.
        $this->assertNull(Models::find(Models::AMAZON_BEDROCK, 'anthropic.claude-opus-5'));
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
        // Each of the four has an endpoint of its own; a shared default would send every one of
        // them to whichever was written first. (Mistral was the fifth until it moved to its own
        // API, as upstream's did — see the next test.)
        $urls = [];

        foreach (['cerebras', 'groq', 'xai', 'zai'] as $provider) {
            $model = self::anyFrom($provider);

            $this->assertSame(Api::OpenAiCompletions, $model->api, $provider);
            $this->assertNotSame('', $model->baseUrl, $provider);

            $urls[$model->baseUrl] = true;
        }

        $this->assertCount(4, $urls, 'two of the compatible providers share a base URL');
    }

    public function testMistralsModelsSpeakMistralsOwnApiAsUpstreamsGeneratorRoutesThem(): void
    {
        // Upstream's generator: `api: "mistral-conversations"`, `baseUrl: "https://api.mistral.ai"`
        // on every Mistral model. pig used to send them to `/v1` on the OpenAI-compatible API,
        // with four Mistral rules upstream's completions provider no longer has.
        foreach (self::of('mistral') as $model) {
            $this->assertSame(Api::MistralConversations, $model->api, $model->id);
            $this->assertSame('https://api.mistral.ai', $model->baseUrl, $model->id);
            $this->assertNull($model->compat, $model->id);
        }

        // A reasoning model with verified efforts carries their map — `reasoning_effort` at run
        // time (`MistralTest` pins the `prompt_mode` arm for one without).
        $small = Models::find('mistral', 'mistral-small-latest');
        $this->assertNotNull($small);
        $this->assertSame('none', $small->thinkingLevelMap['off']);
        $this->assertSame('high', $small->thinkingLevelMap['high']);
    }

    public function testZaisModelsSayTheirThinkingFormatAndStreamTheirToolCalls(): void
    {
        // Upstream's `processZaiModels()` compat: no `developer` role, the `zai` thinking format,
        // and `zaiToolStream` for every model but the GLM-4.5 ones (no longer listed), whose endpoint
        // does not take `tool_stream`.
        $glm = Models::find('zai', 'glm-4.7');
        $this->assertNotNull($glm);
        $this->assertInstanceOf(OpenAiCompat::class, $glm->compat);
        $this->assertTrue($glm->compat->zaiToolStream);
        $this->assertSame('zai', $glm->compat->thinkingFormat);
        $this->assertFalse($glm->compat->developerRole);
        // No verified efforts on the row, so `reasoning_effort` stays off as detection has it.
        $this->assertNull($glm->compat->reasoningEffort);
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

    public function testMidConversationTranscriptKeysFollowUpstreamsGenerator(): void
    {
        // `getAnthropicMessagesCompat()`: both keys for the `anthropic` provider's models that
        // `supportsAnthropicMidConvoSystemMessages()` names; only the system-message key for
        // `opencode` and `github-copilot`, which "reject `tool_addition`/`tool_removal` blocks";
        // neither elsewhere, and neither for an id the patterns leave out.
        $both = AnthropicCompat::forBuiltIn('anthropic', 'claude-opus-4-8');
        $this->assertTrue($both?->supportsMidConvoSystemMessages);
        $this->assertTrue($both?->supportsMidConvoToolChanges);

        $copilot = AnthropicCompat::forBuiltIn('github-copilot', 'claude-opus-4.8');
        $this->assertTrue($copilot?->supportsMidConvoSystemMessages);
        $this->assertNull($copilot?->supportsMidConvoToolChanges);

        $this->assertNull(AnthropicCompat::forBuiltIn('openrouter', 'anthropic/claude-opus-4.8')?->supportsMidConvoSystemMessages);
        $this->assertNull(AnthropicCompat::forBuiltIn('anthropic', 'claude-opus-4-7')?->supportsMidConvoSystemMessages);
        $this->assertTrue(AnthropicCompat::forBuiltIn('anthropic', 'claude-fable-5-1-20260901')?->supportsMidConvoToolChanges);
        $this->assertTrue(AnthropicCompat::forBuiltIn('anthropic', 'claude-fable-5')?->supportsMidConvoSystemMessages);
        $this->assertNull(AnthropicCompat::forBuiltIn('anthropic', 'claude-sonnet-5')?->supportsMidConvoSystemMessages);

        // And the built-in table carries them.
        $this->assertTrue(Models::find(Models::ANTHROPIC, 'claude-opus-4-8')?->compat?->supportsMidConvoToolChanges);
    }

    public function testClaudeHaikuFiveFiveIsUpstreamsHandAddedRowWithItsLongPromptTier(): void
    {
        // Upstream's generator adds it "until models.dev includes it": "Prompts over 100k input
        // tokens are billed at 5x for the whole request."
        $model = Models::find(Models::ANTHROPIC, 'claude-haiku-5-5');

        $this->assertNotNull($model);
        $this->assertSame([1_000_000, 128_000, true], [$model->contextWindow, $model->maxTokens, $model->reasoning]);
        $this->assertEquals(
            new Pricing(0.1, 0.5, 0.01, 0.125, [new PricingTier(100_000, 0.5, 2.5, 0.05, 0.625)]),
            $model->pricing,
        );
        // The compat and the map every 5.5 model gets: adaptive, no temperature, managed effort,
        // strict tools, mid-conversation system messages and tool changes (upstream's generator
        // `supportsAnthropicMidConvoSystemMessages()`, added to pig's compat since), and the whole
        // 5.5 map.
        $this->assertEquals(new AnthropicCompat(
            forceAdaptiveThinking: true,
            strictTools: true,
            supportsTemperature: false,
            supportsMidConvoEffort: true,
            supportsMidConvoSystemMessages: true,
            supportsMidConvoToolChanges: true,
        ), $model->compat);
        $this->assertSame(
            ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
            $model->thinkingLevelMap,
        );
    }

    public function testATierPricesTheWholeRequestOnceItsThresholdIsPassed(): void
    {
        // Upstream's `calculateCost()`: input + cache read + cache write picks the tier, the
        // highest threshold strictly exceeded wins, and its rates bill every token of the request.
        $model = Models::find(Models::ANTHROPIC, 'claude-haiku-5-5');
        $this->assertNotNull($model);

        $at = Models::cost($model, new Usage(100_000, 1_000_000));
        $this->assertEqualsWithDelta(0.01, $at->input, 1e-12, 'at the threshold: base rates');
        $this->assertEqualsWithDelta(0.5, $at->output, 1e-12);

        $past = Models::cost($model, new Usage(90_000, 1_000_000, 10_001));
        $this->assertEqualsWithDelta(0.045, $past->input, 1e-12, 'past it, counting the cache read: the tier');
        $this->assertEqualsWithDelta(2.5, $past->output, 1e-12);
        $this->assertEqualsWithDelta(0.05 * 10_001 / 1_000_000, $past->cacheRead, 1e-12);

        // A one-hour write is twice the tier's input rate, as it is twice the base one.
        $long = (new Usage(200_000, 0, 0, 1_000_000, cacheWrite1h: 1_000_000))->withCost($model);
        $this->assertEqualsWithDelta(1.0, $long->cost->cacheWrite, 1e-12);
    }

    public function testFableFiveMayFallBackToTheOpusModelsAndOpusFiveToNone(): void
    {
        // Upstream's `ANTHROPIC_ALLOWED_FALLBACK_MODELS`, priced from the fallbacks' own rows. Opus 5
        // is a managed-effort model, so only managed-effort fallbacks count, and Opus 4.8 is not one.
        $fable = Models::find(Models::ANTHROPIC, 'claude-fable-5');
        $this->assertInstanceOf(AnthropicCompat::class, $fable?->compat);
        $this->assertSame(
            [['anthropic', 'claude-opus-4-8'], ['anthropic', 'claude-opus-5']],
            array_map(static fn (array $f): array => [$f['provider'], $f['model']], $fable->compat->allowedFallbackModels ?? []),
        );
        $this->assertEquals(Models::find(Models::ANTHROPIC, 'claude-opus-5')?->pricing, $fable->compat->allowedFallbackModels[1]['cost']);

        $opus = Models::find(Models::ANTHROPIC, 'claude-opus-5');
        $this->assertInstanceOf(AnthropicCompat::class, $opus?->compat);
        $this->assertNull($opus->compat->allowedFallbackModels);
    }

    public function testOpenAiAndCopilotGptModelsCarryUpstreamsThinkingLevelMaps(): void
    {
        // Upstream's `applyThinkingLevelMetadata()` OpenAI arms. pig's OpenAI rows carried no map,
        // so `off` was offered on gpt-5 (which cannot be switched off) and xhigh came from an id
        // list that named three models.
        foreach ([
            ['openai', 'gpt-5', ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
            ['openai', 'gpt-5.1', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
            ['openai', 'gpt-5.2', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
            ['openai', 'gpt-5.5', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
            ['openai', 'gpt-5.5-pro', ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
            ['openai', 'gpt-5.6-sol', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            ['openai', 'gpt-6-astra', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            ['openai', 'gpt-6-sol', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            ['openai', 'o3', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
            [Models::COPILOT, 'gpt-5-mini', ['off' => null, 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
            [Models::COPILOT, 'gpt-5.4', ['off' => null, 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
            [Models::COPILOT, 'gpt-6-sol', ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::COPILOT, 'gpt-6.1-sol', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        ] as [$provider, $id, $map]) {
            $model = Models::find($provider, $id);
            $this->assertNotNull($model, "{$provider}/{$id}");
            $this->assertSame($map, $model->thinkingLevelMap, "{$provider}/{$id}");
        }

        // What the map is for: Copilot's GPT-6 offers xhigh, and gpt-5 does not offer off.
        $this->assertTrue(Models::find(Models::COPILOT, 'gpt-6-sol')?->supportsXhigh());
        $this->assertNotContains('off', Models::find('openai', 'gpt-5')?->supportedThinkingLevels() ?? []);
    }

    public function testTheOpenAiModelsThatChargeForCacheWritesTakeTheExplicitCacheMode(): void
    {
        // Upstream's `applyOpenAIExplicitPromptCacheMetadata()`: OpenAI's Responses models with a
        // cache-write price — GPT-5.6 and later — accept `prompt_cache_options`; older ones reject it.
        foreach (self::of('openai') as $model) {
            $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
            $this->assertSame(
                $model->pricing->cacheWrite > 0 ? true : null,
                $model->compat->supportsExplicitPromptCacheMode,
                $model->id,
            );
        }
    }

    public function testOpenAisLongContextModelsStopAt272kAndPriceWhatIsPastIt(): void
    {
        // Upstream's generator: "Keep direct OpenAI requests in the short-context pricing tier by
        // default" — `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS` get a 272,000 window. A window is where
        // compaction fires, so pig used to let a gpt-5.5 conversation grow to 1,050,000 tokens and
        // bill every turn past 272k at the long-context rate, which no row of the table recorded.
        foreach (['gpt-5.4', 'gpt-5.5', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-6.1-sol'] as $id) {
            $model = Models::find('openai', $id);
            $this->assertNotNull($model, $id);
            $this->assertSame(272_000, $model->contextWindow, $id);
            $this->assertSame(128_000, $model->maxTokens, $id);
            // `withOpenAiLongContextPricing()`: one tier past 272k, input and cache at twice the
            // base and output at one and a half times.
            $this->assertEquals([new PricingTier(
                272_000,
                round($model->pricing->input * 2, 6),
                round($model->pricing->output * 1.5, 6),
                round($model->pricing->cacheRead * 2, 6),
                round($model->pricing->cacheWrite * 2, 6),
            )], $model->pricing->tiers, $id);
        }

        // The two Pro models keep their window and get the tier; `OPENAI_STANDARD_COSTS` is the base
        // for the GPT-5.6 and GPT-6 rows.
        $pro = Models::find('openai', 'gpt-5.5-pro');
        $this->assertSame(1_050_000, $pro?->contextWindow);
        $this->assertEquals([new PricingTier(272_000, 60.0, 270.0, 0.0, 0.0)], $pro->pricing->tiers);
        $this->assertEquals(new Pricing(4.0, 20.0, 0.4, 5.0, [new PricingTier(272_000, 8.0, 30.0, 0.8, 10.0)]), Models::find('openai', 'gpt-5.6-sol')?->pricing);

        // "models.dev reports gpt-5-pro output as 272000 (a duplicate of the input sub-limit); the
        // actual max output is 128000."
        $this->assertSame(128_000, Models::find('openai', 'gpt-5-pro')?->maxTokens);

        // The cap is OpenAI's own endpoint only: Copilot resells gpt-5.5 at its own window.
        $this->assertGreaterThan(272_000, Models::find(Models::COPILOT, 'gpt-5.5')?->contextWindow ?? 0);

        // And the long-context rate applies to a whole request past the threshold.
        $gpt55 = Models::find('openai', 'gpt-5.5');
        $this->assertNotNull($gpt55);
        $this->assertEqualsWithDelta(272_000 * 5.0 / 1_000_000, Models::cost($gpt55, new Usage(272_000, 0))->input, 1e-9);
        $this->assertEqualsWithDelta(272_001 * 10.0 / 1_000_000, Models::cost($gpt55, new Usage(272_001, 0))->input, 1e-9);
    }

    public function testImageLimitsAndPromptCacheLifetimesAreWrittenAsUpstreamsGeneratorWritesThem(): void
    {
        // `applyImageInputMetadata()`: per-provider limits for a model that takes images, the
        // 2000px / 4.5 MiB / q80 resize on every one, nothing on a text-only model.
        $resize = ['maxWidth' => 2000, 'maxHeight' => 2000, 'maxBytes' => 4_718_592, 'jpegQuality' => 80];

        $this->assertSame(
            ['maxRequestBytes' => 33_554_432, 'images' => ['maxPerRequest' => 100, 'resize' => $resize]],
            Models::find(Models::ANTHROPIC, 'claude-haiku-4-5')?->inputLimits,
            'Anthropic at a 200,000 window: 100 images a request',
        );
        $this->assertSame(
            ['maxRequestBytes' => 33_554_432, 'images' => ['maxPerRequest' => 600, 'resize' => $resize]],
            Models::find(Models::ANTHROPIC, 'claude-opus-4-8')?->inputLimits,
            'and 600 at any other',
        );
        $this->assertSame(
            ['maxRequestBytes' => 536_870_912, 'images' => ['maxPerRequest' => 1500, 'resize' => $resize]],
            Models::find('openai', 'gpt-5.5')?->inputLimits,
        );
        $this->assertSame(
            ['maxRequestBytes' => 20_971_520, 'images' => ['maxPerRequest' => 3600, 'resize' => $resize]],
            Models::find('google', 'gemini-2.5-pro')?->inputLimits,
        );
        // A provider with no limits of its own still gets the resize profile.
        $this->assertSame(['images' => ['resize' => $resize]], Models::find(Models::COPILOT, 'gpt-5.5')?->inputLimits);

        foreach (Models::all() as $model) {
            if (!$model->acceptsImages()) {
                $this->assertNull($model->inputLimits, "{$model->provider}/{$model->id} takes no images");
            }
        }

        // `applyPromptCacheMetadata()`: direct Anthropic only, five minutes and an hour — "Do not add
        // OpenAI lifetimes yet", and a proxy's cache is not assumed to behave the same.
        foreach (Models::all() as $model) {
            $this->assertSame(
                $model->provider === Models::ANTHROPIC ? ['short' => 300, 'long' => 3600] : null,
                $model->promptCache,
                "{$model->provider}/{$model->id}",
            );
        }

        // Fable 5 is rebuilt to carry its fallbacks, and keeps both through that.
        $this->assertNotNull(Models::find(Models::ANTHROPIC, 'claude-fable-5')?->promptCache);
        $this->assertNotNull(Models::find(Models::ANTHROPIC, 'claude-fable-5')?->inputLimits);
    }

    public function testModelsDevsVerifiedEffortsApplyOnlyWhereTheEffortIsSentDirectly(): void
    {
        // Upstream's `applyModelsDevReasoningOptionMetadata()`, gated by
        // `supportsDirectReasoningEffort()` and run between the Anthropic compat arm and the
        // id rules. The tables carry no `effortLevelMap` until the next regeneration, so the rule is
        // driven here directly, with the map `getEffortThinkingLevelMap()` makes of
        // `{type: "effort", values: ["none", "low", "medium", "high"]}`.
        $effort = ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null];
        $map = new \ReflectionMethod(Models::class, 'thinkingLevelMap');
        $direct = new \ReflectionMethod(Models::class, 'supportsDirectReasoningEffort');

        // Every Responses model takes it, and the id rules still come after it: gpt-5.2's `xhigh`.
        $this->assertTrue($direct->invoke(null, Api::OpenAiResponses, 'openai', 'https://api.openai.com/v1', 'gpt-5.2', null));
        $this->assertSame(
            [...$effort, 'off' => 'none', 'xhigh' => 'xhigh'],
            $map->invoke(null, 'openai', Api::OpenAiResponses, 'gpt-5.2', [], $effort),
        );

        // An Anthropic model only when it thinks adaptively.
        $this->assertTrue($direct->invoke(null, Api::AnthropicMessages, 'anthropic', '', 'claude-opus-4-8', AnthropicCompat::forBuiltIn('anthropic', 'claude-opus-4-8')));
        $this->assertFalse($direct->invoke(null, Api::AnthropicMessages, 'anthropic', '', 'claude-haiku-4-5', AnthropicCompat::forBuiltIn('anthropic', 'claude-haiku-4-5')));

        // A completions endpoint only with `thinkingFormat: "openai"` and `supportsReasoningEffort`
        // — Groq has both; xAI rejects `reasoning_effort` and Z.ai thinks in its own format.
        $this->assertTrue($direct->invoke(null, Api::OpenAiCompletions, 'groq', 'https://api.groq.com/openai/v1', 'x', null));
        $this->assertFalse($direct->invoke(null, Api::OpenAiCompletions, 'xai', 'https://api.x.ai/v1', 'x', null));
        $this->assertFalse($direct->invoke(null, Api::OpenAiCompletions, 'zai', 'https://api.z.ai/api/coding/paas/v4', 'x', null));
        // Copilot's completions compat says `reasoningEffort: false` outright.
        $this->assertFalse($direct->invoke(null, Api::OpenAiCompletions, Models::COPILOT, 'https://api.individual.githubcopilot.com', 'x', new OpenAiCompat(reasoningEffort: false)));
        $this->assertFalse($direct->invoke(null, Api::GoogleGenerativeAi, 'google', '', 'x', null));
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

    public function testCopilotSpeaksThreeApisAndTheIdDecidesWhich(): void
    {
        $apis = [];

        foreach (self::of(Models::COPILOT) as $model) {
            $apis[$model->api->value] = true;

            // The generator's rule, because Copilot's catalogue does not say which shape a model
            // speaks: Claude 4.x/5.x on Anthropic's Messages API (upstream's `isCopilotClaude`),
            // then upstream's `needsResponsesApi` — `gpt-`, `grok-`, `oswe`, `mai-` — on Responses,
            // the rest on completions. pig's Responses rule used to be `gpt-5` and `oswe` only, so
            // `gpt-6…`, `grok-…` and `mai-…`, which Copilot serves through /responses alone, were
            // sent to /chat/completions.
            $expected = match (true) {
                preg_match('/^claude-(haiku|sonnet|opus|fable)-[45]([.\-]|$)/', $model->id) === 1 => Api::AnthropicMessages,
                str_starts_with($model->id, 'gpt-') || str_starts_with($model->id, 'grok-')
                    || str_starts_with($model->id, 'oswe') || str_starts_with($model->id, 'mai-') => Api::OpenAiResponses,
                default => Api::OpenAiCompletions,
            };

            $this->assertSame($expected, $model->api, $model->id);
        }

        // All three present, which is why that table has an API column and none of the others
        // does. Counted as "all three" rather than to a number — see the class docblock.
        $this->assertArrayHasKey('anthropic-messages', $apis);
        $this->assertArrayHasKey('openai-completions', $apis);
        $this->assertArrayHasKey('openai-responses', $apis);
    }

    /**
     * A Copilot Claude carries what upstream's generator gives one: `forceAdaptiveThinking` and
     * `supportsTemperature: false` by the same id rules as Anthropic's own models, no
     * `supportsStrictTools` (that is `provider === "anthropic"` only) and no `supportsMidConvoEffort`
     * (`anthropic`/`openrouter` only), and Copilot's headers like every Copilot row.
     */
    public function testCopilotsClaudeModelsCarryAnthropicsCompatTheWayUpstreamsGeneratorWritesIt(): void
    {
        $sonnet = Models::find(Models::COPILOT, 'claude-sonnet-4.6');
        $haiku = Models::find(Models::COPILOT, 'claude-haiku-4.5');
        $opus = Models::find(Models::COPILOT, 'claude-opus-5');

        $this->assertNotNull($sonnet);
        $this->assertNotNull($haiku);
        $this->assertNotNull($opus);
        $this->assertInstanceOf(AnthropicCompat::class, $sonnet->compat);
        $this->assertTrue($sonnet->compat->forceAdaptiveThinking);
        $this->assertNull($sonnet->compat->strictTools);
        $this->assertNull($sonnet->compat->supportsTemperature, 'Sonnet 4.6 still takes a temperature');
        // Haiku 4.5 thinks on a budget; its one flag is the eager-streaming refusal upstream lists.
        $this->assertNull($haiku->compat?->forceAdaptiveThinking);
        $this->assertFalse($haiku->compat?->supportsEagerToolInputStreaming);
        $this->assertFalse($opus->compat?->supportsTemperature);
        $this->assertNull($opus->compat?->supportsMidConvoEffort);
        $this->assertSame('vscode-chat', $sonnet->headers['Copilot-Integration-Id'] ?? null);
    }

    /**
     * Upstream's generator writes each Claude's whole `thinkingLevelMap`, not only its `off`: `max`
     * on the adaptive 4.6 models, `xhigh` and `max` from Opus 4.7 on, the full map on the 5.5
     * models, and Copilot's measured `minimal: "low"` overrides. pig carried `{off: null}` alone, so
     * `xhigh` was never offered on a Claude that has it.
     */
    public function testEveryClaudeCarriesUpstreamsWholeThinkingLevelMap(): void
    {
        foreach ([
            [Models::ANTHROPIC, 'claude-sonnet-4-5', []],
            [Models::ANTHROPIC, 'claude-opus-4-6', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
            [Models::ANTHROPIC, 'claude-opus-4-7', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::ANTHROPIC, 'claude-opus-5', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::ANTHROPIC, 'claude-opus-5-5', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::ANTHROPIC, 'claude-fable-5', ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::COPILOT, 'claude-sonnet-4.6', ['off' => null, 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
            [Models::COPILOT, 'claude-opus-4.7', ['off' => null, 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
            [Models::COPILOT, 'claude-opus-5', ['off' => null, 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        ] as [$provider, $id, $map]) {
            $model = Models::find($provider, $id);
            $this->assertNotNull($model, "{$provider}/{$id}");
            $this->assertSame($map, $model->thinkingLevelMap, "{$provider}/{$id}");
        }

        // What the map is for: xhigh offered where it is named, and refused where it is not.
        $this->assertTrue(Models::find(Models::ANTHROPIC, 'claude-opus-4-7')?->supportsXhigh());
        $this->assertFalse(Models::find(Models::ANTHROPIC, 'claude-opus-4-6')?->supportsXhigh());
    }

    /**
     * `off` is no level at all — `{off: null}` — on every Claude whose models.dev entry lists its
     * efforts without `none` (`getEffortThinkingLevelMap()`, merged where
     * `supportsDirectReasoningEffort()` holds), on the managed-effort ones, and on the 5.5
     * overrides. Needed now that a turn without thinking says `{type: "disabled"}`: without the
     * mark such a model would be sent an off it does not have. A model with no effort list, such as
     * Haiku 4.5, keeps an empty map, so `off` stays offered.
     */
    public function testTheClaudeModelsThatCannotStopThinkingSaySo(): void
    {
        foreach ([
            [Models::ANTHROPIC, 'claude-fable-5'], [Models::ANTHROPIC, 'claude-fable-5-1'],
            [Models::ANTHROPIC, 'claude-opus-5'], [Models::ANTHROPIC, 'claude-opus-5-5'],
            [Models::ANTHROPIC, 'claude-sonnet-5-5'], [Models::ANTHROPIC, 'claude-sonnet-4-6'],
            [Models::ANTHROPIC, 'claude-sonnet-5'],
            [Models::COPILOT, 'claude-fable-5'], [Models::COPILOT, 'claude-fable-5.1'],
            [Models::COPILOT, 'claude-opus-5.5'], [Models::COPILOT, 'claude-opus-5'],
            [Models::COPILOT, 'claude-sonnet-5.5'],
        ] as [$provider, $id]) {
            $model = Models::find($provider, $id);
            $this->assertNotNull($model, "{$provider}/{$id}");
            $this->assertFalse($model->hasThinkingLevel('off'), "{$provider}/{$id}");
        }

        foreach ([[Models::ANTHROPIC, 'claude-haiku-4-5'], [Models::ANTHROPIC, 'claude-sonnet-4-5']] as [$provider, $id]) {
            $model = Models::find($provider, $id);
            $this->assertNotNull($model, "{$provider}/{$id}");
            $this->assertTrue($model->hasThinkingLevel('off'), "{$provider}/{$id}");
        }
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

    public function testCopilotsResponsesModelsSayOnlyThatGptFiveAndLaterTakeGrammarTools(): void
    {
        // Upstream's generator writes two things into a Copilot Responses model's compat:
        // `supportsOpenAIGrammarTools`, for `gpt-<n>` with n >= 5, and — for the ids in its
        // `OPENAI_MID_CONVO_SYSTEM_MESSAGE_MODEL_IDS` — `supportsMidConvoSystemMessages` and
        // `supportsAdditionalTools` (`applyOpenAIResponsesTranscriptMetadata()`; no tool search,
        // which is "not verified through those proxies"). Everything else is left to the Responses
        // runtime's defaults — so `grok-…` and `mai-…` carry no compat at all.
        $transcript = ['gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-pro', 'gpt-5.5', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-6.1-sol'];

        foreach (self::of(Models::COPILOT) as $model) {
            if ($model->api !== Api::OpenAiResponses) {
                continue;
            }

            if (in_array($model->id, $transcript, true)) {
                $this->assertEquals(new OpenAiCompat(grammarTools: true, supportsMidConvoSystemMessages: true, supportsAdditionalTools: true), $model->compat, $model->id);
            } elseif (preg_match('/^gpt-(\d+)/', $model->id, $match) === 1 && (int) $match[1] >= 5) {
                $this->assertEquals(new OpenAiCompat(grammarTools: true), $model->compat, $model->id);
            } else {
                $this->assertNull($model->compat, $model->id);
            }
        }
    }

    public function testOpenAisToolSearchModelsSayTheyTakeToolSearchAndTranscriptAdditions(): void
    {
        // `applyOpenAIToolSearchMetadata()` and `applyOpenAIResponsesTranscriptMetadata()` on direct
        // OpenAI: tool search, additional tools and mid-conversation system messages, for the ids
        // in `OPENAI_TOOL_SEARCH_MODEL_IDS` and no others. Carried for the session file and a
        // gateway; pig has no tool search or mid-conversation tool additions to read them.
        $search = Models::find('openai', 'gpt-5.5')?->compat;
        $this->assertInstanceOf(OpenAiCompat::class, $search);
        $this->assertTrue($search->supportsToolSearch);
        $this->assertTrue($search->supportsAdditionalTools);
        $this->assertTrue($search->supportsMidConvoSystemMessages);

        $plain = Models::find('openai', 'gpt-5.2')?->compat;
        $this->assertInstanceOf(OpenAiCompat::class, $plain);
        $this->assertNull($plain->supportsToolSearch);
        $this->assertNull($plain->supportsMidConvoSystemMessages);

        // Copilot's Kimi K3 on completions: system text mid-conversation, and no tool additions,
        // because "GitHub Copilot forwards K3 text but silently drops its tool-bearing message".
        $kimi = Models::find(Models::COPILOT, 'kimi-k3')?->compat;
        $this->assertInstanceOf(OpenAiCompat::class, $kimi);
        $this->assertTrue($kimi->supportsMidConvoSystemMessages);
        $this->assertNull($kimi->supportsMidConvoToolAdditions);
    }

    public function testACopilotConversationIsPricedAtModelsDevsListPricesAsUpstreamPricesIt(): void
    {
        // Upstream's generator writes models.dev's list prices on Copilot rows. pig used to price
        // them at zero — "a subscription" — so `/session` said $0.00 where pi says what the same
        // tokens are worth, and a Copilot conversation could not be compared with any other.
        $gpt = Models::find(Models::COPILOT, 'gpt-5.5');
        $this->assertNotNull($gpt);
        $this->assertSame(5.0, $gpt->pricing->input);
        $this->assertSame(30.0, $gpt->pricing->output);
        $this->assertSame(0.5, $gpt->pricing->cacheRead);
        $this->assertCount(1, $gpt->pricing->tiers);
        $this->assertSame(272_000, $gpt->pricing->tiers[0]->inputTokensAbove);

        $opus = Models::find(Models::COPILOT, 'claude-opus-5.5');
        $this->assertNotNull($opus);
        $this->assertSame([4.0, 20.0, 0.2, 5.0], [$opus->pricing->input, $opus->pricing->output, $opus->pricing->cacheRead, $opus->pricing->cacheWrite]);
    }

    public function testCopilotsExtendedWindowsAreUpstreamsOneMillionForEveryListedId(): void
    {
        // `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS`: GitHub's 1,000,000 for every id it lists. pig had
        // five of them at models.dev's 1,050,000 — a window past the real one, where compaction
        // fires too late — and the ids not on the list keep the catalogue's figure.
        foreach (['claude-opus-4.7', 'claude-opus-4.8', 'claude-opus-5', 'claude-sonnet-4.6', 'claude-sonnet-5', 'claude-fable-5', 'claude-opus-5.5', 'gpt-5.3-codex', 'gpt-5.4', 'gpt-5.5', 'gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna'] as $id) {
            $this->assertSame(1_000_000, Models::find(Models::COPILOT, $id)?->contextWindow, $id);
        }

        $this->assertSame(1_050_000, Models::find(Models::COPILOT, 'gpt-6.1-sol')?->contextWindow);
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
     * An id a reseller and a direct provider both claim, whose the direct one is, and the reseller.
     *
     * @return array{0: string, 1: string, 2: string}
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
                return [$model->id, $direct[$model->id], $model->provider];
            }
        }

        // Not a skip: no shared id means `RESOLD` has nothing to arbitrate and the rule it
        // exists for is untested, which is worth failing over.
        self::fail('no id is claimed by both a reseller and a direct provider');
    }
}
