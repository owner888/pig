<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Models;
use Pig\Ai\Timestamp;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use PigAntigravity\Catalog;
use PigAntigravity\Discovery;
use PigAntigravity\Grouping;
use PigAntigravity\Routing;
use RuntimeException;

/**
 * pi-antigravity's dynamic model catalog: `models/grouping.ts` folding the runtime models the
 * deployment lists into public ids, and `models/discovery.ts` asking, filing and re-using the
 * answer for four hours.
 */
final class AntigravityDiscoveryTest extends TestCase
{
    use LoadsAntigravity;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        self::registerAntigravity();
        Loop::reset();
        $this->home = sys_get_temp_dir() . '/pig-antigravity-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        putenv('PIG_HOME=' . $this->home);
        putenv('PI_HOME=' . $this->home . '/pi');
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Pig\Ai\Extension\ProviderRegistry::forget();
        Models::forgetRegistered();
        Routing::forgetTables();
        Discovery::forgetCurrent();
        putenv('PIG_HOME');
        putenv('PI_HOME');
        putenv('ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS');
        putenv('ANTIGRAVITY_BASE_URL');

        self::remove($this->home);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }

            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    // ---- grouping -------------------------------------------------------------------------

    public function testRuntimeVariantsCollapseIntoOnePublicModelWithTheLevelsTheyAdvertise(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'gemini-3.9-flash-low' => ['displayName' => 'Gemini 3.9 Flash (Low)', 'model' => 'MODEL_M400'],
            'gemini-3.9-flash-high' => ['displayName' => 'Gemini 3.9 Flash (High)', 'model' => 'MODEL_M401'],
            'gemini-3.9-flash-tiered' => ['displayName' => 'Gemini 3.9 Flash', 'model' => 'MODEL_M402'],
        ], Discovery::fallbackCatalog());

        $model = $catalog['models'][0];
        $template = Models::find('antigravity', 'gemini-3.8-flash');
        $this->assertNotNull($template);

        // Newest Flash first, synthesized from the first Flash the fallback has.
        $this->assertSame('gemini-3.9-flash', $model['id']);
        $this->assertSame('Gemini 3.9 Flash (Antigravity)', $model['name']);
        $this->assertTrue($model['reasoning']);
        $this->assertSame(['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], $model['thinkingLevelMap']);
        $this->assertSame(['text', 'image'], $model['input']);
        $this->assertSame($template->contextWindow, $model['contextWindow']);
        $this->assertSame($template->maxTokens, $model['maxTokens']);

        $this->assertSame([
            'off' => 'gemini-3.9-flash-low',
            'routing' => [
                'minimal' => 'gemini-3.9-flash-low',
                'low' => 'gemini-3.9-flash-low',
                'medium' => 'gemini-3.9-flash-low',
                'high' => 'gemini-3.9-flash-high',
                'xhigh' => 'gemini-3.9-flash-high',
            ],
            'defaultRequestId' => 'gemini-3.9-flash-low',
        ], $catalog['routing']['gemini-3.9-flash']);

        // The fallback's own models stay, with their routing: an account's catalog that omits them
        // still leaves them selectable.
        $this->assertArrayHasKey('claude-opus-4-6', $catalog['routing']);
        $this->assertContains('gemini-3.8-flash', array_column($catalog['models'], 'id'));
    }

    public function testWhatIsNotAModelSomebodyCouldPickIsLeftOut(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'tab_flash_lite' => [],
            'chat_20706' => [],
            'gemini-3-pro-image' => [],
            'MODEL_PLACEHOLDER_M9' => [],
            'gemini-4-secret' => ['isInternal' => true],
            'llama-4' => [],
        ], ['models' => [], 'routing' => []]);

        // Nothing grouped: the fallback, unchanged.
        $this->assertSame(['models' => [], 'routing' => []], $catalog);
        $this->assertFalse(Grouping::isSelectableRuntimeModelId('gemini 4'));
        $this->assertTrue(Grouping::isSelectableRuntimeModelId('gpt-oss-240b-medium'));
    }

    public function testAnAliasAndAnAgentSingletonJoinTheFamilyTheyBelongTo(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'gemini-4-flash-low' => ['displayName' => 'Gemini 4 Flash (Low)'],
            'gemini-4-flash-agent' => ['displayName' => 'Gemini 4 Flash', 'supportsThinking' => true],
            'gemini-3-flash-agent' => ['displayName' => 'Gemini 3.5 Flash (High)'],
        ], ['models' => [], 'routing' => []]);

        $ids = array_column($catalog['models'], 'id');

        // `gemini-3-flash-agent` is the High of 3.5 Flash; the agent singleton is 4 Flash's High.
        $this->assertSame(['gemini-4-flash', 'gemini-3.5-flash'], $ids);
        $this->assertSame('gemini-4-flash-agent', $catalog['routing']['gemini-4-flash']['routing']['high']);
        $this->assertSame('gemini-3-flash-agent', $catalog['routing']['gemini-3.5-flash']['routing']['high']);
        $this->assertArrayNotHasKey('gemini-4-flash-agent', $catalog['routing']);
    }

    public function testAModelWithoutVariantsGetsHighOnlyUnlessTheCatalogSaysItCannotThink(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'claude-opus-5' => ['displayName' => 'Claude Opus 5'],
            'gemini-4-lite' => ['displayName' => 'Gemini 4 Lite', 'supportsThinking' => false, 'supportsImages' => false],
            'gpt-oss-240b' => [],
        ], Discovery::fallbackCatalog());

        $byId = array_column($catalog['models'], null, 'id');

        $opus = $byId['claude-opus-5'];
        $this->assertTrue($opus['reasoning']);
        $this->assertSame('high', $opus['thinkingLevelMap']['high']);
        $this->assertNull($opus['thinkingLevelMap']['low']);
        $this->assertSame(250_000, $opus['contextWindow'], 'the template is the fallback\'s Opus');
        $this->assertSame('claude-opus-5', $catalog['routing']['claude-opus-5']['routing']['xhigh']);

        // "Explicit false from the catalog must not grow a fake High control."
        $lite = $byId['gemini-4-lite'];
        $this->assertFalse($lite['reasoning']);
        $this->assertArrayNotHasKey('thinkingLevelMap', $lite);
        $this->assertSame(['text'], $lite['input']);

        // No display name: the id, humanized.
        $this->assertSame('GPT-OSS 240B (Antigravity)', $byId['gpt-oss-240b']['name']);
        $this->assertSame('Gemini 3.5 Flash', Grouping::humanizePublicId('gemini-3-5-flash'));
    }

    // ---- discovery ------------------------------------------------------------------------

    public function testARefreshAsksTheDeploymentAppliesTheCatalogAndFilesIt(): void
    {
        $server = new CannedServer();
        $url = rtrim($server->start([self::ok(self::payload())]), '/');
        $published = null;
        $publish = static function (array $entry) use (&$published): void {
            $published = $entry;
        };

        $models = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), false, null, $publish, endpoints: [$url]));

        // What went out: the project in the body, the token as a bearer.
        $this->assertSame(['project' => 'proj-1'], $server->receivedJson());
        $this->assertStringContainsStringIgnoringCase('Authorization: Bearer ya29.a', $server->receivedHead());
        $this->assertStringContainsString('POST /v1internal:fetchAvailableModels', $server->receivedHead());

        // What came back is in use.
        $this->assertContains('gemini-3.9-flash', array_column($models, 'id'));
        $this->assertNotNull(Models::find('antigravity', 'gemini-3.9-flash'));
        $this->assertSame(['gemini-3.9-flash-high', 'MODEL_M401'], Routing::resolve('gemini-3.9-flash', 'high'));
        $this->assertSame(Discovery::current()['models'], $models);

        // And filed in pi's shape.
        $this->assertIsArray($published);
        $this->assertSame('antigravity-api', $published['models'][0]['api']);
        $this->assertSame('antigravity', $published['models'][0]['provider']);
        $this->assertSame(['models' => $models, 'routing' => Discovery::current()['routing']], $published['pi-antigravity']['catalog']);
        $this->assertSame('MODEL_M400', $published['pi-antigravity']['modelEnums']['gemini-3.9-flash-low']);
        $this->assertEqualsWithDelta(Timestamp::nowMs(), $published['pi-antigravity']['checkedAt'], 5_000);
    }

    public function testWhatWasFiledIsReadBackAndAnswersUntilTheIntervalIsUp(): void
    {
        $path = Catalog::storePath();
        file_put_contents($path, json_encode(['openai' => ['models' => []]]));
        $catalog = Grouping::buildAntigravityCatalog(self::payload()['models'], Discovery::fallbackCatalog());
        Discovery::persist($path, [
            'models' => Discovery::storedModels($catalog['models']),
            'pi-antigravity' => ['catalog' => $catalog, 'checkedAt' => Timestamp::nowMs() - 1_000, 'modelEnums' => ['gemini-3.9-flash-low' => 'MODEL_M400', 'gemini-3.9-flash-high' => 'MODEL_M401']],
        ]);

        // Every other provider's entry is kept.
        $this->assertArrayHasKey('openai', json_decode((string) file_get_contents($path), true));

        $stored = Catalog::discover();
        $this->assertGreaterThan(0, $stored->checkedAt);

        // Within the interval: from the store, and no request at all.
        $server = new CannedServer();
        $url = rtrim($server->start([self::ok(self::payload())]), '/');
        $models = Async::run(static fn (): array => Discovery::refresh($stored, true, self::key(), false, null, static fn () => null, endpoints: [$url]));

        $this->assertSame(0, $server->connections());
        $this->assertContains('gemini-3.9-flash', array_column($models, 'id'));
        $this->assertNotNull(Models::find('antigravity', 'gemini-3.9-flash'));

        // Forced, or with the interval at 0: asked again.
        putenv('ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS=0');
        Async::run(static fn (): array => Discovery::refresh($stored, true, self::key(), false, null, static fn () => null, endpoints: [$url]));
        $this->assertSame(1, $server->connections());
    }

    public function testOfflineOrWithoutAKeyNothingIsAsked(): void
    {
        $server = new CannedServer();
        $url = rtrim($server->start([self::ok(self::payload())]), '/');

        $offline = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), false, self::key(), true, null, static fn () => null, endpoints: [$url]));
        $keyless = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, null, true, null, static fn () => null, endpoints: [$url]));

        $this->assertSame(0, $server->connections());
        $this->assertSame(Discovery::fallbackCatalog()['models'], $offline);
        $this->assertSame($offline, $keyless);
    }

    public function testAFailedRefreshKeepsTheCatalogAndOnlyAForcedOneSaysSo(): void
    {
        $server = new CannedServer();
        $body = '{"error":{"message":"Bearer ya29.secret denied"}}';
        $url = rtrim($server->start(["HTTP/1.1 500 Oops\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]), '/');

        $kept = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), false, null, static fn () => null, endpoints: [$url]));
        $this->assertSame(Discovery::fallbackCatalog()['models'], $kept);

        try {
            Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), true, null, static fn () => null, endpoints: [$url]));
            $this->fail('a forced refresh that failed should say so');
        } catch (RuntimeException $error) {
            $this->assertSame('/v1internal:fetchAvailableModels failed: no endpoint available', $error->getMessage());
            // The endpoint's own answer is the cause, with the token taken out.
            $this->assertSame('Bearer [redacted-access-token] denied', $error->getPrevious()?->getMessage());
        }
    }

    public function testAnEmptyAnswerChangesNothing(): void
    {
        $server = new CannedServer();
        $url = rtrim($server->start([self::ok(['models' => new \stdClass()])]), '/');
        $published = false;
        $publish = static function () use (&$published): void {
            $published = true;
        };

        $models = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), true, null, $publish, endpoints: [$url]));

        $this->assertFalse($published);
        $this->assertSame(Discovery::fallbackCatalog()['models'], $models);
    }

    public function testEndpointAnswersMergeInOrderAndNoneIsAnError(): void
    {
        $merged = Discovery::mergeAvailableModelsResults([
            ['endpoint' => 'a', 'status' => 200, 'data' => ['models' => ['x' => ['model' => 'one'], 'y' => []], 'defaultAgentModelId' => 'x']],
            null,
            ['endpoint' => 'b', 'status' => 200, 'data' => ['models' => ['x' => ['model' => 'two'], 'z' => []]]],
        ]);

        $this->assertSame('b', $merged['endpoint']);
        $this->assertSame(['x' => ['model' => 'two'], 'y' => [], 'z' => []], $merged['data']['models']);
        $this->assertSame('x', $merged['data']['defaultAgentModelId']);

        $this->expectExceptionMessage('/v1internal:fetchAvailableModels failed: no endpoint available');
        Discovery::mergeAvailableModelsResults([null]);
    }

    public function testTheIntervalTheEndpointAndTheKeyAreReadAsUpstreamReadsThem(): void
    {
        $this->assertSame(4 * 60 * 60 * 1000, Discovery::catalogRefreshIntervalMs());
        putenv('ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS=12abc');
        $this->assertSame(12, Discovery::catalogRefreshIntervalMs());
        putenv('ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS=-5');
        $this->assertSame(Discovery::DEFAULT_CATALOG_REFRESH_INTERVAL_MS, Discovery::catalogRefreshIntervalMs());

        $this->assertSame(Discovery::ENDPOINT_FALLBACKS, Discovery::endpointCandidates());
        putenv('ANTIGRAVITY_BASE_URL=https://autopush-cloudcode-pa.sandbox.googleapis.com/');
        $this->assertSame(['https://autopush-cloudcode-pa.sandbox.googleapis.com'], Discovery::endpointCandidates());

        foreach ([
            'http://cloudcode-pa.googleapis.com' => 'ANTIGRAVITY_BASE_URL must use https (got http:)',
            'https://user:pw@cloudcode-pa.googleapis.com' => 'ANTIGRAVITY_BASE_URL must not include credentials',
            'https://evil.example' => 'ANTIGRAVITY_BASE_URL host "evil.example" is not allowed. Use a *.googleapis.com endpoint.',
        ] as $raw => $message) {
            try {
                Discovery::assertSafeApiBaseUrl($raw);
                $this->fail("{$raw} should be refused");
            } catch (RuntimeException $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }

        $this->assertSame(['token' => 't', 'projectId' => 'p'], Discovery::parseApiKey('{"token":"t","projectId":"p"}'));

        try {
            Discovery::parseApiKey('{"token":"t"}');
            $this->fail('a key with no project should be refused');
        } catch (RuntimeException $error) {
            $this->assertSame('Invalid Antigravity credentials. Run /login antigravity. (missing token or projectId)', $error->getMessage());
        }
    }

    /** @return array{models: array<string, array<string, mixed>>} */
    private static function payload(): array
    {
        return ['models' => [
            'gemini-3.9-flash-low' => ['displayName' => 'Gemini 3.9 Flash (Low)', 'model' => 'MODEL_M400'],
            'gemini-3.9-flash-high' => ['displayName' => 'Gemini 3.9 Flash (High)', 'model' => 'MODEL_M401'],
            'gemini-3.8-flash-low' => ['displayName' => 'Gemini 3.8 Flash (Low)', 'model' => 'MODEL_PLACEHOLDER_M320'],
        ]];
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): string
    {
        $body = (string) json_encode($data);

        return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }

    private static function key(): string
    {
        return (string) json_encode(['token' => 'ya29.a', 'projectId' => 'proj-1']);
    }
}
