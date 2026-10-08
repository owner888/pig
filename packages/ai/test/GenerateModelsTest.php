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

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'models-dev-');

        if ($path === false) {
            self::fail('could not make a temporary file');
        }

        $this->fixture = $path;
        file_put_contents($this->fixture, (string) json_encode(self::catalogue()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->fixture)) {
            unlink($this->fixture);
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

    public function testOnlyCopilotLeavesADeprecatedModelOut(): void
    {
        // Upstream's generator skips `status: "deprecated"` for Copilot (and providers pig does not
        // generate) and nowhere else among these; pig used to skip it for every provider.
        $output = $this->generated();

        self::assertStringNotContainsString("'retired' => [", $output);
        self::assertStringContainsString("'old-llama' => ['Old Llama',", $output);
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
                '%s %s --from %s --dry-run 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(self::script()),
                escapeshellarg($this->fixture),
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
