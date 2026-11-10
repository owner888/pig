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
    use \Pig\Test\CapturesLog;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        self::registerAntigravity();
        Loop::reset();
        $this->home = sys_get_temp_dir() . '/pig-antigravity-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0o700, true);
        putenv('PIG_CODING_AGENT_DIR=' . $this->home);
        putenv('PI_HOME=' . $this->home . '/pi');
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Pig\Ai\Extension\ProviderRegistry::forget();
        Models::forgetRegistered();
        Routing::forgetTables();
        Discovery::forgetCurrent();
        putenv('PIG_CODING_AGENT_DIR');
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

        // The answer is the catalog: a model it does not list is not put back from the fallback,
        // which is how a retired one used to stay selectable and fail when picked.
        $this->assertSame(['gemini-3.9-flash'], array_keys($catalog['routing']));
        $this->assertSame(['gemini-3.9-flash'], array_column($catalog['models'], 'id'));
    }

    public function testTheLimitsAndCapabilitiesTheAnswerStatesWinOverTheTemplates(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'gemini-3.9-flash-low' => ['displayName' => 'Gemini 3.9 Flash (Low)', 'maxTokens' => 2_000_000, 'maxOutputTokens' => 65_536, 'supportsImages' => true],
            // The smallest stated limit, since the public model may route to either; a limit that
            // is not a positive whole number says nothing; one variant without pictures decides.
            'gemini-3.9-flash-high' => ['displayName' => 'Gemini 3.9 Flash (High)', 'maxTokens' => 1_000_000, 'maxOutputTokens' => 0, 'supportsImages' => false],
            // Levels read off the names do not outvote the answer saying it cannot think.
            'gemini-3.9-nano-low' => ['displayName' => 'Gemini 3.9 Nano (Low)', 'supportsThinking' => false],
        ], Discovery::fallbackCatalog());

        $byId = array_column($catalog['models'], null, 'id');

        $this->assertSame(1_000_000, $byId['gemini-3.9-flash']['contextWindow']);
        $this->assertSame(65_536, $byId['gemini-3.9-flash']['maxTokens']);
        $this->assertSame(['text'], $byId['gemini-3.9-flash']['input']);
        $this->assertFalse($byId['gemini-3.9-nano']['reasoning']);
        $this->assertArrayNotHasKey('thinkingLevelMap', $byId['gemini-3.9-nano']);
    }

    public function testOnlyTheModelsGooglesOwnPickerListsAreKeptUnlessThatLeavesNone(): void
    {
        $raw = [
            'gemini-4-argon-low' => ['displayName' => 'Gemini 4 Argon (Low)'],
            'gemini-4-argon-high' => ['displayName' => 'Gemini 4 Argon (High)'],
            'gemini-pro-agent' => ['displayName' => 'Gemini 3.1 Pro (High)'],
            'gemini-2.5-pro' => ['displayName' => 'Gemini 2.5 Pro'],
        ];
        $sorts = [['displayName' => 'Recommended', 'groups' => [['modelIds' => ['gemini-4-argon-low', 'gemini-4-argon-high', '', 7]], 'not a group']], 'not a sort'];

        $curated = Grouping::buildAntigravityCatalog($raw, Discovery::fallbackCatalog(), $sorts);
        $this->assertSame(['gemini-4-argon'], array_column($curated['models'], 'id'));
        $this->assertSame('gemini-4-argon-high', $curated['routing']['gemini-4-argon']['routing']['high']);

        // Sorts that name nothing the answer has, and sorts that are not sorts: everything, as before.
        $everything = ['gemini-2.5-pro', 'gemini-pro-agent', 'gemini-4-argon'];
        $this->assertSame($everything, array_column(Grouping::buildAntigravityCatalog($raw, Discovery::fallbackCatalog(), [['groups' => [['modelIds' => ['gone']]]]])['models'], 'id'));
        $this->assertSame($everything, array_column(Grouping::buildAntigravityCatalog($raw, Discovery::fallbackCatalog(), [])['models'], 'id'));
        $this->assertNull(Grouping::extractRecommendedModelIds(['a' => 1]));
        $this->assertSame(['x' => true], Grouping::extractRecommendedModelIds([['groups' => [['modelIds' => ['x', 'x']]]]]));
    }

    public function testWhatIsNotAModelSomebodyCouldPickIsLeftOut(): void
    {
        $raw = [
            'tab_flash_lite' => [],
            'chat_20706' => [],
            'gemini-3-pro-image' => [],
            'nano-banana' => ['displayName' => 'Gemini Image Preview'],
            'MODEL_PLACEHOLDER_M9' => [],
            'gemini-4-secret' => ['isInternal' => true],
            'gemini-4-inhouse' => ['apiProvider' => 'API_PROVIDER_INTERNAL'],
        ];

        // Nothing grouped is not "no models": it is an answer this cannot use, and it says so
        // rather than handing back a fallback that would be filed as the deployment's.
        try {
            Grouping::buildAntigravityCatalog($raw, Discovery::fallbackCatalog());
            $this->fail('a catalog with nothing to pick should be refused');
        } catch (RuntimeException $error) {
            $this->assertSame('Antigravity catalog contains no supported chat models', $error->getMessage());
        }

        // Any family is one somebody can pick; the id only has to be an id.
        $catalog = Grouping::buildAntigravityCatalog([...$raw, 'llama-4' => []], ['models' => [], 'routing' => []]);
        $this->assertSame(['llama-4'], array_column($catalog['models'], 'id'));

        $this->assertFalse(Grouping::isSelectableRuntimeModelId('gemini 4'));
        $this->assertFalse(Grouping::isSelectableRuntimeModelId("gemini-4\n"));
        $this->assertFalse(Grouping::isSelectableRuntimeModelId('-gemini-4'));
        $this->assertTrue(Grouping::isSelectableRuntimeModelId('gpt-oss-240b-medium'));
        $this->assertTrue(Grouping::isSelectableRuntimeModelId('argon_4.0'));
    }

    public function testAnAgentSingletonJoinsTheFamilyItsDisplayNameNamesAtTheLevelItStates(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'gemini-4-flash-low' => ['displayName' => 'Gemini 4 Flash (Low)', 'maxTokens' => 500_000],
            'gemini-4-flash-agent' => ['displayName' => 'Gemini 4 Flash (High)', 'supportsThinking' => true, 'maxTokens' => 400_000],
            // No level in the name: nothing says which level it is, so it stays its own model.
            'gemini-4-pro-low' => ['displayName' => 'Gemini 4 Pro (Low)'],
            'gemini-4-pro-agent' => ['displayName' => 'Gemini 4 Pro'],
            // The level it states is one the family already has a runtime for: left apart too.
            'gemini-5-flash-high' => ['displayName' => 'Gemini 5 Flash (High)'],
            'gemini-5-flash-agent' => ['displayName' => 'Gemini 5 Flash (High)'],
            // No table of aliases any more: with no family beside it, it is listed under its own id.
            'gemini-3-flash-agent' => ['displayName' => 'Gemini 3.5 Flash (High)'],
        ], ['models' => [], 'routing' => []]);

        $this->assertSame(
            ['gemini-5-flash', 'gemini-5-flash-agent', 'gemini-4-flash', 'gemini-3-flash-agent', 'gemini-4-pro', 'gemini-4-pro-agent'],
            array_column($catalog['models'], 'id'),
        );
        $this->assertSame('gemini-4-flash-agent', $catalog['routing']['gemini-4-flash']['routing']['high']);
        $this->assertArrayNotHasKey('gemini-4-flash-agent', $catalog['routing']);
        // What the singleton states comes with it: the smaller window is the family's.
        $this->assertSame(400_000, array_column($catalog['models'], null, 'id')['gemini-4-flash']['contextWindow']);
        $this->assertSame('gemini-5-flash-high', $catalog['routing']['gemini-5-flash']['routing']['high']);
    }

    public function testAModelWithoutVariantsHasALevelOnlyWhenTheCatalogSaysItThinks(): void
    {
        $catalog = Grouping::buildAntigravityCatalog([
            'claude-opus-5' => ['displayName' => 'Claude Opus 5'],
            'claude-opus-6' => ['displayName' => 'Claude Opus 6', 'supportsThinking' => true],
            'gemini-4-lite' => ['displayName' => 'Gemini 4 Lite', 'supportsThinking' => false, 'supportsImages' => false],
            'gpt-oss-240b' => [],
        ], Discovery::fallbackCatalog());

        $byId = array_column($catalog['models'], null, 'id');

        // Unsaid: it reasons because its family's template does, and no level is invented for it.
        $opus = $byId['claude-opus-5'];
        $this->assertTrue($opus['reasoning']);
        $this->assertSame(array_fill_keys(['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], null), $opus['thinkingLevelMap']);
        $this->assertSame(250_000, $opus['contextWindow'], 'the template is the fallback\'s Opus');
        $this->assertSame('claude-opus-5', $catalog['routing']['claude-opus-5']['routing']['xhigh']);

        // Said: High, the one level a model with no variants can be asked for.
        $this->assertSame('high', $byId['claude-opus-6']['thinkingLevelMap']['high']);
        $this->assertNull($byId['claude-opus-6']['thinkingLevelMap']['low']);

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

        // With which account it was asked, in upstream's bytes: sha256 of JSON.stringify([key, project]).
        $this->assertSame(2, $published['pi-antigravity']['schemaVersion']);
        $this->assertSame(hash('sha256', '["' . addcslashes(self::key(), '"\\') . '","proj-1"]'), $published['pi-antigravity']['credentialKey']);
        $this->assertSame(hash('sha256', '["1//refresh","proj-1"]'), Discovery::credentialKey(self::key(), '1//refresh'));

        // The version the deployment gates its newest models on.
        $this->assertStringContainsStringIgnoringCase('User-Agent: antigravity/cli/1.4.0 (aidev_client; ', $server->receivedHead());
    }

    public function testTheModelsTheAnswersPickerListsAreTheCatalog(): void
    {
        $server = new CannedServer();
        $payload = self::payload();
        $payload['agentModelSorts'] = [['groups' => [['modelIds' => ['gemini-3.9-flash-low', 'gemini-3.9-flash-high']]]]];
        $url = rtrim($server->start([self::ok($payload)]), '/');

        $models = Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), false, null, static fn () => null, endpoints: [$url]));

        $this->assertSame(['gemini-3.9-flash'], array_column($models, 'id'));
        $this->assertNull(Models::find('antigravity', 'gemini-3.8-flash'), 'listed by the deployment, not by its picker');

        // A model the catalog dropped is not answered from the generated tables, and says what to do.
        try {
            Routing::resolve('gemini-3.8-flash', 'low');
            $this->fail('a model the current catalog does not have should be refused');
        } catch (\Pig\Ai\ProviderError $error) {
            $this->assertSame('Antigravity model gemini-3.8-flash is absent from the current catalog. Next: run /antigravity.refresh and select an available model.', $error->getMessage());
        }
    }

    public function testWhatWasFiledIsReadBackAndAnswersUntilTheIntervalIsUp(): void
    {
        $path = Catalog::storePath();
        file_put_contents($path, json_encode(['openai' => ['models' => []]]));
        $catalog = Grouping::buildAntigravityCatalog(self::payload()['models'], Discovery::fallbackCatalog());
        $section = ['catalog' => $catalog, 'checkedAt' => Timestamp::nowMs() - 1_000, 'modelEnums' => ['gemini-3.9-flash-low' => 'MODEL_M400', 'gemini-3.9-flash-high' => 'MODEL_M401']];
        $file = static fn (array $more): mixed => Discovery::persist($path, [
            'models' => Discovery::storedModels($catalog['models']),
            'pi-antigravity' => [...$section, ...$more],
        ]);
        $file(['schemaVersion' => Discovery::CATALOG_SCHEMA_VERSION, 'credentialKey' => Discovery::credentialKey(self::key(), 'refresh-a')]);

        // Every other provider's entry is kept.
        $this->assertArrayHasKey('openai', json_decode((string) file_get_contents($path), true));

        $stored = Catalog::discover();
        $this->assertGreaterThan(0, $stored->checkedAt);
        $this->assertSame(Discovery::CATALOG_SCHEMA_VERSION, $stored->schemaVersion);

        // Within the interval, for the same account: from the store, and no request at all.
        $server = new CannedServer();
        $url = rtrim($server->start([self::ok(self::payload())]), '/');
        $models = Async::run(static fn (): array => Discovery::refresh($stored, true, self::key(), false, null, static fn () => null, endpoints: [$url], refreshToken: 'refresh-a'));

        $this->assertSame(0, $server->connections());
        $this->assertContains('gemini-3.9-flash', array_column($models, 'id'));
        $this->assertNotNull(Models::find('antigravity', 'gemini-3.9-flash'));

        // Forced, or with the interval at 0: asked again.
        putenv('ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS=0');
        Async::run(static fn (): array => Discovery::refresh($stored, true, self::key(), false, null, static fn () => null, endpoints: [$url], refreshToken: 'refresh-a'));
        $this->assertSame(1, $server->connections());
    }

    public function testACatalogFiledForAnotherAccountOrByAnOlderVersionIsAskedForAgain(): void
    {
        $path = Catalog::storePath();
        $catalog = Grouping::buildAntigravityCatalog(self::payload()['models'], Discovery::fallbackCatalog());
        $section = ['catalog' => $catalog, 'checkedAt' => Timestamp::nowMs() - 1_000, 'modelEnums' => ['gemini-3.9-flash-low' => 'MODEL_M400', 'gemini-3.9-flash-high' => 'MODEL_M401']];
        $ask = static function (array $more, string $refreshToken) use ($path, $catalog, $section): int {
            Discovery::persist($path, ['models' => Discovery::storedModels($catalog['models']), 'pi-antigravity' => [...$section, ...$more]]);
            $stored = Catalog::discover();
            $server = new CannedServer();
            $url = rtrim($server->start([self::ok(self::payload())]), '/');
            Async::run(static fn (): array => Discovery::refresh($stored, true, self::key(), false, null, static fn () => null, endpoints: [$url], refreshToken: $refreshToken));

            return $server->connections();
        };
        $mine = Discovery::credentialKey(self::key(), 'refresh-a');

        // Fresh by the clock every time; what differs is whose it is.
        $this->assertSame(0, $ask(['schemaVersion' => 2, 'credentialKey' => $mine], 'refresh-a'));
        $this->assertSame(1, $ask(['schemaVersion' => 2, 'credentialKey' => $mine], 'refresh-b'), 'another account');
        $this->assertSame(1, $ask(['schemaVersion' => 1, 'credentialKey' => $mine], 'refresh-a'), 'an older shape');
        $this->assertSame(1, $ask([], 'refresh-a'), 'filed before there was a version at all');
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

        $kept = $this->capturingLog(static fn (): array => Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), false, null, static fn () => null, endpoints: [$url])));
        $this->assertSame(Discovery::fallbackCatalog()['models'], $kept);
        $this->assertSame(['Antigravity model catalog refresh failed; keeping the last catalog: /v1internal:fetchAvailableModels failed: no endpoint returned a valid non-empty catalog'], $this->loggedWarnings());

        try {
            Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), true, null, static fn () => null, endpoints: [$url]));
            $this->fail('a forced refresh that failed should say so');
        } catch (RuntimeException $error) {
            $this->assertSame('/v1internal:fetchAvailableModels failed: no endpoint returned a valid non-empty catalog', $error->getMessage());
            // The endpoint's own answer is the cause, with the token taken out.
            $this->assertSame('Bearer [redacted-access-token] denied', $error->getPrevious()?->getMessage());
        }
    }

    public function testAnEmptyAnswerChangesNothingAndIsAFailureNotACatalog(): void
    {
        $published = false;
        $publish = static function () use (&$published): void {
            $published = true;
        };

        // No models at all, and models with nothing in them somebody could pick.
        foreach ([
            [['models' => new \stdClass()], '/v1internal:fetchAvailableModels failed: no endpoint returned a valid non-empty catalog'],
            [['models' => ['chat_1' => new \stdClass(), 'tab_2' => new \stdClass()]], 'Antigravity catalog contains no supported chat models'],
        ] as [$answer, $message]) {
            $server = new CannedServer();
            $url = rtrim($server->start([self::ok($answer), self::ok($answer)]), '/');

            // Unforced: the catalog in use stays, and the log says why.
            $models = $this->capturingLog(static fn (): array => Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), false, null, $publish, endpoints: [$url])));
            $this->assertSame(Discovery::fallbackCatalog()['models'], $models);
            $warnings = $this->loggedWarnings();
            $this->assertSame("Antigravity model catalog refresh failed; keeping the last catalog: {$message}", end($warnings));

            // Forced: said to whoever asked.
            try {
                Async::run(static fn (): array => Discovery::refresh(Catalog::none(), true, self::key(), true, null, $publish, endpoints: [$url]));
                $this->fail('a forced refresh with nothing in the answer should say so');
            } catch (RuntimeException $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }

        $this->assertFalse($published);
    }

    public function testEndpointAnswersMergeInOrderAndNoneIsAnError(): void
    {
        $merged = Discovery::mergeAvailableModelsResults([
            ['endpoint' => 'a', 'status' => 200, 'data' => ['models' => ['x' => ['model' => 'one'], 'y' => []], 'defaultAgentModelId' => 'x', 'agentModelSorts' => [['groups' => []]]]],
            null,
            ['endpoint' => 'b', 'status' => 200, 'data' => ['models' => ['x' => ['model' => 'two'], 'z' => []]]],
            // Answers that are not a catalog do not count, and are not "the last endpoint" either.
            ['endpoint' => 'c', 'status' => 200, 'data' => ['models' => []]],
            ['endpoint' => 'd', 'status' => 200, 'data' => ['models' => ['w' => 'not an entry']]],
            ['endpoint' => 'e', 'status' => 200, 'data' => 'not an answer'],
        ]);

        $this->assertSame('b', $merged['endpoint']);
        $this->assertSame(['x' => ['model' => 'two'], 'y' => [], 'z' => []], $merged['data']['models']);
        $this->assertSame('x', $merged['data']['defaultAgentModelId']);
        $this->assertSame([['groups' => []]], $merged['data']['agentModelSorts']);

        $this->expectExceptionMessage('/v1internal:fetchAvailableModels failed: no endpoint returned a valid non-empty catalog');
        Discovery::mergeAvailableModelsResults([null, ['endpoint' => 'c', 'status' => 200, 'data' => ['models' => []]]]);
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
