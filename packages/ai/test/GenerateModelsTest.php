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

    public function testCopilotRowsCarryNoPricesBecauseASubscriptionIsNotMeteredPerToken(): void
    {
        $output = $this->generated();

        // Six cells and no money: name, api, window, output, reasoning, images.
        self::assertStringContainsString(
            "'claude-x' => ['Claude X', Api::OpenAiCompletions, 128_000, 16_000, true, true],",
            $output,
        );
    }

    public function testACorrectionIsAppliedAndSaysWhy(): void
    {
        $output = $this->generated();

        // A context window is where compaction fires, so a Copilot model reported at a fifth of
        // its real window is a conversation summarised with four times the room it thought.
        self::assertStringContainsString('github-copilot/claude-sonnet-4.6 corrected', $output);
        self::assertStringContainsString('GitHub gives it the extended window', $output);
        self::assertStringContainsString(
            "'claude-sonnet-4.6' => ['Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 16_000, true, true],",
            $output,
        );
    }

    public function testALevelTheEndpointRefusesIsWrittenAsATenthCellAndOnlyThere(): void
    {
        $output = $this->generated();

        // The measurement lives in the override; the row carries it as a map `Models::all()` reads.
        self::assertStringContainsString('google/gemini-3.1-pro-preview corrected: only works in thinking mode', $output);
        self::assertStringContainsString(
            "'gemini-3.1-pro-preview' => ['Gemini 3.1 Pro Preview', 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, ['off' => null, 'minimal' => null]],",
            $output,
        );
        // And a row with nothing to say keeps its nine cells.
        self::assertStringContainsString(
            "'gemini-9-flash' => ['Gemini 9 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0],",
            $output,
        );
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
            "'gemini-9-flash' => ['Gemini 9 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0],",
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

    public function testACorrectionThatChangesNothingSaysSoRatherThanClaimingToCorrect(): void
    {
        // The day models.dev fixes its own figure, a blind merge would go on reporting
        // "corrected" for ever and nobody would retire the override.
        // Exactly what happened on the second real regeneration: the first `fix` here corrected
        // Anthropic's cache pricing, models.dev fixed its own figure, the run said so, and the
        // override was retired in one line rather than re-derived.
        $catalogue = self::catalogue();
        $catalogue['github-copilot']['models']['claude-sonnet-4.6']['limit']['context'] = 1_000_000;
        file_put_contents($this->fixture, (string) json_encode($catalogue));

        $output = $this->generated();

        self::assertStringContainsString(
            'the override for github-copilot/claude-sonnet-4.6 changes nothing any more',
            $output,
        );
        self::assertStringNotContainsString('claude-sonnet-4.6 corrected', $output);
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
        // And Google's are not recorded upstream, so its row keeps its nine cells.
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
        $catalogue = self::catalogue();
        unset($catalogue['mistral']);
        file_put_contents($this->fixture, (string) json_encode($catalogue));

        $output = $this->generated();

        self::assertStringContainsString('mistral is not in this catalogue at all', $output);
        self::assertStringContainsString('nothing to write for mistral, so its table is left as it is', $output);
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
                // One the overrides carry a level map for, so the tenth cell can be seen rendered.
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
                // `none` alone is enough for a map: it is off's word.
                'none-only' => [
                    'name' => 'None Only', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 8_192],
                    'cost' => $priced(0.1, 0.2),
                    'reasoning_options' => [['type' => 'effort', 'values' => ['none']]],
                ],
            ]],
            'mistral' => ['models' => [
                'small' => [
                    'name' => 'Small', 'tool_call' => true,
                    'limit' => ['context' => 128_000, 'output' => 128_000],
                    'cost' => $priced(0.1, 0.3), 'modalities' => ['input' => ['text', 'image']],
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
            'zai' => ['models' => [
                'glm-x' => [
                    'name' => 'GLM X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 131_072, 'output' => 98_304],
                    'cost' => $priced(0, 0), 'modalities' => ['input' => ['text']],
                ],
            ]],
            'github-copilot' => ['models' => [
                'gpt-5' => [
                    'name' => 'GPT-5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 128_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                'claude-x' => [
                    'name' => 'Claude X', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 128_000, 'output' => 16_000],
                    'modalities' => ['input' => ['text', 'image']],
                ],
                // The one the `fix` arm corrects: GitHub gives it the extended window and
                // models.dev reports a fifth of it.
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
