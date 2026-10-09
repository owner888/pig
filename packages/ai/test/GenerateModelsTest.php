<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/generate-models.php`, driven the way a person drives it.
 *
 * It is a script and not a class, so this spawns it — the same arrangement as `RpcClientTest`,
 * and for the reason written there: *a mode nothing drives the way a host drives it is a mode
 * whose checks nobody misses.* The alternative was extracting the transform into `pig/ai`, which
 * would put a build step's arithmetic in the shipped package for one caller.
 *
 * **What this cannot check is the catalogue.** models.dev is fetched, so the input here is a
 * fixture written by hand from upstream's own `ModelsDevModel` interface — and this file's
 * standing warning is that a fixture written to agree with the code has been a real failure here
 * twice. So the fixture is deliberately the *shapes that decide something* rather than a copy of
 * real data, and the real verification is the run against models.dev on a machine that can reach
 * it, which prints a diff of every field that moved precisely so that it can be read rather than
 * trusted.
 */
final class GenerateModelsTest extends TestCase
{
    private string $fixture;

    /** The Radius gateway's `/v1/config`, saved — see `radiusConfig()`. */
    private string $radiusFixture;

    /** OpenRouter's, Vercel AI Gateway's and NVIDIA NIM's model lists, saved — see `openRouterModels()` and the two after it. */
    private string $openRouterFixture;

    private string $vercelFixture;

    private string $nvidiaFixture;

    /** models.dev's decision models, and OpenRouter's image and decision listings — see `decisionModels()` and the two after it. */
    private string $decisionsFixture;

    private string $openRouterImagesFixture;

    private string $openRouterDecisionsFixture;

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'models-dev-');

        if ($path === false) {
            self::fail('could not make a temporary file');
        }

        $this->fixture = $path;
        file_put_contents($this->fixture, (string) json_encode(self::catalogue()));

        $radius = tempnam(sys_get_temp_dir(), 'radius-config-');

        if ($radius === false) {
            self::fail('could not make a temporary file');
        }

        $this->radiusFixture = $radius;
        file_put_contents($this->radiusFixture, (string) json_encode(self::radiusConfig()));

        foreach ([
            'openRouterFixture' => self::openRouterModels(),
            'vercelFixture' => self::aiGatewayModels(),
            'nvidiaFixture' => self::nvidiaModels(),
            'decisionsFixture' => self::decisionModels(),
            'openRouterImagesFixture' => self::openRouterImageModels(),
            'openRouterDecisionsFixture' => self::openRouterDecisionModels(),
        ] as $property => $document) {
            $file = tempnam(sys_get_temp_dir(), 'model-list-');

            if ($file === false) {
                self::fail('could not make a temporary file');
            }

            $this->{$property} = $file;
            file_put_contents($file, (string) json_encode($document));
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->fixture)) {
            unlink($this->fixture);
        }

        foreach ([$this->radiusFixture, $this->openRouterFixture, $this->vercelFixture, $this->nvidiaFixture, $this->decisionsFixture, $this->openRouterImagesFixture, $this->openRouterDecisionsFixture] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAModelThatCannotBeHandedAToolIsNotOffered(): void
    {
        // Upstream's filter, and it is not cosmetic: pig is an agent, so a model with no tool
        // calling cannot do the one thing it would be chosen for.
        $output = $this->generated();

        self::assertStringNotContainsString('no-tools', $output);
        self::assertStringContainsString("'takes-tools' => [", $output);
    }

    public function testARetiredCopilotModelIsNotOffered(): void
    {
        // Copilot marks one rather than removing it, so the row stays in the catalogue.
        $output = $this->generated();

        self::assertStringNotContainsString('retired', $output);
    }

    public function testCopilotsApiIsDecidedByTheIdBecauseTheCatalogueDoesNotSay(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'gpt-5' => ['GPT-5', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'oswe-thing' => ['OSWE Thing', Api::OpenAiResponses,", $output);
        // Upstream's `needsResponsesApi`: `gpt-`, `grok-`, `oswe` and `mai-` — "only served through
        // the Copilot /responses endpoint". pig's rule was `gpt-5`/`oswe`, which put the other three
        // on completions.
        self::assertStringContainsString("'gpt-6-x' => ['GPT-6 X', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'grok-x' => ['Grok X', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'mai-code-x' => ['MAI X', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'gemini-x' => ['Gemini X', Api::OpenAiCompletions,", $output);
        // Upstream's `isCopilotClaude`, `/^claude-(haiku|sonnet|opus|fable)-[45]([.\-]|$)/`: a
        // Claude 4.x or 5.x speaks Anthropic's Messages API on Copilot. An id the pattern does not
        // name (`claude-x`) stays on completions, as anything else does.
        self::assertStringContainsString("'claude-sonnet-4.6' => ['Sonnet 4.6', Api::AnthropicMessages,", $output);
        self::assertStringContainsString("'claude-x' => ['Claude X', Api::OpenAiCompletions,", $output);
    }

    public function testCopilotRowsCarryModelsDevsListPricesAsUpstreamsGeneratorWritesThem(): void
    {
        // Upstream's `cost: getModelsDevCost(m.cost)` on every Copilot row, tiers included. These
        // used to be six cells and no money, on the grounds that a subscription is not metered per
        // token — so `/session` said $0.00 for a Copilot conversation that pi prices.
        $output = $this->generated();

        self::assertStringContainsString(
            "'gpt-5' => ['GPT-5', Api::OpenAiResponses, 128_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'tiers' => [[272_000, 2.5, 15.0, 0.25, 0.0]]],",
            $output,
        );
        // A row the catalogue gives no price is free, as everywhere else.
        self::assertStringContainsString(
            "'claude-x' => ['Claude X', Api::OpenAiCompletions, 128_000, 16_000, true, true, 0.0, 0.0, 0.0, 0.0],",
            $output,
        );
    }

    public function testCopilotsExtendedWindowIsUpstreamsRuleOverEveryListedId(): void
    {
        // Upstream's `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS`, applied to every Copilot row it
        // names: a window is where compaction fires, so 200,000 for a 1,000,000 model summarises a
        // conversation with five times the room. It used to be four per-id `fix` overrides, which
        // missed the ids models.dev lists at 1,050,000 — GitHub's figure is 1,000,000 — and any id
        // listed later.
        $output = $this->generated();

        self::assertStringContainsString("'claude-sonnet-4.6' => ['Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 16_000,", $output);
        self::assertStringContainsString("'gpt-5.4' => ['GPT-5.4', Api::OpenAiResponses, 1_000_000, 128_000,", $output);
        // A rule, not a correction: it says nothing, and an id it does not list keeps the
        // catalogue's window, 1,050,000 or not.
        self::assertStringNotContainsString('claude-sonnet-4.6 corrected', $output);
        self::assertStringContainsString("'gpt-5.6-sol' => ['GPT-5.6 Sol', Api::OpenAiResponses, 1_050_000, 128_000,", $output);
    }

    public function testUpstreamsMissingCopilotModelsAreAddedByHandUntilTheCatalogueHasThem(): void
    {
        // `missingCopilotModels`: Opus 5.5 with its full level map, and GPT-6 Sol and Luna at
        // `withOpenAiLongContextPricing(OPENAI_STANDARD_COSTS[id])`, all at the 1M window.
        $output = $this->generated();

        self::assertStringContainsString('github-copilot/claude-opus-5.5 added by hand', $output);
        self::assertStringContainsString(
            "'claude-opus-5.5' => ['Claude Opus 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, "
            . "'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],",
            $output,
        );
        self::assertStringContainsString(
            "'gpt-6-luna' => ['GPT-6 Luna', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],",
            $output,
        );
        // The catalogue's own GPT-6 Sol wins over the hand-written one, as `!allModels.some()` says.
        self::assertStringContainsString('the catalogue now carries github-copilot/gpt-6-sol', $output);
        self::assertStringContainsString("'gpt-6-sol' => ['GPT-6 Sol (catalogue)', Api::OpenAiResponses,", $output);
    }

    public function testGoogleRowsTakeUpstreamsGoogleThinkingLevelMap(): void
    {
        // `getGoogleThinkingLevelMap()`: the verified efforts when models.dev lists any — a Gemini
        // row's `low`/`high` — else Gemma 4's fixed map, which takes only Google's `MINIMAL` and
        // `HIGH` and cannot be switched off. pig used to write no map for either, so Gemma 4 was
        // offered `low` and `medium` and sent `LOW`, which its endpoint does not take.
        $output = $this->generated();

        self::assertStringContainsString(
            "'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]]",
            self::rowOf($output, 'gemini-9-flash'),
        );
        self::assertStringContainsString(
            "'thinkingLevelMap' => ['off' => null, 'minimal' => 'MINIMAL', 'low' => null, 'medium' => null, 'high' => 'HIGH']]",
            self::rowOf($output, 'gemma-4-9b-it'),
        );

        // `gemini-flash-latest` is read from the model it aliases — its prices, limits and efforts —
        // under its own name, as `processGoogleModels()` reads it.
        self::assertStringContainsString(
            "'gemini-flash-latest' => ['Gemini Flash Latest', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal',",
            $output,
        );
    }

    public function testMistralRowsAreUpstreamsMistralRows(): void
    {
        // Upstream's Mistral arm: the efforts' map ("Models with effort values use
        // `reasoning_effort` with these levels. Reasoning models without them (Magistral) use
        // `prompt_mode`"), a missing cache-read price at a tenth of the input, and no tiers.
        $output = $this->generated();

        self::assertStringContainsString("'small' => ['Small', 128_000, 128_000, false, true, 0.1, 0.3, 0.01, 0.0],", $output);
        self::assertStringContainsString(
            "'small-think' => ['Small Think', 256_000, 256_000, true, true, 0.15, 0.6, 0.015, 0.0, "
            . "'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],",
            $output,
        );
        self::assertStringContainsString("'magistral-x' => ['Magistral X', 128_000, 16_384, true, false, 2.0, 5.0, 0.2, 0.0],", $output);

        // "Add missing Mistral Medium 3.5 model until models.dev includes it."
        self::assertStringContainsString('mistral/mistral-medium-3.5 added by hand', $output);
    }

    public function testZaiIsReadFromTheCodingPlansEntryAndPricedFromZaisOwn(): void
    {
        // Upstream's `processZaiModels()`: pig's `zai` is the coding plan's endpoint, whose models
        // models.dev lists under `zai-coding-plan` — `zai` itself is the pay-as-you-go API's list.
        // Each row is priced from `zai` when it lists the model, and GLM-5.2's effort map says
        // `off: "none"`. pig used to read `zai`, so it offered the API's models on the plan's
        // endpoint and gave none of them their efforts.
        $output = $this->generated();

        self::assertStringContainsString(
            "'glm-5.2' => ['GLM-5.2', 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, "
            . "'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'],",
            $output,
        );
        // Not in `zai`: the plan's own price.
        self::assertStringContainsString("'glm-plan-only' => ['GLM Plan Only', 200_000, 131_072, true, false, 0.5, 1.0, 0.0, 0.0],", $output);
        // In `zai` and not in the plan: not served there, not offered.
        self::assertStringNotContainsString("'glm-api-only' => [", $output);
    }

    public function testOnlyTheCataloguesUpstreamFiltersLeaveADeprecatedModelOut(): void
    {
        // Upstream's generator skips `status: "deprecated"` for Copilot, Together, Baseten, both
        // OpenCodes and Xiaomi's four, and nowhere else; pig used to skip it for every provider.
        $output = $this->generated();

        self::assertStringNotContainsString("'retired' => [", $output);
        self::assertStringNotContainsString("'retired/model' => [", $output);
        self::assertStringContainsString("'old-llama' => ['Old Llama',", $output);
    }

    public function testBasetenRowsSayWhetherTheCatalogueListsAToggleAndAnEffort(): void
    {
        // `processBasetenModels()`: the toggle's explicit off/high map, the efforts' map, and GLM-5.2's
        // own — text-only "despite models.dev reporting image input". `Models` picks the compat by
        // the two flags (and forces both for GLM-5.2).
        $output = $this->generated();

        self::assertStringContainsString(
            "'moonshotai/Kimi-K2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.95, 4.0, 0.0, 0.0, "
            . "'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],",
            $output,
        );
        self::assertStringContainsString(
            "'openai/gpt-oss-120b' => ['GPT OSS 120B', 128_000, 128_000, true, false, 0.1, 0.5, 0.0, 0.0, "
            . "'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsEffort' => true],",
            $output,
        );
        self::assertStringContainsString(
            "'zai-org/GLM-5.2' => ['GLM 5.2', 202_752, 131_072, true, false, 1.4, 4.4, 0.0, 0.0, "
            . "'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],",
            $output,
        );
    }

    public function testFireworksPutsGlmAndKimiK3OnChatCompletionsAndTheRestOnMessages(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'accounts/fireworks/models/glm-5p3' => ['GLM 5.3', Api::OpenAiCompletions, 202_752, 131_072, true, false, 1.4, 4.4, 0.0, 0.0],", $output);
        self::assertStringContainsString("'accounts/fireworks/models/kimi-k3' => ['Kimi K3', Api::OpenAiCompletions,", $output);
        // Both reasoning options are said, for `Models`' adaptive thinking and `off: "none"`.
        self::assertStringContainsString(
            "'accounts/fireworks/models/deepseek-v4-pro-0813' => ['DeepSeek V4 Pro', Api::AnthropicMessages, 1_048_576, 384_000, true, false, 1.74, 3.48, 0.0, 0.0, "
            . "'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],",
            $output,
        );
    }

    public function testTogetherIsReadUnderAnyOfItsThreeNamesWithUpstreamsMaps(): void
    {
        // `data.together ?? data.togetherai ?? data["together-ai"]`, and `getTogetherThinkingLevelMap()`.
        $output = $this->generated();

        self::assertStringContainsString(
            "'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 131_072, true, false, 0.15, 0.6, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null], "
            . "'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],",
            $output,
        );
        self::assertStringContainsString("'moonshotai/Kimi-K3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null]],", $output);
        self::assertStringContainsString("'MiniMaxAI/MiniMax-M2.7' => ['MiniMax M2.7', 196_608, 131_072, true, false, 0.3, 1.2, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null]],", $output);
        self::assertStringContainsString("'plain/model' => ['Plain', 131_072, 8_192, false, false, 0.1, 0.1, 0.0, 0.0],", $output);
    }

    public function testOpenCodesApiIsTheCataloguesNpmPackageWithUpstreamsCorrections(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'gpt-5.5' => ['GPT-5.5', Api::OpenAiResponses, 1_050_000, 128_000,", $output);
        // "OpenCode variants list Claude Sonnet 4/4.5 with 1M context, actual limit is 200K."
        self::assertStringContainsString("'claude-sonnet-4-5' => ['Claude Sonnet 4.5', Api::AnthropicMessages, 200_000, 64_000,", $output);
        self::assertStringContainsString(
            "'gemini-x' => ['Gemini X', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 0.5, 3.0, 0.0, 0.0, "
            . "'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null],",
            $output,
        );
        self::assertStringContainsString("'qwen-alibaba' => ['Qwen Alibaba', Api::OpenAiCompletions, 262_144, 65_536, false, false, 0.4, 1.2, 0.0, 0.0, 'cacheControlFormat' => 'anthropic'],", $output);
        self::assertStringContainsString('opencode/gpt-5.3-codex-spark is listed and not offered', $output);
        // OpenCode Go's MiniMax M2.7 "don't accept Anthropic SDK auth".
        self::assertStringContainsString("'minimax-m2.7' => ['MiniMax M2.7', Api::OpenAiCompletions,", $output);
    }

    public function testKimiAndMoonshotRowsArePricedAndNamedAsUpstreamWritesThem(): void
    {
        $output = $this->generated();

        // An alias with no canonical id beside it becomes it, at Moonshot's rates for the subscription.
        self::assertStringContainsString("'kimi-for-coding' => ['Kimi For Coding', 262_144, 32_768, true, true, 0.95, 4.0, 0.19, 0.0],", $output);
        // K3 reasons whatever models.dev says, at `KIMI_K3_COST`, on both — with no tiers and no cache writes.
        self::assertStringContainsString("'k3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0],", $output);
        self::assertStringContainsString("'kimi-k3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0],", $output);
    }

    public function testTheTokenPlansNarrowTheirCataloguesAndFallBackForGlm(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'glm-5' => ['GLM-5', 202_752, 16_384, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],", $output);
        self::assertStringNotContainsString("'qwen3.8-max-preview' => [", $output);
        self::assertStringContainsString("qwen-token-plan  2 models", $output);
        // The Individual plan's allowlist has Qwen3.8 Max and not GLM-5.
        self::assertStringContainsString("qwen-token-plan-individual 1 models", $output);
    }

    public function testNvidiaRowsAreTheOnesNimServesUnderNimsOwnIds(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'vendor/model.x' => ['Model X', 131_072, 8_192, true, false, 0.0, 0.0, 0.0, 0.0],", $output);
        self::assertStringNotContainsString("'google/gemma-2-2b-it' => [", $output);
        self::assertStringNotContainsString("'vendor/not-served' => [", $output);
    }

    public function testMiniMaxKeepsOnlyTheModelsItsOwnApiServes(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'MiniMax-M2.7' => ['MiniMax-M2.7',", $output);
        self::assertStringNotContainsString("'MiniMax-M2' => [", $output);
    }

    public function testOpenRouterRowsAreItsToolCapableListingWithUpstreamsAliasesAndCorrections(): void
    {
        $output = $this->generated();

        // `anthropic/` on Messages, its reasoning metadata's efforts with `none` for off, the
        // prompt-length override as a tier and the time-of-day one skipped.
        self::assertStringContainsString(
            "'anthropic/claude-x' => ['Anthropic: Claude X', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 3.0, 15.0, 0.3, 3.75, "
            . "'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 6.0, 15.0, 0.3, 3.75]]],",
            $output,
        );
        // A `:batch` model on Chat Completions; an empty price is none; the listing's window, 4,096 out.
        self::assertStringContainsString("'anthropic/claude-x:batch' => ['Anthropic: Claude X (batch)', Api::OpenAiCompletions, 200_000, 4_096, false, false, 1.5, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("'vendor/always-thinks' => ['Vendor: Always Thinks', Api::OpenAiCompletions, 4_096, 4_096, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],", $output);
        self::assertStringNotContainsString("'vendor/no-tools' => [", $output);
        self::assertStringContainsString("'moonshotai/kimi-k3' => ['MoonshotAI: Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072,", $output);
        self::assertStringContainsString("'z-ai/glm-5' => ['Z.ai: GLM 5', Api::OpenAiCompletions, 202_752, 4_096, true, false, 0.6, 1.9, 0.119, 0.0],", $output);
        self::assertStringContainsString("'auto' => ['Auto', Api::OpenAiCompletions, 2_000_000, 30_000, true, true, 0.0, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("'openrouter/fusion' => ['OpenRouter: Fusion', Api::OpenAiCompletions, 1_000_000, 30_000, true, false, 0.0, 0.0, 0.0, 0.0],", $output);
    }

    public function testVercelRowsAreItsToolUseListingWithTheBracketsAsTiers(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'anthropic/claude-x' => ['Claude X', 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 0.0, 'tiers' => [[200_000, 6.0, 15.0, 0.3, 0.0]]],", $output);
        self::assertStringNotContainsString("'typesafe/jev' => [", $output);
        self::assertStringContainsString("vercel-ai-gateway 1 models", $output);
    }

    public function testDeepSeekAndAntLingAreUpstreamsHandWrittenRows(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("'deepseek-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'max' => 'max']],", $output);
        self::assertStringContainsString("'deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 1.32, 3.96, 0.044, 0.0],", $output);
        self::assertStringContainsString("'Ring-2.6-1T' => ['Ring 2.6 1T', 262_144, 65_536, true, false, 0.06, 0.25, 0.0, 0.0],", $output);
    }

    public function testAListThatCannotBeFetchedLeavesItsTableAlone(): void
    {
        file_put_contents($this->openRouterFixture, 'not json');

        $output = $this->generated();

        self::assertStringContainsString('Failed to fetch OpenRouter models', $output);
        self::assertStringContainsString('nothing to write for openrouter, so its table is left as it is', $output);
    }

    public function testAModelTheCatalogueDoesNotCarryIsAddedAndSaysSo(): void
    {
        $output = $this->generated();

        self::assertStringContainsString('openai/gpt-5-chat-latest added by hand', $output);
        self::assertStringContainsString("'gpt-5-chat-latest' => ['GPT-5 Chat Latest',", $output);
    }

    public function testACataloguesContextTierIsWrittenAsTheRowsTiers(): void
    {
        // Upstream's `getModelsDevCost()`: a `context` tier becomes `{inputTokensAbove: size, …}`,
        // a rate it does not list keeping the base price; any other kind of tier is skipped.
        self::assertStringContainsString(
            "'tiered' => ['Tiered', 1_000_000, 64_000, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.3, 3.75]]],",
            $this->generated(),
        );
    }

    public function testClaudeHaikuFiveFiveIsAddedByHandWithItsTier(): void
    {
        // Upstream's own hand-added row, "until models.dev includes it", written exactly as the
        // table carries it.
        $output = $this->generated();

        self::assertStringContainsString('anthropic/claude-haiku-5-5 added by hand', $output);
        self::assertStringContainsString(
            "'claude-haiku-5-5' => ['Claude Haiku 5.5', 1_000_000, 128_000, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],",
            $output,
        );
    }

    public function testClaudeOpusAndSonnetFiveFiveAreAddedByHandUntilTheCatalogueHasThem(): void
    {
        $output = $this->generated();

        self::assertStringContainsString('anthropic/claude-opus-5-5 added by hand', $output);
        self::assertStringContainsString("'claude-opus-5-5' => ['Claude Opus 5.5', 1_000_000, 128_000, true, 4.0, 20.0, 0.2, 5.0],", $output);
        self::assertStringContainsString("'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 1_000_000, 128_000, true, 2.0, 10.0, 0.2, 2.5],", $output);
    }

    public function testWorkersAiRowsAreItsToolCallingCatalogueWithItsEfforts(): void
    {
        $output = $this->generated();

        self::assertSame(
            "        '@cf/vendor/thinker' => ['Thinker', 131_072, 16_384, true, true, 0.1, 0.3, 0.05, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],",
            self::rowOf($output, '@cf/vendor/thinker'),
        );
        self::assertStringNotContainsString("'@cf/vendor/no-tools'", $output);
    }

    public function testTheGatewayRowsAreItsPassthroughsAndTheMirroredWorkersAiCatalogue(): void
    {
        $output = $this->generated();

        // "Anthropic only accepts dashed IDs".
        self::assertStringStartsWith("        'claude-x-5-5' => ['Claude X 5.5', Api::AnthropicMessages,", self::rowOf($output, 'claude-x-5-5'));
        // "Cloudflare AI Gateway passes OpenAI usage through at OpenAI list prices."
        self::assertStringContainsString(
            "        'gpt-6-sol' => ['GPT-6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],",
            $output,
        );
        self::assertStringStartsWith("        'workers-ai/@cf/vendor/listed' => ['Listed', Api::OpenAiCompletions,", self::rowOf($output, 'workers-ai/@cf/vendor/listed'));
        // The Workers AI catalogue mirrored under the prefix where the gateway's own lacks the id.
        self::assertStringStartsWith("        'workers-ai/@cf/vendor/thinker' => ['Thinker', Api::OpenAiCompletions, 131_072,", self::rowOf($output, 'workers-ai/@cf/vendor/thinker'));
        self::assertStringNotContainsString("'other/model'", $output);
    }

    public function testClassifierRowsAreEachSourcesDecisionModels(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("        'typesafe/jev-latest' => ['Jev', ClassifierApi::TypesafeSystemOne, 'https://api.typesafe.ai/v1/', 64_000, ['text'], 0.0, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("        'openrouter/typesafe/jev-1.13' => ['TypeSafe: Jev 1.13', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 32_000, ['text', 'image'], 0.042, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("        'vercel-ai-gateway/typesafe/jev' => ['Jev', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 4_096, ['text'], 0.0, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("        'opencode/jev-1.13' => ['Jev 1.13', ClassifierApi::TypesafeSystemOne, 'https://opencode.ai/zen/v1', 32_000, ['text'], 0.042, 0.0, 0.0, 0.0],", $output);
        self::assertStringContainsString("        'cloudflare-workers-ai/@cf/cloudflare/clef' => ['Clef', ClassifierApi::CloudflareWorkersAiSystemOne, 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai', 65_536, ['text'], 0.24, 0.0, 0.0, 0.0],", $output);
        // A listed model whose output is not decisions is not a classifier.
        self::assertStringNotContainsString("'openrouter/vendor/chatty'", $output);
        // The hand-kept Decisions row, with the first classifier price tier.
        self::assertStringContainsString("        'openai/gpt-6-luna' => ['GPT-6 Luna', ClassifierApi::OpenAiDecisions, 'https://api.openai.com/v1', 922_000, ['text', 'image'], 0.1, 0.0, 0.0, 0.0, 'tiers' => [[272_000, 0.2, 0.0, 0.0, 0.0]]],", $output);
        self::assertStringContainsString('classifiers      9 models', $output);
    }

    public function testImageRowsAreOpenRoutersImageListing(): void
    {
        $output = $this->generated();

        self::assertStringContainsString("        'openrouter/vendor/painter' => ['Vendor: Painter', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image', 'text'], 0.3, 2.5, 0.03, 0.0],", $output);
        self::assertStringContainsString("        'openrouter/vendor/text-in' => ['Vendor: Text In', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text'], ['image'], 0.0, 0.0, 0.0, 0.0],", $output);
        self::assertStringNotContainsString("'openrouter/vendor/no-images'", $output);
        self::assertStringContainsString('images           2 models', $output);
    }

    public function testAClassifierOrImageListingThatCannotBeReadIsNamed(): void
    {
        file_put_contents($this->openRouterImagesFixture, 'not json');
        file_put_contents($this->decisionsFixture, (string) json_encode(['typesafe/jev-latest' => ['type' => 'language']]));

        $output = $this->generated();

        self::assertStringContainsString('Failed to fetch OpenRouter image models', $output);
        self::assertStringContainsString('nothing to write for images, so its table is left as it is', $output);
        self::assertStringContainsString('Failed to load models.dev classifier data: models.dev did not return decision model typesafe/jev-latest', $output);
        self::assertStringNotContainsString("'typesafe/jev-latest'", $output);
    }

    public function testAMissingLimitIsComplainedAboutRatherThanDefaultedQuietly(): void
    {
        // Upstream's `|| 4096` turns a missing window into a number small enough to make every
        // conversation look nearly full. The default is kept so the row is usable; the complaint
        // is what gets somebody to look.
        $output = $this->generated();

        self::assertStringContainsString('openai/no-limits has no context window', $output);
        self::assertStringContainsString('openai/no-limits has no output limit', $output);
        self::assertStringContainsString("'no-limits' => ['No Limits', 4_096, 4_096,", $output);
    }

    public function testAnAnthropicModelWithNoImagesIsComplainedAboutBecauseTheTableCannotSayIt(): void
    {
        // `ANTHROPIC_MODELS` has no images column, so the assumption behind it is checked rather
        // than left to be wrong silently one day.
        $output = $this->generated();

        self::assertStringContainsString('anthropic/text-only takes no images', $output);
    }

    public function testImagesAndReasoningAreReadOffTheCatalogueAndNotGuessed(): void
    {
        $output = $this->generated();

        // Google's row: reasoning true, images true, prices through.
        self::assertStringContainsString(
            "'gemini-9-flash' => ['Gemini 9 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0,",
            $output,
        );

        // Groq's: neither flag set in the catalogue, so both false.
        self::assertStringContainsString(
            "'llama-x' => ['Llama X', 131_072, 8_192, false, false, 0.05, 0.08, 0.0, 0.0],",
            $output,
        );
    }

    public function testAModelTheCatalogueListsAndPigDoesNotOfferIsNamedAndLeftOut(): void
    {
        // Knowledge models.dev does not carry: upstream HEAD excludes these xAI ids outright.
        // The regeneration that needed this is in the traps — an `add` override for this very id
        // had never fired, and the first time it did it replaced a good row with its own stale
        // fallback.
        $output = $this->generated();

        self::assertStringContainsString('xai/grok-code-fast-1 is listed and not offered', $output);
        self::assertStringNotContainsString("'grok-code-fast-1' => [", $output);

        // And the exclusion is per provider, so the rest of xAI is untouched.
        self::assertStringContainsString("'grok-x' => ['Grok X',", $output);
    }

    public function testModelsDevsVerifiedEffortsAreWrittenAsTheRowsEffortLevelMap(): void
    {
        // Upstream's `recordModelsDevReasoningOptions()` + `getEffortThinkingLevelMap()`: every
        // level, in order — `off` is "none" when the model takes `none` — and values with no pi
        // equivalent (`default`, JSON null) left out. `Models` merges it where upstream's
        // `applyModelsDevReasoningOptionMetadata()` would, which is the next regeneration's effect.
        $output = $this->generated();

        self::assertStringContainsString(
            "'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]]",
            self::rowOf($output, 'gpt-5.5'),
        );
        self::assertStringContainsString(
            "'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => null, 'xhigh' => null, 'max' => null]]",
            self::rowOf($output, 'none-only'),
        );

        // Nothing a pi level answers to: no map at all, so the id rules alone decide.
        self::assertStringNotContainsString('effortLevelMap', self::rowOf($output, 'only-default'));
        // And Google's are not recorded upstream — its row takes them as its own map instead.
        self::assertStringNotContainsString('effortLevelMap', self::rowOf($output, 'gemini-9-flash'));
    }

    public function testOpenAisLongContextModelsAreCappedAndPricedAsUpstreamsGeneratorWritesThem(): void
    {
        // Upstream's temporary overrides for `openai`: the 272k window and 128k output on
        // `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS`, and `withOpenAiLongContextPricing()` replacing
        // whatever tiers models.dev listed — 2x input and cache, 1.5x output, above 272,000.
        $output = $this->generated();

        self::assertStringContainsString(
            "'gpt-5.5' => ['GPT-5.5', 272_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]],",
            $output,
        );
        // gpt-5-pro's output limit, which models.dev reports as the input sub-limit.
        self::assertStringContainsString("'gpt-5-pro' => ['GPT-5 Pro', 400_000, 128_000,", $output);

        // `missingOpenAiModels`: a GPT-6 the catalogue lacks is added at the cap, at
        // `OPENAI_STANDARD_COSTS`, with the long-context tier.
        self::assertStringContainsString('openai/gpt-6-sol added by hand', $output);
        self::assertStringContainsString(
            "'gpt-6-sol' => ['GPT-6 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],",
            $output,
        );
    }

    public function testOpenAisUnsupportedAliasIsNotOffered(): void
    {
        // Upstream's `MODELS_DEV_OPENAI_UNSUPPORTED_MODEL_IDS`: "models.dev lists this alias, but it
        // is not accepted by OpenAI APIs."
        $output = $this->generated();

        self::assertStringContainsString('openai/gpt-5.6 is listed and not offered', $output);
        self::assertStringNotContainsString("'gpt-5.6' => [", $output);
    }

    public function testAzureRowsAreUpstreamsCloneOfTheOpenAiRows(): void
    {
        // Upstream's Azure clone of every `openai` Responses row, after the temporary overrides: the
        // capped 272k window replaced by `AZURE_CONTEXT_WINDOW_OVERRIDES`' 1,050,000, and the cost's
        // four rates alone — the long-context tier and the models.dev efforts stay on OpenAI's row.
        $output = $this->generated();

        self::assertStringContainsString(
            "'gpt-5.5' => ['GPT-5.5', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0],",
            $output,
        );
        // An id the overrides do not name keeps OpenAI's window, its output fix included.
        self::assertStringContainsString("'gpt-5-pro' => ['GPT-5 Pro', Api::AzureOpenAiResponses, 400_000, 128_000,", $output);
        // And the DeepSeek V4 Pro resale, on Chat Completions at Azure's own rates.
        self::assertStringContainsString(
            "'deepseek-v4-pro' => ['DeepSeek V4 Pro', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 1.925, 3.828, 0.165, 0.0],",
            $output,
        );
    }

    public function testCodexRowsAreUpstreamsExplicitList(): void
    {
        // Upstream's `codexModels`, not models.dev's: Codex's 272k window, 128k output, and
        // `withOpenAiLongContextPricing(OPENAI_STANDARD_COSTS[id])` — Spark flat, at 128k.
        $output = $this->generated();

        self::assertStringContainsString(
            "'gpt-6.1-sol' => ['GPT-6.1 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],",
            $output,
        );
        self::assertStringContainsString(
            "'gpt-5.3-codex-spark' => ['GPT-5.3 Codex Spark', 128_000, 128_000, true, false, 1.75, 14.0, 0.175, 0.0],",
            $output,
        );
    }

    public function testRadiusRowsAreTheGatewaysCatalogueAsItSentThem(): void
    {
        // Upstream's `fetchRadiusModels()`: `getRadiusModelsFromConfig()` over the sanitised config —
        // the gateway's own level map and tiers kept, an entry that is not a gateway model dropped.
        $output = $this->generated();

        self::assertStringContainsString(
            "'balanced' => ['Balanced', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, "
            . "'thinkingLevelMap' => ['off' => null, 'low' => 'low', 'high' => 'high'], 'tiers' => [[272_000, 6.0, 22.5, 0.6, 0.0]]],",
            $output,
        );
        self::assertStringContainsString("'glm-x' => ['GLM X', 200_000, 32_768, false, false, 1.0, 2.0, 0.0, 0.0],", $output);
        self::assertStringNotContainsString("'no-name' => [", $output);
    }

    public function testARadiusConfigThatCannotBeReadLeavesItsTableAlone(): void
    {
        // Upstream's non-strict run: "Failed to fetch Radius models", and no rows — which here, as for
        // every other provider, leaves the table as it is rather than emptying it.
        file_put_contents($this->radiusFixture, '{"models": []}');

        $output = $this->generated();

        self::assertStringContainsString('Failed to fetch Radius models: Invalid Radius config in', $output);
        self::assertStringContainsString('nothing to write for radius, so its table is left as it is', $output);
    }

    /** The one rendered row for $id, which the dry run prints once per table. */
    private static function rowOf(string $output, string $id): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, "        '{$id}' => [")) {
                return $line;
            }
        }

        self::fail("no row for {$id} in the dry run");
    }

    public function testVertexRowsAreUpstreamsGeminiOnlyVertexRows(): void
    {
        // The end of upstream's `processGoogleModels()`: "The google-vertex models.dev catalog also
        // includes Claude, OpenAI, and other MaaS models that do not use the @google/genai Gemini
        // streaming path" — `gemini-` ids only, not `gemini-3.1-flash-lite-preview`; no tiers, no
        // cache-write price, and Gemini 2.5 Flash's cache read pinned to 0.03.
        $output = $this->generated();

        self::assertStringContainsString("'gemini-2.5-flash' => ['Gemini 2.5 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0],", $output);
        self::assertStringContainsString(
            "'gemini-3.5-flash' => ['Gemini 3.5 Flash', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, "
            . "'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],",
            $output,
        );
        // The alias reads the model it names, under its own name.
        self::assertStringContainsString("'gemini-flash-latest' => ['Gemini Flash Latest', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap'", $output);
        self::assertStringNotContainsString("'claude-on-vertex' => [", $output);
        self::assertStringNotContainsString("'gemini-3.1-flash-lite-preview' => [", $output);
    }

    public function testBedrockRowsAreUpstreamsBedrockRows(): void
    {
        // Upstream's Amazon Bedrock arm: models.dev's tiers, `compat: {supportsStrictMode: true}`
        // where it says `structured_output`, and not the ids it names as unusable.
        $output = $this->generated();

        self::assertStringContainsString(
            "'anthropic.claude-x-v1:0' => ['Claude X', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.3, 3.75]], 'strictMode' => true],",
            $output,
        );
        self::assertStringContainsString("'amazon.nova-x-v1:0' => ['Nova X', 300_000, 10_000, false, true, 0.8, 3.2, 0.2, 0.0],", $output);
        self::assertStringNotContainsString("'ai21.jamba-x' => [", $output);
        self::assertStringNotContainsString("'mistral.mistral-7b-instruct-v0:2' => [", $output);
        // `BEDROCK_INFERENCE_PROFILE_ONLY_MODEL_IDS`.
        self::assertStringContainsString('amazon-bedrock/anthropic.claude-opus-5 is listed and not offered', $output);
        self::assertStringNotContainsString("'anthropic.claude-opus-5' => [", $output);
    }

    public function testADryRunWritesNothing(): void
    {
        $before = (string) file_get_contents(__DIR__ . '/../src/Models.php');
        $this->generated();

        self::assertSame($before, (string) file_get_contents(__DIR__ . '/../src/Models.php'));
    }

    public function testAProviderMissingFromTheCatalogueIsNamedAndItsTableIsLeftAlone(): void
    {
        // A provider that has vanished is either a rename this script has to learn or an
        // endpoint that is gone, and both need a person — so an empty table is never written,
        // which would otherwise take every one of that provider's models out of pig in silence.
        // Cerebras, which has no hand-added row to keep its table from being empty (Mistral used to
        // be the example, until upstream's Mistral Medium 3.5 row arrived).
        $catalogue = self::catalogue();
        unset($catalogue['cerebras']);
        file_put_contents($this->fixture, (string) json_encode($catalogue));

        $output = $this->generated();

        self::assertStringContainsString('cerebras is not in this catalogue at all', $output);
        self::assertStringContainsString('nothing to write for cerebras, so its table is left as it is', $output);
    }

    public function testAnUnknownArgumentIsRefusedRatherThanIgnored(): void
    {
        exec(
            sprintf('%s %s --nonsense 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::script())),
            $lines,
            $status,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('unknown argument --nonsense', implode("\n", $lines));
    }

    /**
     * The generator's own report, from a dry run against the fixture.
     *
     * **Not `run()`, which is `final` on `TestCase`.** It was, and overriding a final method is a
     * fatal error *at class-load time* — so PHPUnit could not build the suite at all and no test in
     * the repository ran, which is a far worse failure than the one file being wrong. It survived
     * because the verification shim has no such method to be final.
     */
    private function generated(): string
    {
        exec(
            sprintf(
                '%s %s --from %s --radius-from %s --openrouter-from %s --vercel-from %s --nvidia-from %s --decisions-from %s --openrouter-images-from %s --openrouter-decisions-from %s --dry-run 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(self::script()),
                escapeshellarg($this->fixture),
                escapeshellarg($this->radiusFixture),
                escapeshellarg($this->openRouterFixture),
                escapeshellarg($this->vercelFixture),
                escapeshellarg($this->nvidiaFixture),
                escapeshellarg($this->decisionsFixture),
                escapeshellarg($this->openRouterImagesFixture),
                escapeshellarg($this->openRouterDecisionsFixture),
            ),
            $lines,
            $status,
        );

        $output = implode("\n", $lines);

        self::assertSame(0, $status, $output);

        return $output;
    }

    private static function script(): string
    {
        return __DIR__ . '/../../../scripts/generate-models.php';
    }

    /**
     * A Radius `/v1/config` of the shapes that decide something: a model with its own map and tiers,
     * a plain one, and one with no name, which `isRadiusGatewayModel()` refuses.
     *
     * @return array<string, mixed>
     */
    private static function radiusConfig(): array
    {
        return [
            'baseUrl' => 'https://radius.pi.dev/v1',
            'models' => [
                [
                    'id' => 'balanced', 'name' => 'Balanced', 'reasoning' => true, 'input' => ['text', 'image'],
                    'thinkingLevelMap' => ['off' => null, 'low' => 'low', 'high' => 'high'],
                    'cost' => ['input' => 3, 'output' => 15, 'cacheRead' => 0.3, 'cacheWrite' => 0, 'tiers' => [
                        ['inputTokensAbove' => 272_000, 'input' => 6, 'output' => 22.5, 'cacheRead' => 0.6, 'cacheWrite' => 0],
                    ]],
                    'contextWindow' => 1_048_576, 'maxTokens' => 131_072, 'lab' => 'Moonshot AI',
                ],
                [
                    'id' => 'glm-x', 'name' => 'GLM X', 'reasoning' => false, 'input' => ['text'],
                    'cost' => ['input' => 1, 'output' => 2, 'cacheRead' => 0, 'cacheWrite' => 0],
                    'contextWindow' => 200_000, 'maxTokens' => 32_768,
                ],
                ['id' => 'no-name', 'reasoning' => true, 'input' => ['text'], 'cost' => [], 'contextWindow' => 1, 'maxTokens' => 1],
            ],
        ];
    }

    /**
     * OpenRouter's `/api/v1/models` of the shapes that decide something: an `anthropic/` model and its
     * `:batch` twin, one without tools, the reasoning metadata's three cases, a prompt-length override
     * and a time-of-day one, and two ids upstream corrects.
     *
     * @return array<string, mixed>
     */
    private static function openRouterModels(): array
    {
        $tools = ['tools', 'reasoning'];

        return ['data' => [
            [
                'id' => 'anthropic/claude-x', 'name' => 'Anthropic: Claude X', 'supported_parameters' => $tools,
                'architecture' => ['modality' => 'text+image->text'],
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015', 'input_cache_read' => '0.0000003', 'input_cache_write' => '0.00000375',
                    'overrides' => [['min_prompt_tokens' => 200_000, 'prompt' => '0.000006'], ['min_prompt_tokens' => 1, 'utc_start' => 16, 'prompt' => '0.000001']]],
                'top_provider' => ['context_length' => 1_000_000, 'max_completion_tokens' => 128_000],
                'reasoning' => ['mandatory' => false, 'supported_efforts' => ['low', 'high']],
            ],
            [
                'id' => 'anthropic/claude-x:batch', 'name' => 'Anthropic: Claude X (batch)', 'supported_parameters' => ['tools'],
                'architecture' => ['modality' => 'text->text'], 'pricing' => ['prompt' => '0.0000015', 'completion' => ''],
                'context_length' => 200_000,
            ],
            [
                'id' => 'vendor/always-thinks', 'name' => 'Vendor: Always Thinks', 'supported_parameters' => $tools,
                'pricing' => ['prompt' => '0', 'completion' => '0'],
                'reasoning' => ['mandatory' => true],
            ],
            [
                'id' => 'vendor/no-tools', 'name' => 'Vendor: No Tools', 'supported_parameters' => ['reasoning'],
            ],
            [
                'id' => 'moonshotai/kimi-k3', 'name' => 'MoonshotAI: Kimi K3', 'supported_parameters' => $tools,
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'top_provider' => ['context_length' => 1_048_576, 'max_completion_tokens' => 32_768],
            ],
            [
                'id' => 'z-ai/glm-5', 'name' => 'Z.ai: GLM 5', 'supported_parameters' => $tools,
                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000003', 'input_cache_read' => '0.0000002'],
                'top_provider' => ['context_length' => 202_752],
            ],
        ]];
    }

    /**
     * Vercel AI Gateway's `/v1/models`: a tool-use model with vision, reasoning and a prompt-length
     * bracket, one without tools, and an evaluation model (a classifier).
     *
     * @return array<string, mixed>
     */
    private static function aiGatewayModels(): array
    {
        return ['data' => [
            [
                'id' => 'anthropic/claude-x', 'name' => 'Claude X', 'type' => 'language', 'tags' => ['tool-use', 'vision', 'reasoning'],
                'context_window' => 1_000_000, 'max_tokens' => 64_000,
                'pricing' => ['input' => '0.000003', 'output' => '0.000015', 'input_cache_read' => '0.0000003',
                    'input_tiers' => [['cost' => '0.000003', 'min' => 0, 'max' => 200_001], ['cost' => '0.000006', 'min' => 200_001]]],
            ],
            ['id' => 'vendor/no-tools', 'name' => 'No Tools', 'type' => 'language', 'tags' => ['vision'], 'pricing' => ['input' => '0.000001']],
            ['id' => 'typesafe/jev', 'name' => 'Jev', 'type' => 'evaluation', 'tags' => ['tool-use']],
        ]];
    }

    /**
     * NVIDIA NIM's `/v1/models`: one id models.dev spells differently, and one upstream does not offer.
     *
     * @return array<string, mixed>
     */
    private static function nvidiaModels(): array
    {
        return ['data' => [['id' => 'vendor/model.x'], ['id' => 'google/gemma-2-2b-it']]];
    }

    /**
     * models.dev's `models.json?type=decision`: the one decision model upstream reads.
     *
     * @return array<string, mixed>
     */
    private static function decisionModels(): array
    {
        return ['typesafe/jev-latest' => ['type' => 'decision', 'name' => 'Jev', 'modalities' => ['input' => ['text']], 'limit' => (object) []]];
    }

    /**
     * OpenRouter's `?output_modalities=image` listing: a text-and-image model, an image-only one with
     * no input modalities, and one whose output has no image.
     *
     * @return array<string, mixed>
     */
    private static function openRouterImageModels(): array
    {
        return ['data' => [
            ['id' => 'vendor/painter', 'name' => 'Vendor: Painter', 'architecture' => ['input_modalities' => ['text', 'image', 'file'], 'output_modalities' => ['image', 'text']],
                'pricing' => ['prompt' => '0.0000003', 'completion' => '0.0000025', 'input_cache_read' => '0.00000003']],
            ['id' => 'vendor/text-in', 'name' => 'Vendor: Text In', 'architecture' => ['output_modalities' => ['image']], 'pricing' => ['prompt' => '0']],
            ['id' => 'vendor/no-images', 'name' => 'Vendor: No Images', 'architecture' => ['output_modalities' => ['text']]],
        ]];
    }

    /**
     * OpenRouter's `?output_modalities=decisions` listing: a decision model, and one whose output is
     * not decisions.
     *
     * @return array<string, mixed>
     */
    private static function openRouterDecisionModels(): array
    {
        return ['data' => [
            ['id' => 'typesafe/jev-1.13', 'name' => 'TypeSafe: Jev 1.13', 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['decisions']],
                'pricing' => ['prompt' => '0.000000042', 'completion' => '0'], 'context_length' => 32_000],
            ['id' => 'vendor/chatty', 'name' => 'Vendor: Chatty', 'architecture' => ['output_modalities' => ['text']]],
        ]];
    }

    /**
     * A catalogue of the shapes that decide something, not a copy of real data.
     *
     * @return array<string, mixed>
     */
    private static function catalogue(): array
    {
        $priced = static fn (float $in, float $out): array => ['input' => $in, 'output' => $out];

        return [
            'anthropic' => ['models' => [
                'takes-tools' => [
                    'name' => 'Takes Tools', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 200_000, 'output' => 64_000],
                    'cost' => ['input' => 3, 'output' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'no-tools' => [
                    'name' => 'No Tools', 'tool_call' => false,
                    'limit' => ['context' => 200_000, 'output' => 8_192],
                ],
                'text-only' => [
                    'name' => 'Text Only', 'tool_call' => true,
                    'limit' => ['context' => 200_000, 'output' => 8_192],
                    'cost' => $priced(1, 2), 'modalities' => ['input' => ['text']],
                ],
                // A long-context tier, as models.dev writes one, and one of a kind upstream skips.
                'tiered' => [
                    'name' => 'Tiered', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 64_000],
                    'cost' => ['input' => 3, 'output' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75, 'tiers' => [
                        ['tier' => ['type' => 'context', 'size' => 200_000], 'input' => 6, 'output' => 22.5],
                        ['tier' => ['type' => 'batch'], 'input' => 1],
                    ]],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'openai' => ['models' => [
                'no-limits' => [
                    'name' => 'No Limits', 'tool_call' => true, 'modalities' => ['input' => ['text']],
                ],
                // One of upstream's capped, long-context-priced ids, as models.dev lists it: the full
                // window, and a tier of models.dev's own that `withOpenAiLongContextPricing()` replaces.
                // Its verified efforts are models.dev's `reasoning_options`, two options of which only
                // the `effort` one says anything.
                'gpt-5.5' => [
                    'name' => 'GPT-5.5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'cost' => ['input' => 5, 'output' => 30, 'cache_read' => 0.5, 'tiers' => [
                        ['tier' => ['type' => 'context', 'size' => 200_000], 'input' => 7],
                    ]],
                    'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [
                        ['type' => 'toggle'],
                        ['type' => 'effort', 'values' => ['none', 'low', 'medium', 'high', 'xhigh', 'default', null]],
                    ],
                ],
                // Upstream's `MODELS_DEV_OPENAI_UNSUPPORTED_MODEL_IDS`.
                'gpt-5.6' => [
                    'name' => 'GPT-5.6', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // "models.dev reports gpt-5-pro output as 272000 (a duplicate of the input sub-limit)".
                'gpt-5-pro' => [
                    'name' => 'GPT-5 Pro', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 400_000, 'output' => 272_000],
                    'cost' => ['input' => 15, 'output' => 120],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // Efforts with no pi equivalent, and a budget with no efforts at all: no map.
                'only-default' => [
                    'name' => 'Only Default', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 8_192],
                    'modalities' => ['input' => ['text']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['default', null]], ['type' => 'budget_tokens', 'min' => 1024]],
                ],
            ]],
            'google' => ['models' => [
                'gemini-9-flash' => [
                    'name' => 'Gemini 9 Flash', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.3, 'output' => 2.5, 'cache_read' => 0.075],
                    'modalities' => ['input' => ['text', 'image']],
                    // Upstream does not record Google's: its rows take `getGoogleThinkingLevelMap()`
                    // where they are built, which pig does not port.
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
                ],
                // Nothing to say about levels: no reasoning, no efforts.
                'gemini-plain' => [
                    'name' => 'Gemini Plain', 'tool_call' => true, 'reasoning' => false,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.1, 'output' => 0.4],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // Gemma 4 with no verified efforts: upstream's fixed map.
                'gemma-4-9b-it' => [
                    'name' => 'Gemma 4 9B IT', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 262_144, 'output' => 32_768],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // The alias, whose own entry lags behind the model it names.
                'gemini-flash-latest' => [
                    'name' => 'Gemini Flash Latest', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.75, 'output' => 3.75, 'cache_read' => 0.075],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'gemini-3.5-flash' => [
                    'name' => 'Gemini 3.5 Flash', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 1.5, 'output' => 9, 'cache_read' => 0.15],
                    'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['minimal', 'low', 'medium', 'high']]],
                ],
                // One the overrides carry a level map for, so the map can be seen rendered.
                'gemini-3.1-pro-preview' => [
                    'name' => 'Gemini 3.1 Pro Preview', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 2.0, 'output' => 12.0, 'cache_read' => 0.2],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'cerebras' => ['models' => [
                'oss' => [
                    'name' => 'OSS', 'tool_call' => true,
                    'limit' => ['context' => 131_072, 'output' => 32_768],
                    'cost' => $priced(0.25, 0.69), 'modalities' => ['input' => ['text']],
                ],
            ]],
            'groq' => ['models' => [
                'llama-x' => [
                    'name' => 'Llama X', 'tool_call' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192],
                    'cost' => $priced(0.05, 0.08),
                ],
                // Retired, and still offered: only Copilot's retired models are left out.
                'old-llama' => [
                    'name' => 'Old Llama', 'tool_call' => true, 'status' => 'deprecated',
                    'limit' => ['context' => 8_192, 'output' => 8_192],
                    'cost' => $priced(0.05, 0.08),
                ],
                // `none` alone is enough for a map: it is off's word.
                'none-only' => [
                    'name' => 'None Only', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192],
                    'cost' => $priced(0.1, 0.2),
                    'reasoning_options' => [['type' => 'effort', 'values' => ['none']]],
                ],
            ]],
            'mistral' => ['models' => [
                // No cache-read price: a tenth of the input. And a context tier, which Mistral's
                // rows do not carry.
                'small' => [
                    'name' => 'Small', 'tool_call' => true,
                    'limit' => ['context' => 128_000, 'output' => 128_000],
                    'cost' => ['input' => 0.1, 'output' => 0.3, 'tiers' => [['tier' => ['type' => 'context', 'size' => 64_000], 'input' => 0.2]]],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'small-think' => [
                    'name' => 'Small Think', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 256_000, 'output' => 256_000],
                    'cost' => ['input' => 0.15, 'output' => 0.6, 'cache_read' => 0.015],
                    'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['none', 'high']]],
                ],
                // Reasoning with no efforts: no map, so `prompt_mode` at run time.
                'magistral-x' => [
                    'name' => 'Magistral X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 16_384],
                    'cost' => $priced(2, 5), 'modalities' => ['input' => ['text']],
                ],
            ]],
            'cloudflare-workers-ai' => ['models' => [
                '@cf/vendor/thinker' => [
                    'name' => 'Thinker', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 16_384],
                    'cost' => ['input' => 0.1, 'output' => 0.3, 'cache_read' => 0.05], 'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
                ],
                '@cf/vendor/no-tools' => ['name' => 'No Tools', 'tool_call' => false, 'limit' => ['context' => 1, 'output' => 1]],
            ]],
            'cloudflare-ai-gateway' => ['models' => [
                'anthropic/claude-x-5.5' => [
                    'name' => 'Claude X 5.5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 128_000],
                    'cost' => ['input' => 4, 'output' => 20, 'cache_read' => 0.4, 'cache_write' => 5], 'modalities' => ['input' => ['text', 'image']],
                ],
                'openai/gpt-6-sol' => [
                    'name' => 'GPT-6 Sol', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'cost' => ['input' => 9, 'output' => 9], 'modalities' => ['input' => ['text', 'image']],
                ],
                'workers-ai/@cf/vendor/listed' => [
                    'name' => 'Listed', 'tool_call' => true,
                    'limit' => ['context' => 32_768, 'output' => 4_096],
                    'cost' => ['input' => 0.2, 'output' => 0.4], 'modalities' => ['input' => ['text']],
                ],
                'other/model' => ['name' => 'Other', 'tool_call' => true, 'limit' => ['context' => 1, 'output' => 1]],
            ]],
            'xai' => ['models' => [
                'grok-x' => [
                    'name' => 'Grok X', 'tool_call' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192],
                    'cost' => $priced(5, 25), 'modalities' => ['input' => ['text']],
                ],
                // On upstream HEAD's exclusion list: listed by the catalogue, not offered.
                'grok-code-fast-1' => [
                    'name' => 'Grok Code Fast 1', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 256_000, 'output' => 10_000],
                    'cost' => $priced(0.2, 1.5), 'modalities' => ['input' => ['text']],
                ],
            ]],
            // The coding plan's list, which is what pig's `zai` endpoint serves.
            'zai-coding-plan' => ['models' => [
                'glm-5.2' => [
                    'name' => 'GLM-5.2', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 131_072],
                    'cost' => $priced(0, 0), 'modalities' => ['input' => ['text']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['high', 'max']]],
                ],
                'glm-plan-only' => [
                    'name' => 'GLM Plan Only', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 200_000, 'output' => 131_072],
                    'cost' => $priced(0.5, 1.0), 'modalities' => ['input' => ['text']],
                ],
            ]],
            // The pay-as-you-go API's: prices for the plan's models, and one the plan does not serve.
            'zai' => ['models' => [
                'glm-5.2' => [
                    'name' => 'GLM-5.2', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 131_072],
                    'cost' => ['input' => 1.4, 'output' => 4.4, 'cache_read' => 0.26],
                ],
                'glm-api-only' => [
                    'name' => 'GLM API Only', 'tool_call' => true,
                    'limit' => ['context' => 131_072, 'output' => 98_304],
                    'cost' => $priced(1, 2),
                ],
            ]],
            'google-vertex' => ['models' => [
                // models.dev's Vertex figures for 2.5 Flash, which upstream overrides.
                'gemini-2.5-flash' => [
                    'name' => 'Gemini 2.5 Flash', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.3, 'output' => 2.5, 'cache_read' => 0.075, 'cache_write' => 0.383],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'gemini-3.5-flash' => [
                    'name' => 'Gemini 3.5 Flash', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 1.5, 'output' => 9, 'cache_read' => 0.15, 'tiers' => [['tier' => ['type' => 'context', 'size' => 200_000], 'input' => 3]]],
                    'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['minimal', 'low', 'medium', 'high']]],
                ],
                'gemini-flash-latest' => [
                    'name' => 'Gemini Flash Latest', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.75, 'output' => 3.75],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'gemini-3.1-flash-lite-preview' => [
                    'name' => 'Gemini 3.1 Flash Lite Preview', 'tool_call' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // A MaaS model on Vertex, which the Gemini path does not serve.
                'claude-on-vertex' => [
                    'name' => 'Claude on Vertex', 'tool_call' => true,
                    'limit' => ['context' => 200_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'amazon-bedrock' => ['models' => [
                'anthropic.claude-x-v1:0' => [
                    'name' => 'Claude X', 'tool_call' => true, 'reasoning' => true, 'structured_output' => true,
                    'limit' => ['context' => 200_000, 'output' => 64_000],
                    'cost' => ['input' => 3, 'output' => 15, 'cache_read' => 0.3, 'cache_write' => 3.75, 'tiers' => [
                        ['tier' => ['type' => 'context', 'size' => 200_000], 'input' => 6, 'output' => 22.5],
                    ]],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'amazon.nova-x-v1:0' => [
                    'name' => 'Nova X', 'tool_call' => true, 'structured_output' => false,
                    'limit' => ['context' => 300_000, 'output' => 10_000],
                    'cost' => ['input' => 0.8, 'output' => 3.2, 'cache_read' => 0.2],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'anthropic.claude-opus-5' => [
                    'name' => 'Claude Opus 5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'ai21.jamba-x' => [
                    'name' => 'Jamba X', 'tool_call' => true,
                    'limit' => ['context' => 256_000, 'output' => 4_096],
                ],
                'mistral.mistral-7b-instruct-v0:2' => [
                    'name' => 'Mistral 7B Instruct', 'tool_call' => true,
                    'limit' => ['context' => 32_000, 'output' => 8_192],
                ],
            ]],
            // `processBasetenModels()`: a toggle, an effort, GLM-5.2 (its own map, no images whatever
            // models.dev says), and a retired one.
            'baseten' => ['models' => [
                'moonshotai/Kimi-K2.6' => [
                    'name' => 'Kimi K2.6', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 262_144, 'output' => 262_144],
                    'cost' => $priced(0.95, 4), 'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'toggle']],
                ],
                'zai-org/GLM-5.2' => [
                    'name' => 'GLM 5.2', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 202_752, 'output' => 131_072],
                    'cost' => $priced(1.4, 4.4), 'modalities' => ['input' => ['text', 'image']],
                ],
                'openai/gpt-oss-120b' => [
                    'name' => 'GPT OSS 120B', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 128_000],
                    'cost' => $priced(0.1, 0.5), 'modalities' => ['input' => ['text']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'medium', 'high']]],
                ],
                'retired/model' => [
                    'name' => 'Retired', 'tool_call' => true, 'status' => 'deprecated',
                    'limit' => ['context' => 8_192, 'output' => 8_192], 'cost' => $priced(1, 1),
                ],
            ]],
            // `processFireworksModels()`: GLM and Kimi K3 on Chat Completions, the rest on Messages.
            'fireworks-ai' => ['models' => [
                'accounts/fireworks/models/glm-5p3' => [
                    'name' => 'GLM 5.3', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 202_752, 'output' => 131_072],
                    'cost' => $priced(1.4, 4.4), 'modalities' => ['input' => ['text']],
                ],
                'accounts/fireworks/models/kimi-k3' => [
                    'name' => 'Kimi K3', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 131_072],
                    'cost' => $priced(3, 15), 'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'medium', 'high', 'max']]],
                ],
                'accounts/fireworks/models/deepseek-v4-pro-0813' => [
                    'name' => 'DeepSeek V4 Pro', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 384_000],
                    'cost' => $priced(1.74, 3.48), 'modalities' => ['input' => ['text']],
                    'reasoning_options' => [['type' => 'toggle'], ['type' => 'effort', 'values' => ['high', 'max']]],
                ],
            ]],
            // Upstream reads `together`, else `togetherai`, else `together-ai`.
            'togetherai' => ['models' => [
                'openai/gpt-oss-120b' => [
                    'name' => 'GPT OSS 120B', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 131_072],
                    'cost' => $priced(0.15, 0.6), 'modalities' => ['input' => ['text']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'medium', 'high']]],
                ],
                'moonshotai/Kimi-K3' => [
                    'name' => 'Kimi K3', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 131_072],
                    'cost' => $priced(3, 15), 'modalities' => ['input' => ['text', 'image']],
                ],
                'MiniMaxAI/MiniMax-M2.7' => [
                    'name' => 'MiniMax M2.7', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 196_608, 'output' => 131_072],
                    'cost' => $priced(0.3, 1.2), 'modalities' => ['input' => ['text']],
                ],
                'plain/model' => [
                    'name' => 'Plain', 'tool_call' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192], 'cost' => $priced(0.1, 0.1),
                ],
            ]],
            // The API is the `provider.npm` models.dev gives each model.
            'opencode' => ['models' => [
                'gpt-5.5' => [
                    'name' => 'GPT-5.5', 'tool_call' => true, 'reasoning' => true, 'provider' => ['npm' => '@ai-sdk/openai'],
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'cost' => $priced(5, 30), 'modalities' => ['input' => ['text', 'image']],
                ],
                'claude-sonnet-4-5' => [
                    'name' => 'Claude Sonnet 4.5', 'tool_call' => true, 'reasoning' => true, 'provider' => ['npm' => '@ai-sdk/anthropic'],
                    'limit' => ['context' => 1_000_000, 'output' => 64_000],
                    'cost' => $priced(3, 15), 'modalities' => ['input' => ['text', 'image']],
                ],
                'gemini-x' => [
                    'name' => 'Gemini X', 'tool_call' => true, 'reasoning' => true, 'provider' => ['npm' => '@ai-sdk/google'],
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => $priced(0.5, 3), 'modalities' => ['input' => ['text', 'image']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
                ],
                'qwen-alibaba' => [
                    'name' => 'Qwen Alibaba', 'tool_call' => true, 'provider' => ['npm' => '@ai-sdk/alibaba'],
                    'limit' => ['context' => 262_144, 'output' => 65_536],
                    'cost' => $priced(0.4, 1.2), 'modalities' => ['input' => ['text']],
                ],
                'gpt-5.3-codex-spark' => [
                    'name' => 'GPT-5.3 Codex Spark', 'tool_call' => true, 'provider' => ['npm' => '@ai-sdk/openai'],
                    'limit' => ['context' => 128_000, 'output' => 32_000], 'cost' => $priced(1.75, 14),
                ],
            ]],
            'opencode-go' => ['models' => [
                // "models.dev reports these models as @ai-sdk/anthropic, but the OpenCode Go endpoints
                // either don't accept Anthropic SDK auth (MiniMax M2.7) or …"
                'minimax-m2.7' => [
                    'name' => 'MiniMax M2.7', 'tool_call' => true, 'reasoning' => true, 'provider' => ['npm' => '@ai-sdk/anthropic'],
                    'limit' => ['context' => 204_800, 'output' => 131_072],
                    'cost' => $priced(0.3, 1.2), 'modalities' => ['input' => ['text']],
                ],
            ]],
            // An alias with no canonical id beside it, and K3 with models.dev's zero price.
            'kimi-code-plan-global' => ['models' => [
                'k2p6' => [
                    'name' => 'K2.6', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 262_144, 'output' => 32_768],
                    'cost' => $priced(0, 0), 'modalities' => ['input' => ['text', 'image']],
                ],
                'k3' => [
                    'name' => 'Kimi K3', 'tool_call' => true, 'reasoning' => false,
                    'limit' => ['context' => 1_048_576, 'output' => 131_072],
                    'cost' => $priced(0, 0), 'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'moonshotai' => ['models' => [
                // "Moonshot does not bill cache writes; models.dev lists the input rate as cache_write
                // for Kimi K3."
                'kimi-k3' => [
                    'name' => 'Kimi K3', 'tool_call' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 131_072],
                    'cost' => ['input' => 3, 'output' => 15, 'cache_read' => 0.3, 'cache_write' => 3, 'tiers' => [['tier' => ['type' => 'context', 'size' => 200_000], 'input' => 6]]],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'alibaba-token-plan' => ['models' => [
                'glm-5' => [
                    'name' => 'GLM-5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 202_752, 'output' => 16_384], 'cost' => $priced(0, 0),
                ],
                'qwen3.8-max' => [
                    'name' => 'Qwen3.8 Max', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 262_144, 'output' => 65_536], 'cost' => $priced(0, 0),
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'medium', 'xhigh']]],
                ],
                'qwen3.8-max-preview' => [
                    'name' => 'Qwen3.8 Max Preview', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 262_144, 'output' => 65_536], 'cost' => $priced(0, 0),
                ],
            ]],
            // `fetchNvidiaNimModelIds()` says which of these NIM serves, and under what spelling.
            'nvidia' => ['models' => [
                'Vendor/Model_X' => [
                    'name' => 'Model X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                ],
                'google/gemma-2-2b-it' => [
                    'name' => 'Gemma 2 2B', 'tool_call' => true,
                    'limit' => ['context' => 8_192, 'output' => 4_096],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                ],
                'vendor/not-served' => [
                    'name' => 'Not Served', 'tool_call' => true,
                    'limit' => ['context' => 8_192, 'output' => 4_096],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                ],
            ]],
            'minimax' => ['models' => [
                'MiniMax-M2.7' => [
                    'name' => 'MiniMax-M2.7', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 204_800, 'output' => 131_072], 'cost' => $priced(0.3, 1.2),
                ],
                'MiniMax-M2' => [
                    'name' => 'MiniMax-M2', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 196_608, 'output' => 128_000], 'cost' => $priced(0.3, 1.2),
                ],
            ]],
            'github-copilot' => ['models' => [
                // Priced, with a tier: Copilot rows carry models.dev's list prices.
                'gpt-5' => [
                    'name' => 'GPT-5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 128_000],
                    'cost' => ['input' => 1.25, 'output' => 10, 'cache_read' => 0.125, 'tiers' => [
                        ['tier' => ['type' => 'context', 'size' => 272_000], 'input' => 2.5, 'output' => 15, 'cache_read' => 0.25],
                    ]],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // On GitHub's extended-context list, at models.dev's 1,050,000.
                'gpt-5.4' => [
                    'name' => 'GPT-5.4', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // Not on it, at the same figure.
                'gpt-5.6-sol' => [
                    'name' => 'GPT-5.6 Sol', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_050_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // One of upstream's hand-added rows that the catalogue now carries.
                'gpt-6-sol' => [
                    'name' => 'GPT-6 Sol (catalogue)', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_000_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'claude-x' => [
                    'name' => 'Claude X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 16_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // GitHub gives it the extended window and models.dev reports a fifth of it.
                'claude-sonnet-4.6' => [
                    'name' => 'Sonnet 4.6', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 200_000, 'output' => 16_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'oswe-thing' => [
                    'name' => 'OSWE Thing', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text']],
                ],
                // Upstream's `needsResponsesApi` prefixes beyond `gpt-5`/`oswe`, and one that is none
                // of them.
                'gpt-6-x' => [
                    'name' => 'GPT-6 X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text']],
                ],
                'grok-x' => [
                    'name' => 'Grok X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text']],
                ],
                'mai-code-x' => [
                    'name' => 'MAI X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text']],
                ],
                'gemini-x' => [
                    'name' => 'Gemini X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 64_000],
                    'modalities' => ['input' => ['text']],
                ],
                'retired' => [
                    'name' => 'Retired', 'tool_call' => true, 'status' => 'deprecated',
                    'limit' => ['context' => 64_000, 'output' => 16_384],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
        ];
    }
}
