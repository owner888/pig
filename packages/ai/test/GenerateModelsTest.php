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
        $output = $this->run();

        self::assertStringNotContainsString('no-tools', $output);
        self::assertStringContainsString("'takes-tools' => [", $output);
    }

    public function testARetiredCopilotModelIsNotOffered(): void
    {
        // Copilot marks one rather than removing it, so the row stays in the catalogue.
        $output = $this->run();

        self::assertStringNotContainsString('retired', $output);
    }

    public function testCopilotsApiIsDecidedByTheIdBecauseTheCatalogueDoesNotSay(): void
    {
        $output = $this->run();

        self::assertStringContainsString("'gpt-5' => ['GPT-5', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'oswe-thing' => ['OSWE Thing', Api::OpenAiResponses,", $output);
        self::assertStringContainsString("'claude-x' => ['Claude X', Api::OpenAiCompletions,", $output);
    }

    public function testCopilotRowsCarryNoPricesBecauseASubscriptionIsNotMeteredPerToken(): void
    {
        $output = $this->run();

        // Six cells and no money: name, api, window, output, reasoning, images.
        self::assertStringContainsString(
            "'claude-x' => ['Claude X', Api::OpenAiCompletions, 128_000, 16_000, true, true],",
            $output,
        );
    }

    public function testTheCacheCorrectionIsAppliedAndSaysWhy(): void
    {
        $output = $this->run();

        // models.dev reports 1.5/18.75, which is three times the real figure.
        self::assertStringContainsString('models.dev has 3x the real cache pricing', $output);
        self::assertStringContainsString(
            "'claude-opus-4-5' => ['Claude Opus 4.5', 200_000, 64_000, true, 5.0, 25.0, 0.5, 6.25],",
            $output,
        );
    }

    public function testAModelTheCatalogueDoesNotCarryIsAddedAndSaysSo(): void
    {
        $output = $this->run();

        self::assertStringContainsString('openai/gpt-5-chat-latest added by hand', $output);
        self::assertStringContainsString("'gpt-5-chat-latest' => ['GPT-5 Chat Latest',", $output);
    }

    public function testAMissingLimitIsComplainedAboutRatherThanDefaultedQuietly(): void
    {
        // Upstream's `|| 4096` turns a missing window into a number small enough to make every
        // conversation look nearly full. The default is kept so the row is usable; the complaint
        // is what gets somebody to look.
        $output = $this->run();

        self::assertStringContainsString('openai/no-limits has no context window', $output);
        self::assertStringContainsString('openai/no-limits has no output limit', $output);
        self::assertStringContainsString("'no-limits' => ['No Limits', 4_096, 4_096,", $output);
    }

    public function testAnAnthropicModelWithNoImagesIsComplainedAboutBecauseTheTableCannotSayIt(): void
    {
        // `ANTHROPIC_MODELS` has no images column, so the assumption behind it is checked rather
        // than left to be wrong silently one day.
        $output = $this->run();

        self::assertStringContainsString('anthropic/text-only takes no images', $output);
    }

    public function testImagesAndReasoningAreReadOffTheCatalogueAndNotGuessed(): void
    {
        $output = $this->run();

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
        $output = $this->run();

        self::assertStringContainsString('xai/grok-code-fast-1 is listed and not offered', $output);
        self::assertStringNotContainsString("'grok-code-fast-1' => [", $output);

        // And the exclusion is per provider, so the rest of xAI is untouched.
        self::assertStringContainsString("'grok-x' => ['Grok X',", $output);
    }

    public function testACorrectionThatChangesNothingSaysSoRatherThanClaimingToCorrect(): void
    {
        // The day models.dev fixes its own figure, a blind merge would go on reporting
        // "corrected" for ever and nobody would retire the override.
        $catalogue = self::catalogue();
        $catalogue['anthropic']['models']['claude-opus-4-5']['cost'] = [
            'input' => 5, 'output' => 25, 'cache_read' => 0.5, 'cache_write' => 6.25,
        ];
        file_put_contents($this->fixture, (string) json_encode($catalogue));

        $output = $this->run();

        self::assertStringContainsString(
            'the override for anthropic/claude-opus-4-5 changes nothing any more',
            $output,
        );
        self::assertStringNotContainsString('claude-opus-4-5 corrected', $output);
    }

    public function testADryRunWritesNothing(): void
    {
        $before = (string) file_get_contents(__DIR__ . '/../src/Models.php');
        $this->run();

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

        $output = $this->run();

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

    private function run(): string
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
                'claude-opus-4-5' => [
                    'name' => 'Claude Opus 4.5', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 200_000, 'output' => 64_000],
                    'cost' => ['input' => 5, 'output' => 25, 'cache_read' => 1.5, 'cache_write' => 18.75],
                    'modalities' => ['input' => ['text', 'image']],
                ],
            ]],
            'openai' => ['models' => [
                'no-limits' => [
                    'name' => 'No Limits', 'tool_call' => true, 'modalities' => ['input' => ['text']],
                ],
            ]],
            'google' => ['models' => [
                'gemini-9-flash' => [
                    'name' => 'Gemini 9 Flash', 'tool_call' => true, 'reasoning' => true,
                    'limit' => ['context' => 1_048_576, 'output' => 65_536],
                    'cost' => ['input' => 0.3, 'output' => 2.5, 'cache_read' => 0.075],
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
                'oswe-thing' => [
                    'name' => 'OSWE Thing', 'tool_call' => true, 'reasoning' => true,
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
