<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CustomModels;

/**
 * Providers declared in `models.json`.
 *
 * Two halves worth testing separately: what a good file produces, and what a bad one says.
 * The second is most of the file, because this is the one config a person writes by hand with
 * no schema in front of them, and every way it can be wrong lands on a model that silently is
 * not there.
 */
final class CustomModelsTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pig-models-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Or every test after these ones sees `my-box`. `Models` is static by design, so the
        // cleanup is the price of the seam.
        Models::forgetRegistered();

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
        putenv('MY_BOX_KEY');
    }

    private function write(string $json): string
    {
        $path = $this->directory . '/models.json';
        file_put_contents($path, $json);

        return $path;
    }

    /** @param array<string, mixed> $provider */
    private function load(array $provider, string $name = 'my-box'): CustomModels
    {
        return CustomModels::load($this->write((string) json_encode(['providers' => [$name => $provider]])));
    }

    /** @param array<string, mixed> $overrides */
    private static function provider(array $overrides = []): array
    {
        return [
            'baseUrl' => 'http://192.168.1.9:8080/v1',
            // A literal rather than `MY_BOX_KEY`: a variable name with nothing behind it is now a
            // complaint of its own, and a fixture shared by thirty tests should not carry one.
            'apiKey' => 'sk-a-literal-key',
            'api' => 'openai-completions',
            'models' => [self::model()],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function model(array $overrides = []): array
    {
        return [
            'id' => 'qwen3-coder',
            'name' => 'Qwen3 Coder',
            'reasoning' => false,
            'input' => ['text'],
            'contextWindow' => 262_144,
            'maxTokens' => 32_768,
            ...$overrides,
        ];
    }

    // ---- a good file -----------------------------------------------------------------------

    public function testADeclaredModelBecomesOneTheRegistryKnows(): void
    {
        $this->load(self::provider())->install();

        $model = Models::find('my-box', 'qwen3-coder');

        $this->assertNotNull($model);
        $this->assertSame('Qwen3 Coder', $model->name);
        $this->assertSame(Api::OpenAiCompletions, $model->api);
        $this->assertSame(262_144, $model->contextWindow);

        // Which is the whole point: everything downstream asks `Models` by id, so a model
        // only `--models` could see would be one that stops working the moment it is used.
        $this->assertSame('my-box', Models::get('qwen3-coder')?->provider);
    }

    // ---- what a model calls each thinking level -------------------------------------------

    public function testAThinkingLevelMapIsCarriedOverFromTheFile(): void
    {
        // Upstream's key and upstream's spelling, so a `models.json` written for pi keeps
        // working here — which is this file's whole standing rule.
        $model = $this->load(self::provider([
            'models' => [self::model(['reasoning' => true, 'thinkingLevelMap' => [
                'off' => null,
                'minimal' => null,
                'high' => 'max',
            ]])],
        ]))->models[0];

        $this->assertNull($model->thinkingEffort('off'));
        $this->assertFalse($model->hasThinkingLevel('minimal'));
        $this->assertSame('max', $model->thinkingEffort('high'));
        // Not mentioned, so not changed — a partial map says only what is unusual.
        $this->assertSame('low', $model->thinkingEffort('low'));
    }

    public function testAMissingKeyAndAKeySetToNullStayDifferentThroughTheFile(): void
    {
        // JSON can express both and PHP can lose the difference in a dozen ways on the way in.
        // `"medium": null` means the model has no medium; leaving `medium` out means it does.
        $model = $this->load(self::provider([
            'models' => [self::model(['reasoning' => true, 'thinkingLevelMap' => ['medium' => null]])],
        ]))->models[0];

        $this->assertFalse($model->hasThinkingLevel('medium'));
        $this->assertTrue($model->hasThinkingLevel('high'));
    }

    public function testALevelPigHasNoNameForIsKeptRatherThanRefused(): void
    {
        // Upstream has a `max` level and pig does not. Refusing the file over it would break the
        // promise that a `models.json` written for pi works here; nothing ever asks for it.
        $problems = $this->load(self::provider([
            'models' => [self::model(['reasoning' => true, 'thinkingLevelMap' => ['max' => 'max']])],
        ]))->problems;

        $this->assertSame([], $problems);
    }

    public function testRubbishInTheMapIsDroppedWithoutTakingTheModelWithIt(): void
    {
        $custom = $this->load(self::provider([
            'models' => [self::model(['reasoning' => true, 'thinkingLevelMap' => [
                'high' => 7,
                'nonsense' => 'x',
                'low' => 'low',
            ]])],
        ]));

        $this->assertCount(1, $custom->models);
        // The number is not a level name, so `high` falls back to being sent as itself.
        $this->assertSame('high', $custom->models[0]->thinkingEffort('high'));
        $this->assertSame('low', $custom->models[0]->thinkingEffort('low'));
    }

    public function testTheBaseUrlLosesItsTrailingSlash(): void
    {
        $model = $this->load(self::provider(['baseUrl' => 'http://box:8080/v1/']))->models[0];

        // Every caller appends `/chat/completions`, so a kept slash is a double one.
        $this->assertSame('http://box:8080/v1', $model->baseUrl);
    }

    public function testAModelsOwnApiWinsOverTheProvidersDefault(): void
    {
        $custom = $this->load(self::provider(['models' => [
            self::model(),
            self::model(['id' => 'claude-ish', 'api' => 'anthropic-messages']),
        ]]));

        $this->assertSame(Api::OpenAiCompletions, $custom->models[0]->api);
        $this->assertSame(Api::AnthropicMessages, $custom->models[1]->api);
    }

    public function testHeadersMergeWithTheModelsOwnWinning(): void
    {
        $model = $this->load(self::provider([
            'headers' => ['X-Tenant' => 'acme', 'X-Keep' => 'yes'],
            'models' => [self::model(['headers' => ['X-Tenant' => 'other']])],
        ]))->models[0];

        $this->assertSame(['X-Tenant' => 'other', 'X-Keep' => 'yes'], $model->headers);
    }

    public function testCostIsOptionalBecauseALocalModelIsFree(): void
    {
        $model = $this->load(self::provider())->models[0];

        $this->assertSame(0.0, $model->pricing->input);
        $this->assertSame(0.0, $model->pricing->output);
    }

    /**
     * And a `cost` block that *is* there is read, in dollars per million tokens.
     *
     * Only the absent case had a test, so the reading was right and nothing would have caught a
     * regression in it — which is also how a live run reporting `the model is priced at zero` came
     * to look like a missing feature rather than a `models.json` with no prices in it. The four
     * names and the unit are upstream's, so a file written for pi carries its prices over.
     */
    public function testACostBlockIsReadInDollarsPerMillionTokens(): void
    {
        $model = $this->load(self::provider(['models' => [self::model([
            'cost' => ['input' => 0.28, 'output' => 0.42, 'cacheRead' => 0.028, 'cacheWrite' => 1],
        ])]]))->models[0];

        $this->assertSame(0.28, $model->pricing->input);
        $this->assertSame(0.42, $model->pricing->output);
        $this->assertSame(0.028, $model->pricing->cacheRead);

        // A whole number in the file is still a price.
        $this->assertSame(1.0, $model->pricing->cacheWrite);
    }

    /**
     * A price that is not a number is named, not read as free.
     *
     * `"input": "0.28"` with the quotes left on is the mistake to expect, and `/session`, the
     * footer and `--list-models` all report money — so a model that silently costs nothing
     * misreports it every turn. Refused the way every other typed field here is refused.
     */
    #[DataProvider('everyWayACostCanBeWrong')]
    public function testAPriceThatIsNotANumberIsRefusedRatherThanReadAsFree(mixed $cost, string $says): void
    {
        $custom = $this->load(self::provider(['models' => [self::model(['cost' => $cost])]]));

        $this->assertSame([], $custom->models);
        $this->assertCount(1, $custom->problems);
        $this->assertStringContainsString($says, $custom->problems[0]);
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function everyWayACostCanBeWrong(): iterable
    {
        yield 'a quoted number' => [['input' => '0.28'], '"cost.input" must be dollars per million'];
        yield 'a negative price' => [['output' => -1.0], '"cost.output" must be dollars per million'];
        yield 'null in a field' => [['cacheRead' => []], '"cost.cacheRead" must be dollars per million'];
        yield 'not an object' => ['0.28', '"cost" must be an object'];
    }

    public function testNoCompatBlockLeavesItToBeWorkedOutFromTheUrl(): void
    {
        // Null rather than a set of defaults, so `OpenAiCompat::detect()` still runs — which
        // is what gets a local llama.cpp the loose settings it needs with nothing written.
        $this->assertNull($this->load(self::provider())->models[0]->compat);
    }

    public function testACompatBlockUsesUpstreamsSpellings(): void
    {
        $model = $this->load(self::provider(['models' => [self::model(['compat' => [
            'supportsStore' => false,
            'supportsReasoningEffort' => false,
            'maxTokensField' => 'max_tokens',
        ]])]]))->models[0];

        // All eight of upstream's key names, so a `models.json` written for pi works here
        // unchanged. This used to assert three of them and this method used to be right about
        // three of them: upstream declares all eight and pig read four under names of its own.
        $this->assertNotNull($model->compat);
        $this->assertFalse($model->compat->store);
        $this->assertFalse($model->compat->reasoningEffort);
        $this->assertSame('max_tokens', $model->compat->maxTokensField);
        // An unmentioned key is null — "not said" — and detection decides it later
        // (`OpenAiCompat::resolve()`). This used to assert `true`, the default it was filled
        // with, which is what made a one-key block switch detection off for every other key.
        $this->assertNull($model->compat->developerRole, 'unmentioned keys are left to detection');
    }

    public function testTheFourKeysWithARequiresPrefixAreUpstreamsToo(): void
    {
        $model = $this->load(self::provider(['models' => [self::model(['compat' => [
            'requiresToolResultName' => true,
            'requiresAssistantAfterToolResult' => true,
            'requiresThinkingAsText' => true,
            'requiresMistralToolIds' => true,
        ]])]]))->models[0];

        // Read under pig's own shorter spellings, these four were silently ignored — and the one
        // that matters most is the last: a Mistral-shaped endpoint rejects a tool id that is not
        // exactly nine alphanumeric characters, so somebody who had configured it correctly for pi
        // got a 400 with nothing on screen to connect it to.
        $this->assertNotNull($model->compat);
        $this->assertTrue($model->compat->toolResultName);
        $this->assertTrue($model->compat->assistantAfterToolResult);
        $this->assertTrue($model->compat->thinkingAsText);
        $this->assertTrue($model->compat->mistralToolIds);
    }

    public function testRequiresReasoningContentOnAssistantMessagesIsReadFromACompatBlock(): void
    {
        // Upstream's compat key for DeepSeek-style endpoints. Detection only finds `deepseek.com`
        // or a provider called `deepseek`; a proxy in front of DeepSeek needs to say it in
        // `models.json`, and pig ignored the key, so that proxy kept answering 400.
        $model = $this->load(self::provider(['models' => [self::model(['compat' => [
            'requiresReasoningContentOnAssistantMessages' => true,
        ]])]]))->models[0];

        $this->assertNotNull($model->compat);
        $this->assertTrue($model->compat->reasoningContentOnAssistantMessages);

        $plain = $this->load(self::provider(['models' => [self::model(['compat' => [
            'supportsStore' => false,
        ]])]]))->models[0];

        $this->assertNotNull($plain->compat);
        // Null and not `false`: left to detection, which turns it on for DeepSeek. The old
        // `false` here meant a DeepSeek model whose block only said `supportsStore` lost the flag.
        $this->assertNull($plain->compat->reasoningContentOnAssistantMessages, 'unmentioned keys are left to detection');
    }

    /**
     * The fix end to end: a DeepSeek endpoint declared with a `compat` block that says one thing
     * still gets everything detection says about DeepSeek for the rest — upstream's
     * `getCompat()`, `model.compat.x ?? detected.x`. Before, the block replaced detection, so
     * this model was sent `store`, the `developer` role and `max_completion_tokens` (which
     * DeepSeek ignores, leaving the output uncapped) and lost the `reasoning_content` filler.
     */
    public function testACompatBlockIsLaidOverDetectionKeyByKey(): void
    {
        $model = $this->load(self::provider([
            'baseUrl' => 'https://api.deepseek.com/v1',
            'models' => [self::model(['reasoning' => true, 'compat' => ['requiresThinkingAsText' => true]])],
        ]))->models[0];

        $resolved = OpenAiCompat::resolve($model);

        $this->assertTrue($resolved->thinkingAsText, 'what the block says');
        $this->assertFalse($resolved->store);
        $this->assertFalse($resolved->developerRole);
        $this->assertSame('max_tokens', $resolved->maxTokensField);
        $this->assertTrue($resolved->reasoningContentOnAssistantMessages);
    }

    /**
     * Upstream's `mergeCompat(providerConfig.compat, definition.compat)`: a provider-level block
     * applies to each of its models, and a model's own keys win over it. pig read only the
     * model's, so a block written once for the provider — the way pi's docs show it — did nothing.
     */
    public function testAProviderCompatBlockAppliesToItsModelsAndAModelsOwnKeysWin(): void
    {
        $models = $this->load(self::provider([
            'compat' => ['supportsStore' => false, 'maxTokensField' => 'max_tokens'],
            'models' => [
                self::model(),
                self::model(['id' => 'other', 'compat' => ['maxTokensField' => 'max_completion_tokens']]),
            ],
        ]))->models;

        $this->assertFalse($models[0]->compat?->store);
        $this->assertSame('max_tokens', $models[0]->compat?->maxTokensField);
        $this->assertFalse($models[1]->compat?->store, 'the provider\'s key, which the model does not set');
        $this->assertSame('max_completion_tokens', $models[1]->compat?->maxTokensField, 'the model\'s own key');
    }

    /**
     * `thinkingFormat` and its template values, under upstream's names. A provider's
     * `chatTemplateKwargs` and a model's are merged a level deeper rather than one replacing the
     * other — upstream's `mergeCompat()` treats the template objects that way.
     */
    public function testAThinkingFormatAndItsTemplateValuesAreReadAndMergedKeyByKey(): void
    {
        $model = $this->load(self::provider([
            'compat' => ['thinkingFormat' => 'chat-template', 'chatTemplateKwargs' => ['a' => 1, 'b' => 2]],
            'models' => [self::model(['compat' => ['chatTemplateKwargs' => ['b' => 3, 'c' => ['$var' => 'thinking.enabled']]]])],
        ]))->models[0];

        $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
        $this->assertSame('chat-template', $model->compat->thinkingFormat);
        $this->assertSame(['a' => 1, 'b' => 3, 'c' => ['$var' => 'thinking.enabled']], $model->compat->chatTemplateKwargs);
        $this->assertNull($model->compat->chatTemplateArgs, 'not said');
    }

    /**
     * Upstream's `openRouterRouting` and `vercelGatewayRouting`, read under its names and merged
     * provider → model one level deep by `mergeCompat()` (both are on its list of object keys), so
     * a provider-wide preference and a model's own order both reach the request. These keys were
     * read as nothing before: a pi `models.json` routing OpenRouter lost its routing here.
     */
    public function testRoutingObjectsAreReadAndMergedFromProviderToModelKeyByKey(): void
    {
        $model = $this->load(self::provider([
            'compat' => [
                'openRouterRouting' => ['allow_fallbacks' => false, 'order' => ['a']],
                'vercelGatewayRouting' => ['only' => ['bedrock']],
            ],
            'models' => [self::model(['compat' => ['openRouterRouting' => ['order' => ['anthropic']]]])],
        ]))->models[0];

        $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
        $this->assertSame(['allow_fallbacks' => false, 'order' => ['anthropic']], $model->compat->openRouterRouting);
        $this->assertSame(['only' => ['bedrock']], $model->compat->vercelGatewayRouting);

        // Not said is null — not sent — and a list is not an object.
        $plain = $this->load(self::provider([
            'compat' => ['supportsStore' => false, 'openRouterRouting' => ['a', 'b']],
        ]))->models[0];
        $this->assertInstanceOf(OpenAiCompat::class, $plain->compat);
        $this->assertNull($plain->compat->openRouterRouting);
        $this->assertNull($plain->compat->vercelGatewayRouting);
    }

    /**
     * An `anthropic-messages` model's block is upstream's `AnthropicMessagesCompat`: the way to say
     * a proxied Claude takes adaptive thinking only, now that nothing at request time reads the id.
     */
    public function testAnAnthropicModelsCompatBlockIsAnthropicsOwn(): void
    {
        $model = $this->load(self::provider([
            'api' => 'anthropic-messages',
            'models' => [self::model(['compat' => ['forceAdaptiveThinking' => true, 'supportsStrictTools' => true]])],
        ]))->models[0];

        $this->assertInstanceOf(AnthropicCompat::class, $model->compat);
        $this->assertTrue($model->compat->forceAdaptiveThinking);
        $this->assertTrue($model->compat->strictTools);
    }

    // ---- the key ---------------------------------------------------------------------------

    public function testTheApiKeyIsAVariableNameBeforeItIsAKey(): void
    {
        putenv('MY_BOX_KEY=sk-from-the-environment');

        $auth = Auth::inMemory();
        $this->load(self::provider(['apiKey' => 'MY_BOX_KEY']))->install($auth);

        // The reason the order is this way round: this is the one config file in pig whose
        // natural contents are a credential, and a file in a repository is a credential in a
        // repository.
        $this->assertSame('sk-from-the-environment', $auth->apiKey('my-box'));
    }

    public function testAKeyNoVariableAnswersToIsUsedAsItself(): void
    {
        $auth = Auth::inMemory();
        $this->load(self::provider(['apiKey' => 'sk-written-in-the-file']))->install($auth);

        $this->assertSame('sk-written-in-the-file', $auth->apiKey('my-box'));
    }

    /**
     * A name with no variable behind it is no key, not a key that happens to be that name.
     *
     * Upstream's `resolveApiKeyConfig` falls back to the value whenever the environment has
     * nothing, so a `models.json` that names `DEEPSEEK_API_KEY` on a machine where it is not set
     * hands the *name* on as a credential: `hasKeyFor()` says yes, the models are listed and
     * switchable, and the request leaves as `Authorization: Bearer DEEPSEEK_API_KEY`. That is the
     * 401-explaining-nothing this class's docblock gives as the reason for resolving at all,
     * reached through the fallback. Found while pointing `test/live.php` at a declared endpoint.
     */
    public function testAVariableNameWithNothingBehindItIsNotAKey(): void
    {
        putenv('MY_BOX_KEY');

        $auth = Auth::inMemory();
        $custom = $this->load(self::provider(['apiKey' => 'MY_BOX_KEY']));
        $custom->install($auth);

        $this->assertNull($auth->apiKey('my-box'));
        $this->assertFalse($auth->hasKeyFor('my-box'), 'and so the models are not offered');

        // Said where every other thing wrong with this file is said: a 401 from an endpoint
        // explains nothing about a file, and this names the variable to fill in.
        $this->assertCount(1, $custom->problems);
        $this->assertStringContainsString('"apiKey" names MY_BOX_KEY, which is not set', $custom->problems[0]);
    }

    /** And a value that is not shaped like a variable name is still taken literally. */
    public function testAKeyThatCouldNotBeAVariableNameIsNeverMistakenForOne(): void
    {
        $auth = Auth::inMemory();
        $custom = $this->load(self::provider(['apiKey' => 'sk-lowercase-and-dashes']));
        $custom->install($auth);

        $this->assertSame('sk-lowercase-and-dashes', $auth->apiKey('my-box'));
        $this->assertSame([], $custom->problems);
    }

    /**
     * An empty key is no key, and it keeps the complaint it already had.
     *
     * Two ways to have no key and they should not both be described as an unset variable: this one
     * was already named at the point the file is read, so the new check has to leave it alone.
     */
    public function testAnEmptyApiKeyIsNoKeyAndKeepsItsOwnComplaint(): void
    {
        $auth = Auth::inMemory();
        $custom = $this->load(self::provider(['apiKey' => '']));
        $custom->install($auth);

        $this->assertNull($auth->apiKey('my-box'));
        $this->assertCount(1, $custom->problems);
        $this->assertStringContainsString('no "apiKey"', $custom->problems[0]);
        $this->assertStringNotContainsString('which is not set', $custom->problems[0]);
    }

    public function testAuthHeaderPutsTheResolvedKeyInTheHeaders(): void
    {
        putenv('MY_BOX_KEY=sk-resolved');

        $model = $this->load(self::provider(['apiKey' => 'MY_BOX_KEY', 'authHeader' => true]))->models[0];

        // Resolved, not the variable's name: a proxy given `Bearer MY_BOX_KEY` answers 401
        // and says nothing about why.
        $this->assertSame('Bearer sk-resolved', $model->headers['Authorization']);
    }

    /** And with no key to put there, the header is left off rather than sent empty. */
    public function testAuthHeaderWithNoKeyBehindItAddsNoHeader(): void
    {
        putenv('MY_BOX_KEY');

        $model = $this->load(self::provider(['apiKey' => 'MY_BOX_KEY', 'authHeader' => true]))->models[0];

        $this->assertArrayNotHasKey('Authorization', $model->headers);
    }

    public function testWithoutAuthHeaderNothingIsAddedToTheHeaders(): void
    {
        $this->assertArrayNotHasKey('Authorization', $this->load(self::provider())->models[0]->headers);
    }

    public function testAProviderWhoseModelsAllFailedStillHandsOverItsKey(): void
    {
        $auth = Auth::inMemory();
        $custom = $this->load(self::provider(['models' => [self::model(['id' => ''])]]));
        $custom->install($auth);

        // So a fixed file next run does not also need the key put somewhere new.
        $this->assertSame([], $custom->models);
        $this->assertSame('sk-a-literal-key', $auth->apiKey('my-box'));
    }

    // ---- a built-in always wins ---------------------------------------------------------

    public function testAFileCannotRedefineAModelThatShips(): void
    {
        $before = Models::find('anthropic', 'claude-sonnet-4-5');

        $this->load([
            'baseUrl' => 'http://evil/v1',
            'apiKey' => 'x',
            'api' => 'openai-completions',
            'models' => [self::model(['id' => 'claude-sonnet-4-5', 'name' => 'Not This One'])],
        ], name: 'anthropic')->install();

        // A config file quietly replacing a shipped model is a bug report nobody could read.
        $this->assertSame($before?->name, Models::find('anthropic', 'claude-sonnet-4-5')?->name);
        $this->assertSame($before?->baseUrl, Models::find('anthropic', 'claude-sonnet-4-5')?->baseUrl);
    }

    // ---- a bad file names what is wrong ---------------------------------------------------

    public function testAFileThatIsNotJsonIsNamedRatherThanIgnored(): void
    {
        $custom = CustomModels::load($this->write('{ "providers": '));

        $this->assertSame([], $custom->models);
        $this->assertCount(1, $custom->problems);
        $this->assertStringContainsString('not valid JSON', $custom->problems[0]);
    }

    public function testAFileWithNoProvidersSaysSo(): void
    {
        $custom = CustomModels::load($this->write('{"models": []}'));

        $this->assertStringContainsString('no "providers"', $custom->problems[0]);
    }

    public function testAFileThatIsNotThereIsNotAProblem(): void
    {
        $custom = CustomModels::load($this->directory . '/nothing.json');

        // Not having one is the normal case, and a warning on every start would train people
        // to ignore the warnings that matter.
        $this->assertSame([], $custom->models);
        $this->assertSame([], $custom->problems);
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: string}>
     */
    public static function badProviders(): array
    {
        return [
            [['apiKey' => 'K', 'api' => 'openai-completions', 'models' => [self::model()]], 'no "baseUrl"'],
            [['baseUrl' => 'http://b', 'api' => 'openai-completions', 'models' => [self::model()]], 'no "apiKey"'],
            [['baseUrl' => 'http://b', 'apiKey' => 'K', 'api' => 'openai-completions'], 'no "models"'],
            [self::provider(['api' => null, 'models' => [self::model()]]), '"api" must be one of'],
            [self::provider(['api' => 'telepathy']), '"api" must be one of'],
            [self::provider(['models' => [self::model(['id' => ''])]]), 'no "id"'],
            [self::provider(['models' => [self::model(['name' => ''])]]), 'no "name"'],
            [self::provider(['models' => [self::model(['contextWindow' => 0])]]), '"contextWindow" must be'],
            [self::provider(['models' => [self::model(['contextWindow' => 'lots'])]]), '"contextWindow" must be'],
            [self::provider(['models' => [self::model(['maxTokens' => -1])]]), '"maxTokens" must be'],
        ];
    }

    /** @param array<string, mixed> $provider */
    #[\PHPUnit\Framework\Attributes\DataProvider('badProviders')]
    public function testEveryWayAProviderCanBeWrongIsNamed(array $provider, string $expected): void
    {
        $custom = $this->load($provider);

        $this->assertSame([], $custom->models, $expected);
        $this->assertNotSame([], $custom->problems);
        $this->assertStringContainsString($expected, $custom->problems[0]);

        // And it says *where*, because a file with four providers in it needs more than a
        // description of the mistake.
        $this->assertStringContainsString('my-box', $custom->problems[0]);
        $this->assertStringContainsString('models.json', $custom->problems[0]);
    }

    public function testOneBadModelDoesNotTakeTheGoodOnesWithIt(): void
    {
        $custom = $this->load(self::provider(['models' => [
            self::model(),
            self::model(['id' => 'broken', 'maxTokens' => 0]),
            self::model(['id' => 'fine']),
        ]]));

        // A bad line loses only itself — the same rule `Settings` and the skill loader follow.
        $this->assertSame(['qwen3-coder', 'fine'], array_map(static fn ($m): string => $m->id, $custom->models));
        $this->assertCount(1, $custom->problems);
        $this->assertStringContainsString('broken', $custom->problems[0]);
    }
}
