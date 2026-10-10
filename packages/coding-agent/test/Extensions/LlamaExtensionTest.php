<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ClassifierApi;
use Pig\Ai\Context;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\ModelType;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\UserMessage;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\Extensions\Llama\HuggingFaceClient;
use Pig\Extensions\Llama\LlamaClient;
use Pig\Extensions\Llama\LlamaExtension;
use Pig\Extensions\Llama\LlamaProvider;
use Pig\Extensions\Llama\LlamaUi;
use Pig\Test\ScriptedServer;

// The extension's classes, as its `index.php` requires them — the class below implements one.
foreach (['LlamaClient', 'HuggingFaceClient', 'RefreshModelsContext', 'LlamaApiKeyAuth', 'LlamaProvider', 'LlamaUi', 'HuggingFaceSearch', 'LlamaView', 'LlamaExtension'] as $class) {
    require_once dirname(__DIR__, 4) . "/extensions/pig-llama/{$class}.php";
}

/**
 * The llama.cpp extension (`extensions/pig-llama`, upstream's `extensions/llama/`) against a fake
 * router and a fake Hugging Face Hub behind loopback sockets.
 */
final class LlamaExtensionTest extends TestCase
{
    private string $dir;

    private ?ScriptedServer $router = null;

    /** @var list<array<string, mixed>> what the fake router lists */
    private array $catalog = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pig-llama-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);

        foreach (['PIG_HOME', 'LLAMA_BASE_URL', 'LLAMA_API_KEY', 'HF_TOKEN', 'HF_TOKEN_PATH', 'HF_HOME', 'XDG_CACHE_HOME', 'PIG_OFFLINE'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }

        putenv("PIG_HOME={$this->dir}");

    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->router?->stop();
        ProviderRegistry::forget();
        Models::forgetRegistered();

        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testTheFolderLoadsAsAnExtensionWithTheProviderAndTheCommand(): void
    {
        $root = dirname(__DIR__, 4);
        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$root . '/extensions/pig-llama/index.php'], home: $this->dir, auth: $this->auth(), discover: false);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertArrayHasKey('llama', $loaded[0]->api->commands());
        $this->assertSame('Manage llama.cpp router models', $loaded[0]->api->commands()['llama']->description);

        $provider = ProviderRegistry::get('llama.cpp');
        $this->assertNotNull($provider);
        $this->assertSame('llama.cpp', $provider->name);
        $this->assertSame('llama.cpp server', $provider->apiKeyAuth?->name());
        $this->assertSame([$provider], ProviderRegistry::apiKeyProviders());
    }

    public function testTheStoredCatalogIsRestoredWhenTheExtensionLoads(): void
    {
        file_put_contents($this->dir . '/models-store.json', json_encode(['llama.cpp' => ['models' => [
            ['id' => 'cached', 'name' => 'cached', 'api' => 'openai-completions', 'provider' => 'llama.cpp', 'baseUrl' => 'http://127.0.0.1:9/v1', 'reasoning' => false, 'input' => ['text'], 'cost' => [], 'contextWindow' => 4096, 'maxTokens' => 4096],
            ['type' => 'classifier', 'id' => 'cached', 'name' => 'cached', 'api' => 'llama-cpp-classify', 'provider' => 'llama.cpp', 'baseUrl' => 'http://127.0.0.1:9', 'input' => ['text'], 'cost' => [], 'contextWindow' => 4096],
        ], 'checkedAt' => 1]]));
        $root = dirname(__DIR__, 4);
        ExtensionLoader::load($root, cliPaths: [$root . '/extensions/pig-llama/index.php'], home: $this->dir, auth: $this->auth(), discover: false);

        $this->assertSame(4096, Models::find('llama.cpp', 'cached')?->contextWindow);
        $this->assertSame(ClassifierApi::LlamaCppClassify, Models::findOfType(ModelType::Classifier, 'llama.cpp', 'cached')?->api);
    }

    public function testTheCatalogBecomesTheProvidersModelsAndClassifiers(): void
    {
        $this->catalog = [
            ['id' => 'qwen', 'status' => ['value' => 'loaded'], 'architecture' => ['input_modalities' => ['text', 'image']], 'meta' => ['n_ctx' => 32768]],
            ['id' => 'gemma', 'status' => ['value' => 'sleeping', 'args' => ['-m', 'x.gguf', '--ctx-size', '8192']]],
            ['id' => 'cold', 'status' => ['value' => 'unloaded']],
            ['id' => 'preset', 'status' => ['value' => 'unloaded'], 'source' => 'preset'],
            ['id' => 'judge', 'status' => ['value' => 'loaded'], 'architecture' => ['output_modalities' => ['decisions']]],
        ];
        $url = $this->startRouter();
        $auth = $this->auth();
        $auth->setApiKeyCredential('llama.cpp', new \Pig\Ai\Extension\ApiKeyCredential('secret', ['LLAMA_BASE_URL' => $url]));
        [$extension] = $this->extension($auth);

        Async::run(static fn () => $extension->syncCatalog(new LlamaClient($url, 'secret')));

        $qwen = Models::find('llama.cpp', 'qwen');
        $this->assertNotNull($qwen);
        $this->assertSame(Api::OpenAiCompletions, $qwen->api);
        $this->assertSame("{$url}/v1", $qwen->baseUrl);
        $this->assertSame(32768, $qwen->contextWindow);
        $this->assertSame(32768, $qwen->maxTokens);
        $this->assertSame(['text', 'image'], $qwen->input);
        $this->assertTrue($qwen->reasoning, 'the loaded model\'s chat template mentions enable_thinking');
        $this->assertSame(['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => null], $qwen->thinkingLevelMap);
        $this->assertSame('qwen-chat-template', $qwen->compat?->thinkingFormat);
        $this->assertSame('max_tokens', $qwen->compat?->maxTokensField);

        $gemma = Models::find('llama.cpp', 'gemma');
        $this->assertSame(8192, $gemma?->contextWindow, 'the configured --ctx-size of a sleeping model');
        $this->assertFalse($gemma->reasoning, 'a sleeping model is not asked for its template');
        $this->assertNull(Models::find('llama.cpp', 'cold'));
        $this->assertNull(Models::find('llama.cpp', 'preset'), 'router autoload is off');
        $this->assertNull(Models::find('llama.cpp', 'judge'), 'a decision-only model is not a chat model');

        $judge = Models::findOfType(ModelType::Classifier, 'llama.cpp', 'judge');
        $this->assertSame(ClassifierApi::TypesafeSystemOne, $judge?->api);
        $this->assertSame("{$url}/v1", $judge->baseUrl);
        $chat = Models::findOfType(ModelType::Classifier, 'llama.cpp', 'qwen');
        $this->assertSame(ClassifierApi::LlamaCppClassify, $chat?->api);
        $this->assertSame($url, $chat->baseUrl);

        $props = array_values(array_filter($this->router()->requests, static fn (array $r): bool => $r['path'] === '/props'));
        // The router's own (is autoload on, for the unloaded preset), then the loaded model's template.
        $this->assertSame(['', 'model=qwen&autoload=false'], array_column($props, 'query'));
        $this->assertSame('Bearer secret', $props[1]['headers']['authorization']);

        $stored = json_decode((string) file_get_contents($this->dir . '/models-store.json'), true);
        $this->assertSame(['qwen', 'gemma', 'qwen', 'gemma', 'judge'], array_column($stored['llama.cpp']['models'], 'id'));
        $this->assertSame('classifier', $stored['llama.cpp']['models'][2]['type']);
    }

    public function testRouterAutoloadMakesUnloadedPresetsSelectable(): void
    {
        $this->catalog = [['id' => 'preset', 'status' => ['value' => 'unloaded'], 'source' => 'preset']];
        $url = $this->startRouter(autoload: true);
        $auth = $this->auth();
        $auth->setApiKeyCredential('llama.cpp', new \Pig\Ai\Extension\ApiKeyCredential(null, ['LLAMA_BASE_URL' => $url]));
        [$extension] = $this->extension($auth);

        Async::run(static fn () => $extension->syncCatalog(new LlamaClient($url)));

        $this->assertNotNull(Models::find('llama.cpp', 'preset'));
        $this->assertSame(128000, Models::find('llama.cpp', 'preset')->contextWindow);
    }

    public function testLoadingAskedToReplaceUnloadsTheOthersThenLoads(): void
    {
        $this->catalog = [
            ['id' => 'old', 'status' => ['value' => 'loaded']],
            ['id' => 'new', 'status' => ['value' => 'unloaded']],
        ];
        $url = $this->startRouter();
        $auth = $this->stored($url);
        [$extension] = $this->extension($auth);
        $ui = new ScriptedLlamaUi(selects: ['Unload all and load']);
        $notices = new NoticingUi();
        $ctx = new HookContext('.', ui: $notices);
        $client = new LlamaClient($url);

        Async::run(function () use ($extension, $ctx, $ui, $client): void {
            $extension->loadModel($ctx, $ui, $client, $this->catalog, $this->catalog[1]);
        });

        $this->assertSame(['1 model is loaded'], $ui->selectTitles);
        $posts = $this->posts();
        $this->assertSame([['/models/unload', 'old'], ['/models/load', 'new']], $posts);
        $this->assertContains('[info] Loaded new', $notices->notices);
        $this->assertNotNull(Models::find('llama.cpp', 'new'));
        $this->assertNull(Models::find('llama.cpp', 'old'));
    }

    public function testUnloadingAsksFirst(): void
    {
        $this->catalog = [['id' => 'old', 'status' => ['value' => 'loaded']]];
        $url = $this->startRouter();
        [$extension] = $this->extension($this->stored($url));
        $notices = new NoticingUi();

        Async::run(function () use ($extension, $notices, $url): void {
            $extension->unloadModel(new HookContext('.', ui: $notices), new ScriptedLlamaUi(confirms: [false]), new LlamaClient($url), $this->catalog[0]);
            $extension->unloadModel(new HookContext('.', ui: $notices), $confirming = new ScriptedLlamaUi(confirms: [true]), new LlamaClient($url), $this->catalog[0]);
            $this->assertSame(['Unload model?|old'], $confirming->confirmed);
        });

        $this->assertSame([['/models/unload', 'old']], $this->posts());
        $this->assertSame(['[info] Unloaded old'], $notices->notices);
    }

    public function testEscapeDuringALoadStopsItWhenConfirmed(): void
    {
        $this->catalog = [['id' => 'slow', 'status' => ['value' => 'unloaded']]];
        // The router accepts the load and never finishes it.
        $url = $this->startRouter(loads: false);
        [$extension] = $this->extension($this->stored($url));
        $ui = new ScriptedLlamaUi(confirms: [true], stopAt: 1);
        $notices = new NoticingUi();

        Async::run(function () use ($extension, $ui, $notices, $url): void {
            $extension->loadModel(new HookContext('.', ui: $notices), $ui, new LlamaClient($url), $this->catalog, $this->catalog[0]);
        });

        $this->assertSame(['Stop loading?|slow'], $ui->confirmed);
        $this->assertSame([['/models/load', 'slow'], ['/models/unload', 'slow']], $this->posts());
        $this->assertSame([], $notices->notices, 'nothing was loaded');
        $this->assertSame('Loading model', $ui->progressStates[0]['title']);
        $this->assertSame('slow', $ui->progressStates[0]['model']);
    }

    public function testDownloadingFollowsTheQuantizationChoice(): void
    {
        $this->catalog = [];
        $url = $this->startRouter();
        $hub = new ScriptedServer();
        $hubUrl = $hub->start(static fn (array $request): array => match ($request['path']) {
            '/api/models/owner/repo' => [200, [], (string) json_encode(['id' => 'owner/repo', 'gated' => false, 'siblings' => [
                ['rfilename' => 'repo-Q8_0.gguf', 'size' => 8 * 1024 ** 3],
                ['rfilename' => 'repo-Q4_K_M.gguf', 'size' => 4 * 1024 ** 3],
            ]])],
            default => [404, [], '{"error":"nope"}'],
        });
        [$extension] = $this->extension($this->stored($url), $hubUrl);
        $ui = new ScriptedLlamaUi(searches: ['owner/repo'], selects: ['Q8_0 · 8.00 GiB']);
        $notices = new NoticingUi();

        try {
            Async::run(static fn () => $extension->downloadModel(new HookContext('.', ui: $notices), $ui, new LlamaClient($url)));
        } finally {
            $hub->stop();
        }

        $this->assertSame(["Select quantization\nowner/repo"], $ui->selectTitles);
        $this->assertSame([['Q4_K_M · 4.00 GiB · recommended', 'Q8_0 · 8.00 GiB']], $ui->selectOptions);
        $this->assertSame([['/models', 'owner/repo:Q8_0']], $this->posts());
        $this->assertContains('[info] Downloaded owner/repo:Q8_0', $notices->notices);
        $reloads = array_filter($this->router()->requests, static fn (array $r): bool => $r['path'] === '/models' && $r['query'] === 'reload=1');
        $this->assertCount(1, $reloads, 'the catalog is reloaded once the download is there');
    }

    public function testTheChatRequestReachesTheRouterAsOpenAiCompletions(): void
    {
        $this->catalog = [['id' => 'qwen', 'status' => ['value' => 'loaded'], 'meta' => ['n_ctx' => 4096]]];
        $url = $this->startRouter();
        $auth = $this->stored($url, 'secret');
        [$extension] = $this->extension($auth);
        Async::run(static fn () => $extension->syncCatalog(new LlamaClient($url, 'secret')));
        $model = Models::find('llama.cpp', 'qwen');
        $this->assertNotNull($model);

        $answer = Async::run(static fn (): AssistantMessage => Stream::simple($model, new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: $auth->apiKey('llama.cpp')))->result()->await());

        $this->assertSame('ok', $answer->content[0]->text ?? null);
        $chat = array_values(array_filter($this->router()->requests, static fn (array $r): bool => $r['path'] === '/v1/chat/completions'));
        $this->assertCount(1, $chat);
        $this->assertSame('Bearer secret', $chat[0]['headers']['authorization']);
        $body = json_decode($chat[0]['body'], true);
        $this->assertSame('qwen', $body['model']);
        $this->assertTrue($body['stream']);
        $this->assertArrayNotHasKey('store', $body);
    }

    public function testLoginStoresTheUrlAndTheOptionalKey(): void
    {
        $url = $this->startRouter();
        $auth = $this->auth();
        [, $provider] = $this->extension($auth);
        $prompts = [];
        $answers = ["  {$url}/v1/  ", ' key-1 '];

        $onPrompt = static function (string $message, string $placeholder) use (&$prompts, &$answers): ?string {
            $prompts[] = [$message, $placeholder];

            return array_shift($answers);
        };
        $credential = Async::run(static fn () => $auth->loginApiKey('llama.cpp', $provider->provider->apiKeyAuth, $onPrompt));

        $this->assertSame([['llama.cpp server URL', 'http://127.0.0.1:8080'], ['API key (optional)', '']], $prompts);
        $this->assertSame('key-1', $credential?->key);
        $saved = json_decode((string) file_get_contents($this->dir . '/auth.json'), true);
        $this->assertSame(['type' => 'api_key', 'key' => 'key-1', 'env' => ['LLAMA_BASE_URL' => $url]], $saved['llama.cpp']);
        $this->assertSame('Bearer key-1', $this->router()->requests[0]['headers']['authorization'], 'the router was asked for its catalog with the key');
        $this->assertSame('/models', $this->router()->requests[0]['path']);

        $this->assertTrue($auth->hasKeyFor('llama.cpp'));
        $this->assertSame('key-1', $auth->apiKey('llama.cpp'));
        $resolved = $auth->providerAuth('llama.cpp');
        $this->assertSame("{$url}/v1", $resolved?->auth->baseUrl);
        $this->assertSame('stored credential', $resolved->source);
    }

    public function testAKeylessLoginKeepsOnlyTheUrlAndSendsLocal(): void
    {
        $url = $this->startRouter();
        $auth = $this->auth();
        [, $provider] = $this->extension($auth);
        $answers = [$url, ''];

        $onPrompt = static function () use (&$answers): ?string {
            return array_shift($answers);
        };
        Async::run(static fn () => $auth->loginApiKey('llama.cpp', $provider->provider->apiKeyAuth, $onPrompt));

        $saved = json_decode((string) file_get_contents($this->dir . '/auth.json'), true);
        $this->assertSame(['type' => 'api_key', 'env' => ['LLAMA_BASE_URL' => $url]], $saved['llama.cpp']);
        $this->assertArrayNotHasKey('authorization', $this->router()->requests[0]['headers']);
        $this->assertSame('local', $auth->apiKey('llama.cpp'));
    }

    public function testAnEscapedPromptIsACancellation(): void
    {
        $auth = $this->auth();
        [, $provider] = $this->extension($auth);

        $this->assertNull($auth->loginApiKey('llama.cpp', $provider->provider->apiKeyAuth, static fn (): ?string => null));
        $this->assertFalse(is_file($this->dir . '/auth.json'));
    }

    public function testTheEnvironmentConfiguresTheProviderWithoutLogin(): void
    {
        $auth = $this->auth();
        $this->extension($auth);
        $notices = new NoticingUi();
        [$extension] = $this->extension($auth);

        $this->assertFalse($auth->hasKeyFor('llama.cpp'));
        $this->assertNull($auth->apiKey('llama.cpp'));
        $this->assertNull($extension->configuredClient(new HookContext('.', ui: $notices)));
        $this->assertSame(['[warning] Configure llama.cpp with /login llama.cpp'], $notices->notices);

        putenv('LLAMA_BASE_URL=http://10.0.0.5:9000/v1');
        $this->assertTrue($auth->hasKeyFor('llama.cpp'));
        $this->assertSame('local', $auth->apiKey('llama.cpp'));
        $this->assertSame('LLAMA_BASE_URL', $auth->providerAuth('llama.cpp')?->source);
        $this->assertSame('http://10.0.0.5:9000', $extension->configuredClient(new HookContext('.', ui: $notices))?->serverUrl);

        putenv('LLAMA_API_KEY=env-key');
        $this->assertSame('env-key', $auth->apiKey('llama.cpp'));
    }

    public function testOutsideInteractiveModeTheCommandSaysSo(): void
    {
        [$extension] = $this->extension($this->auth());
        $notices = new NoticingUi();

        $extension->command('', new HookContext('.', ui: $notices));

        $this->assertSame(['[warning] /llama is available in interactive mode'], $notices->notices);
    }

    public function testAConnectionFailureOffersRetryAndClose(): void
    {
        [$extension] = $this->extension($this->auth());
        $ui = new ScriptedLlamaUi(connectionErrors: ['retry', 'close']);

        // Nothing listens on port 9 of the loopback.
        Async::run(static fn () => $extension->manage(new HookContext('.', ui: new NoticingUi()), $ui, new LlamaClient('http://127.0.0.1:9')));

        $this->assertSame(['http://127.0.0.1:9|Could not connect to the server.', 'http://127.0.0.1:9|Could not connect to the server.'], $ui->connectionErrorsShown);
    }

    public function testHuggingFaceSearchAndDetailsAreParsed(): void
    {
        $hub = new ScriptedServer();
        $url = $hub->start(static fn (array $request): array => match ($request['path']) {
            '/api/models' => [200, [], (string) json_encode([
                ['id' => 'unsloth/Qwen3-GGUF', 'downloads' => 1200],
                ['id' => 'bartowski/gemma-GGUF'],
                ['downloads' => 3],
            ])],
            '/api/models/unsloth/Qwen3-GGUF' => [200, [], (string) json_encode(['id' => 'unsloth/Qwen3-GGUF', 'gated' => 'manual', 'siblings' => [
                ['rfilename' => 'Qwen3-UD-Q2_K_XL.gguf', 'size' => 100],
                ['rfilename' => 'big/Qwen3-Q8_0-00001-of-00002.gguf', 'size' => 600],
                ['rfilename' => 'big/Qwen3-Q8_0-00002-of-00002.gguf', 'size' => 400],
                ['rfilename' => 'Qwen3-Q4_K_M.gguf', 'size' => 500],
                ['rfilename' => 'Qwen3-bf16.gguf'],
                ['rfilename' => 'mmproj-F16.gguf', 'size' => 50],
                ['rfilename' => 'README.md', 'size' => 1],
            ]])],
            '/api/models/limited/repo' => [429, ['retry-after' => '7'], '{}'],
            default => [404, [], '{"error":"Repository not found"}'],
        });

        try {
            $client = new HuggingFaceClient('hf-token', $url);
            [$results, $details, $missing, $limited] = Async::run(static function () use ($client): array {
                $failure = static function (Closure $call): string {
                    try {
                        $call();
                    } catch (\RuntimeException $error) {
                        return $error->getMessage();
                    }

                    return '';
                };

                return [
                    $client->search('qwen'),
                    $client->details('unsloth/Qwen3-GGUF'),
                    $failure(static fn () => $client->details('nobody/none')),
                    $failure(static fn () => $client->details('limited/repo')),
                ];
            });
        } finally {
            $hub->stop();
        }

        $this->assertSame([['id' => 'unsloth/Qwen3-GGUF', 'downloads' => 1200], ['id' => 'bartowski/gemma-GGUF', 'downloads' => 0]], $results);
        parse_str($hub->requests[0]['query'], $query);
        $this->assertSame(['search' => 'qwen', 'filter' => 'gguf', 'sort' => 'downloads', 'direction' => '-1', 'limit' => '20'], $query);
        $this->assertSame('Bearer hf-token', $hub->requests[0]['headers']['authorization']);
        $this->assertSame('blobs=true', $hub->requests[1]['query']);

        $this->assertSame('manual', $details['gated']);
        $this->assertSame([
            ['name' => 'Q4_K_M', 'size' => 500],
            ['name' => 'UD-Q2_K_XL', 'size' => 100],
            ['name' => 'Q8_0', 'size' => 1000],
            ['name' => 'BF16'],
        ], $details['quantizations']);
        $this->assertSame('Repository not found', $missing);
        $this->assertSame('Hugging Face rate limit reached; retry in 7s', $limited);
    }

    public function testTheHuggingFaceTokenIsFoundInItsUsualPlaces(): void
    {
        file_put_contents($this->dir . '/token', " from-file \n");

        $this->assertSame('env', HuggingFaceClient::findHuggingFaceToken(['HF_TOKEN' => ' env ', 'HOME' => $this->dir]));
        $this->assertSame('from-file', HuggingFaceClient::findHuggingFaceToken(['HF_TOKEN_PATH' => $this->dir . '/token', 'HOME' => $this->dir]));
        $this->assertSame('from-file', HuggingFaceClient::findHuggingFaceToken(['HF_HOME' => $this->dir, 'HOME' => '/nonexistent']));
        $this->assertNull(HuggingFaceClient::findHuggingFaceToken(['HOME' => '/nonexistent']));
    }

    public function testServerUrlsAreNormalizedToTheRouterRoot(): void
    {
        $this->assertSame('http://127.0.0.1:8080', LlamaClient::normalizeLlamaServerUrl(' http://127.0.0.1:8080/v1/ '));
        $this->assertSame('https://example.com/prefix', LlamaClient::normalizeLlamaServerUrl('https://Example.com:443/prefix/v1?x=1#y'));
        $this->assertSame('http://host', LlamaClient::normalizeLlamaServerUrl('http://host/'));
        $this->assertSame('http://host:8080/v1', LlamaClient::llamaInferenceUrl('http://host:8080'));

        try {
            LlamaClient::normalizeLlamaServerUrl('ftp://host');
            $this->fail('ftp is refused');
        } catch (\RuntimeException $error) {
            $this->assertSame('Server URL must use http or https', $error->getMessage());
        }
    }

    public function testProgressIsReadFromTheRoutersEvents(): void
    {
        $this->assertSame(['message' => 'Loading tensors', 'ratio' => 0.75], LlamaClient::parseLoadProgress(['progress' => ['stages' => ['init', 'tensors'], 'current' => 'tensors', 'value' => 0.5]]));
        $this->assertSame(['message' => 'Loading model'], LlamaClient::parseLoadProgress(['progress' => []]));
        $this->assertSame(
            ['message' => 'Downloading model', 'ratio' => 0.25, 'detail' => '1.00 MiB / 4.00 MiB'],
            LlamaClient::parseDownloadProgress(['a.gguf' => ['done' => 1024 ** 2, 'total' => 2 * 1024 ** 2], 'b.gguf' => ['done' => 0, 'total' => 2 * 1024 ** 2]]),
        );
        $this->assertNull(LlamaClient::parseDownloadProgress(['progress' => []]));
        $this->assertSame('512 B', LlamaClient::formatBytes(512));
        $this->assertSame('15.0 KiB', LlamaClient::formatBytes(15 * 1024));
    }

    // ---- the fake router ---------------------------------------------------------------

    /**
     * llama.cpp's router as far as the extension sees it: the catalog, `/props`, load and unload
     * that take effect at once (or, with `$loads` false, a load that never finishes), a download
     * that adds the model, an empty `/models/sse`, and `/v1/chat/completions`.
     */
    private function startRouter(bool $autoload = false, bool $loads = true): string
    {
        $this->router = new ScriptedServer();

        return $this->router->start(function (array $request) use ($autoload, $loads): array {
            $body = (array) json_decode($request['body'], true);
            $setStatus = function (string $id, string $status): void {
                foreach ($this->catalog as $index => $model) {
                    if ($model['id'] === $id) {
                        $this->catalog[$index]['status']['value'] = $status;
                    }
                }
            };

            return match ([$request['method'], $request['path']]) {
                ['GET', '/models'] => [200, [], (string) json_encode(['object' => 'list', 'data' => $this->catalog])],
                ['GET', '/props'] => [200, [], (string) json_encode(['models_autoload' => $autoload, 'chat_template' => '{% if enable_thinking %}<think>{% endif %}'])],
                ['POST', '/models/load'] => (static function () use ($setStatus, $body, $loads): array {
                    $setStatus((string) $body['model'], $loads ? 'loaded' : 'loading');

                    return [200, [], '{"success":true}'];
                })(),
                ['POST', '/models/unload'] => (static function () use ($setStatus, $body): array {
                    $setStatus((string) $body['model'], 'unloaded');

                    return [200, [], '{"success":true}'];
                })(),
                ['POST', '/models'] => (function () use ($body): array {
                    $this->catalog[] = ['id' => (string) $body['model'], 'status' => ['value' => 'unloaded']];

                    return [200, [], '{"success":true}'];
                })(),
                ['GET', '/models/sse'] => [200, ['content-type' => 'text/event-stream'], ''],
                ['POST', '/v1/chat/completions'] => [200, ['content-type' => 'text/event-stream'], self::completionsStream()],
                default => [404, [], '{"error":{"message":"no route"}}'],
            };
        });
    }

    private function router(): ScriptedServer
    {
        $this->assertNotNull($this->router);

        return $this->router;
    }

    /** @return list<array{0: string, 1: string}> the router's POSTs as [path, model] */
    private function posts(): array
    {
        return array_values(array_map(
            static fn (array $r): array => [$r['path'], (string) (json_decode($r['body'], true)['model'] ?? '')],
            array_filter($this->router()->requests, static fn (array $r): bool => $r['method'] === 'POST'),
        ));
    }

    private function auth(): Auth
    {
        return Auth::discover($this->dir . '/auth.json');
    }

    private function stored(string $url, ?string $key = null): Auth
    {
        $auth = $this->auth();
        $auth->setApiKeyCredential('llama.cpp', new \Pig\Ai\Extension\ApiKeyCredential($key, ['LLAMA_BASE_URL' => $url]));

        return $auth;
    }

    /** @return array{0: LlamaExtension, 1: LlamaProvider} */
    private function extension(Auth $auth, string $huggingFaceUrl = HuggingFaceClient::DEFAULT_HUGGING_FACE_URL): array
    {
        $provider = new LlamaProvider();
        ProviderRegistry::register($provider->provider);

        return [new LlamaExtension($auth, $provider, $huggingFaceUrl), $provider];
    }

    private static function completionsStream(): string
    {
        return 'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]) . "\n\n"
            . "data: [DONE]\n\n";
    }
}

/**
 * A `LlamaUi` that answers from lists and writes down what it was shown. `progress()` parks until
 * the `$stopAt`-th call, which completes at once — a person pressing Escape on that screen.
 */
final class ScriptedLlamaUi implements LlamaUi
{
    /** @var list<string> */
    public array $selectTitles = [];

    /** @var list<list<string>> */
    public array $selectOptions = [];

    /** @var list<string> `title|message` */
    public array $confirmed = [];

    /** @var list<string> `serverUrl|message` */
    public array $connectionErrorsShown = [];

    /** @var list<array<string, mixed>> */
    public array $progressStates = [];

    private int $progressCalls = 0;

    /**
     * @param list<?string> $selects
     * @param list<bool> $confirms
     * @param list<?string> $searches
     * @param list<'retry'|'close'> $connectionErrors
     */
    public function __construct(
        private array $selects = [],
        private array $confirms = [],
        private array $searches = [],
        private array $connectionErrors = [],
        private readonly int $stopAt = 0,
    ) {
    }

    #[\Override]
    public function showModels(string $serverUrl, array $models): array
    {
        return ['type' => 'close'];
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        $this->selectTitles[] = $title;
        $this->selectOptions[] = $options;

        return array_shift($this->selects);
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        $this->confirmed[] = "{$title}|{$message}";

        return array_shift($this->confirms) ?? false;
    }

    #[\Override]
    public function connectionError(string $serverUrl, string $message): string
    {
        $this->connectionErrorsShown[] = "{$serverUrl}|{$message}";

        return array_shift($this->connectionErrors) ?? 'close';
    }

    #[\Override]
    public function searchModels(Closure $search): ?string
    {
        return array_shift($this->searches);
    }

    #[\Override]
    public function showStatus(string $title, string $message): void
    {
    }

    #[\Override]
    public function progress(array $state): Future
    {
        $this->progressStates[] = $state;
        $this->progressCalls++;

        if ($this->progressCalls === $this->stopAt) {
            return Future::complete(null);
        }

        return (new Deferred())->future;
    }

    #[\Override]
    public function updateProgress(array $state): void
    {
        $this->progressStates[] = $state;
    }
}
