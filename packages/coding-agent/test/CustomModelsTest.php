<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Models;
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
            'apiKey' => 'MY_BOX_KEY',
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

        // Upstream's four key names, so a `models.json` written for pi works here unchanged.
        $this->assertNotNull($model->compat);
        $this->assertFalse($model->compat->store);
        $this->assertFalse($model->compat->reasoningEffort);
        $this->assertTrue($model->compat->developerRole, 'unmentioned keys keep their default');
        $this->assertSame('max_tokens', $model->compat->maxTokensField);
    }

    // ---- the key ---------------------------------------------------------------------------

    public function testTheApiKeyIsAVariableNameBeforeItIsAKey(): void
    {
        putenv('MY_BOX_KEY=sk-from-the-environment');

        $auth = Auth::inMemory();
        $this->load(self::provider())->install($auth);

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

    public function testAuthHeaderPutsTheResolvedKeyInTheHeaders(): void
    {
        putenv('MY_BOX_KEY=sk-resolved');

        $model = $this->load(self::provider(['authHeader' => true]))->models[0];

        // Resolved, not the variable's name: a proxy given `Bearer MY_BOX_KEY` answers 401
        // and says nothing about why.
        $this->assertSame('Bearer sk-resolved', $model->headers['Authorization']);
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
        $this->assertSame('MY_BOX_KEY', $auth->apiKey('my-box'));
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
