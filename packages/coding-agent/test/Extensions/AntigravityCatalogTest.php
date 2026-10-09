<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use PigAntigravity\Routing;
use Pig\Ai\Models;
use PigAntigravity\Catalog;

/**
 * The catalogue the `pi-antigravity` extension leaves on disk.
 *
 * Two shapes and one rule. The shapes are pi's `models-store.json`, where the tables sit under
 * the provider and the extension's own key, and the older `antigravity-model-catalog.json`,
 * which is the tables and no enums. The rule is that a routing table and an enum table are one
 * snapshot: half of one installed is a thinking level that throws at the moment somebody picks
 * it, so it is both or neither.
 */
final class AntigravityCatalogTest extends TestCase
{
    use LoadsAntigravity;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        self::registerAntigravity();
        $this->home = sys_get_temp_dir() . '/pig-antigravity-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        // Both homes, and not the same directory: `Catalog::read()` looks at pig's own store
        // before pi's, so on a machine that has run the extension the real catalog answered and
        // sixteen models came back — and one directory for both reads the fixture twice.
        putenv('PI_HOME=' . $this->home);
        mkdir($this->home . '/pig', 0o700);
        putenv('PIG_HOME=' . $this->home . '/pig');
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Pig\Ai\Extension\ProviderRegistry::forget();
        putenv('PI_HOME');
        putenv('PIG_HOME');
        Models::forgetRegistered();
        Routing::forgetTables();

        rmdir($this->home . '/pig');

        foreach (glob($this->home . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->home);
    }

    public function testTheCatalogueWinsOverTheRowPigShipsForTheSameModel(): void
    {
        // The whole reason it is read: both tables are prints of the same endpoint, and this one
        // was taken more recently. What it visibly costs to get this backwards is the price —
        // pig's own rows carry `new Pricing()`, so a subscription turn reports as free.
        $shipped = Models::find('antigravity', 'gemini-3.8-flash');

        self::assertNotNull($shipped);
        self::assertSame(0.0, $shipped->pricing->input);

        $this->writeStore(['gemini-3.8-flash' => ['cost' => ['input' => 0.1, 'output' => 0.4]]]);
        Catalog::discover()->install();

        $fresh = Models::find('antigravity', 'gemini-3.8-flash');

        self::assertNotNull($fresh);
        self::assertSame(0.1, $fresh->pricing->input);
        self::assertSame(0.4, $fresh->pricing->output);
    }

    public function testAntigravitysModelsCarryTheInputLimitsUpstreamsGeneratorGivesSuchAProvider(): void
    {
        // `applyImageInputMetadata()`: a provider it has no limits for — every provider but
        // Anthropic, Bedrock, OpenAI and Google itself, so `google-vertex`, the other way to Gemini,
        // among them — gets the default resize profile on an image-taking model and nothing else;
        // a text-only model gets nothing. Both the shipped rows and the catalogue's carried none.
        $resize = ['maxWidth' => 2000, 'maxHeight' => 2000, 'maxBytes' => 4_718_592, 'jpegQuality' => 80];

        self::assertSame(['images' => ['resize' => $resize]], Models::find('antigravity', 'gemini-3.8-flash')?->inputLimits);
        self::assertNull(Models::find('antigravity', 'gpt-oss-120b')?->inputLimits);

        $this->writeStore(['gemini-3.8-flash' => ['input' => ['text', 'image']]]);
        Catalog::discover()->install();

        self::assertSame(['images' => ['resize' => $resize]], Models::find('antigravity', 'gemini-3.8-flash')?->inputLimits);
    }

    public function testTheCatalogueReplacesTheRowRatherThanSittingBesideIt(): void
    {
        $this->writeStore(['gemini-3.8-flash' => []]);
        Catalog::discover()->install();

        $rows = array_filter(Models::all(), static fn ($model): bool => $model->provider === 'antigravity');
        $ids = array_map(static fn ($model): string => $model->id, array_values($rows));

        self::assertSame(array_unique($ids), $ids, 'the same model listed twice');
    }

    public function testAModelTheCatalogueAddsIsReachable(): void
    {
        $this->writeStore(['gemini-4-flash' => []]);
        Catalog::discover()->install();

        self::assertNotNull(Models::find('antigravity', 'gemini-4-flash'));
        self::assertSame(['gemini-4-flash-low', 'MODEL_TEST_LOW'], Routing::resolve('gemini-4-flash', 'low'));
    }

    public function testARoutingTargetWithNoEnumRefusesTheWholeCatalogueAndSaysWhich(): void
    {
        // Not "the models whose routing happened to be complete": the two tables are one
        // snapshot, and keeping half of one is how the routing and the model list come to
        // disagree about which models exist.
        $this->writeStore(['gemini-4-flash' => []], enums: []);

        $catalogue = Catalog::discover();

        self::assertSame([], $catalogue->models);
        self::assertSame([], $catalogue->routing);
        self::assertCount(1, $catalogue->problems);
        self::assertStringContainsString('gemini-4-flash-low', $catalogue->problems[0]);

        $catalogue->install();

        self::assertNull(Models::find('antigravity', 'gemini-4-flash'));
        self::assertTrue(Routing::knows('gemini-3.8-flash'), 'the generated tables should still be in use');
    }

    public function testAModelNothingRoutesToIsLeftOutAndNamed(): void
    {
        // The catalogue also enumerates the Antigravity IDE's own models — tab completion, its
        // chat surfaces — and a model no routing reaches is one every turn would fail on.
        $store = $this->store(['gemini-4-flash' => []]);
        $store['antigravity']['pi-antigravity']['catalog']['models'][] = [
            'id' => 'chat_23310',
            'name' => 'The IDE own chat model',
            'contextWindow' => 1000,
            'maxTokens' => 100,
        ];

        file_put_contents($this->home . '/models-store.json', json_encode($store));

        $catalogue = Catalog::discover();

        self::assertCount(1, $catalogue->models);
        self::assertSame('gemini-4-flash', $catalogue->models[0]->id);
        self::assertCount(1, $catalogue->problems);
        self::assertStringContainsString('chat_23310', $catalogue->problems[0]);
    }

    public function testAStoreWithNoAntigravityInItIsPassedOverInSilence(): void
    {
        // Every `models-store.json` written by a pi that never ran the extension, which is most
        // of them. A warning on every start is how people learn to skip the ones that matter.
        file_put_contents(
            $this->home . '/models-store.json',
            json_encode(['google' => ['models' => []], 'anthropic' => ['models' => []]]),
        );

        $catalogue = Catalog::discover();

        self::assertSame([], $catalogue->models);
        self::assertSame([], $catalogue->problems);
    }

    public function testAnAntigravitySectionWithNoCatalogueInItIsNamed(): void
    {
        // The one case worth a line: the extension wrote its section and what is in it is not a
        // catalogue, so a catalogue is silently not taking effect.
        file_put_contents(
            $this->home . '/models-store.json',
            json_encode(['antigravity' => ['pi-antigravity' => ['checkedAt' => 1]]]),
        );

        $catalogue = Catalog::discover();

        self::assertCount(1, $catalogue->problems);
        self::assertStringContainsString('models-store.json', $catalogue->problems[0]);
    }

    public function testTheOlderCatalogueFileAnswersWhenTheStoreHasNothing(): void
    {
        // `antigravity-model-catalog.json` is what an *older* extension wrote — the current one
        // mentions it nowhere — so it is a fallback rather than a source. It carries no enums,
        // which is why its routing may only name runtime ids the generated table already knows.
        file_put_contents($this->home . '/antigravity-model-catalog.json', json_encode([
            'version' => 1,
            'checkedAt' => 1,
            'models' => [[
                'id' => 'gemini-3.8-flash',
                'name' => 'Gemini 3.8 Flash (Antigravity)',
                'contextWindow' => 1_048_576,
                'maxTokens' => 65_536,
                'reasoning' => true,
                'cost' => ['input' => 0.1],
            ]],
            'routing' => ['gemini-3.8-flash' => [
                'off' => 'gemini-3.8-flash-low',
                'defaultRequestId' => 'gemini-3.8-flash-low',
                'routing' => ['medium' => 'gemini-3.8-flash-medium'],
            ]],
        ]));

        $catalogue = Catalog::discover();

        self::assertCount(1, $catalogue->models);
        self::assertSame([], $catalogue->enums);
        self::assertSame([], $catalogue->problems);

        $catalogue->install();

        self::assertSame(['gemini-3.8-flash-medium', 'MODEL_PLACEHOLDER_M319'], Routing::resolve('gemini-3.8-flash', 'medium'));
    }

    public function testTheStoreIsPreferredOverTheOlderFile(): void
    {
        // A fallback rather than a merge, for `CustomModels::discover()`'s reason: one of them is
        // the current answer and the other is a copy of what the answer used to be, and merging
        // two snapshots of one table is how a model that was withdrawn comes back.
        $this->writeStore(['gemini-4-flash' => []]);
        file_put_contents($this->home . '/antigravity-model-catalog.json', json_encode([
            'models' => [['id' => 'ancient-model', 'contextWindow' => 1, 'maxTokens' => 1]],
            'routing' => ['ancient-model' => ['defaultRequestId' => 'gemini-3.8-flash-low', 'routing' => []]],
        ]));

        $ids = array_map(static fn ($model): string => $model->id, Catalog::discover()->models);

        self::assertSame(['gemini-4-flash'], $ids);
    }

    public function testAbsentNullAndAStringAreThreeDifferentThingsInTheLevelMap(): void
    {
        // `Model::hasThinkingLevel()` reads all three, so this only has to preserve them: a key
        // that is not there means the level is sent under its own name, and a key that is null
        // means the model does not have that level at all.
        $this->writeStore(['gemini-4-flash' => ['thinkingLevelMap' => ['off' => null, 'low' => 'low']]]);

        $model = Catalog::discover()->models[0];

        self::assertArrayHasKey('off', $model->thinkingLevelMap);
        self::assertNull($model->thinkingLevelMap['off']);
        self::assertSame('low', $model->thinkingLevelMap['low']);
        self::assertArrayNotHasKey('medium', $model->thinkingLevelMap);
        self::assertFalse($model->hasThinkingLevel('off'));
        self::assertTrue($model->hasThinkingLevel('medium'));
    }

    public function testNoneReadsNothingAtAll(): void
    {
        // What `--no-save` gets, and the flag means this run touches nothing of the person's.
        $this->writeStore(['gemini-4-flash' => []]);

        self::assertSame([], Catalog::none()->models);
    }

    /**
     * A store holding one entry per id given, with a routing entry and an enum for each.
     *
     * @param array<string, array<string, mixed>> $models id => whatever to override on its row
     * @param array<string, string>|null          $enums  null for the matching enums, [] for none
     */
    private function writeStore(array $models, ?array $enums = null): void
    {
        file_put_contents($this->home . '/models-store.json', json_encode($this->store($models, $enums)));
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @param array<string, string>|null          $enums
     *
     * @return array<string, mixed>
     */
    private function store(array $models, ?array $enums = null): array
    {
        $rows = [];
        $routing = [];
        $made = [];

        foreach ($models as $id => $overrides) {
            $rows[] = [
                'id' => $id,
                'name' => $id,
                'reasoning' => true,
                'input' => ['text', 'image'],
                'contextWindow' => 1_048_576,
                'maxTokens' => 65_536,
                ...$overrides,
            ];

            $routing[$id] = [
                'off' => $id . '-low',
                'defaultRequestId' => $id . '-low',
                'routing' => ['low' => $id . '-low', 'medium' => $id . '-medium'],
            ];

            $made[$id . '-low'] = 'MODEL_TEST_LOW';
            $made[$id . '-medium'] = 'MODEL_TEST_MEDIUM';
        }

        return ['antigravity' => ['models' => $rows, 'pi-antigravity' => [
            'catalog' => ['models' => $rows, 'routing' => $routing],
            'checkedAt' => 1,
            'modelEnums' => $enums ?? $made,
        ]]];
    }
}
