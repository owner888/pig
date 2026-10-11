<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantImages;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierBoolAnswer;
use Pig\Ai\ClassifierBoolQuestion;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\Cost;
use Pig\Ai\Extension\ClassificationApi;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\ModelRefresh;

/**
 * Upstream's legacy `registerProvider(name, config: ProviderConfig)` — `custom-provider.md`'s second
 * registration form — through `ExtensionApi::registerProvider()`.
 */
final class ProviderConfigTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        ProviderRegistry::forget();
        Models::forgetRegistered();
        putenv('MEDIA_TOOLS_API_KEY');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
        putenv('MEDIA_TOOLS_API_KEY');
    }

    /** The doc's mixed-operation example, on the image and classifier APIs pig has. */
    public function testAConfigRegistersChatImageAndClassifierModelsAndAKeyReference(): void
    {
        $auth = Auth::inMemory();
        $api = new ExtensionApi('.', 'media.php', 'media', $auth);
        $api->registerProvider('media-tools', [
            'apiKey' => '$MEDIA_TOOLS_API_KEY',
            'baseUrl' => 'https://media.example.com/v1',
            'api' => 'openai-completions',
            'models' => [
                ['id' => 'chat-v1', 'name' => 'Chat V1', 'contextWindow' => 64_000, 'maxTokens' => 4_000, 'cost' => ['input' => 1, 'output' => 2, 'cacheRead' => 0, 'cacheWrite' => 0]],
                ['type' => 'image', 'id' => 'image-v1', 'name' => 'Image V1', 'api' => 'openrouter-images', 'input' => ['text'], 'output' => ['image']],
                ['type' => 'classifier', 'id' => 'classifier-v1', 'name' => 'Classifier V1', 'api' => 'typesafe-system-one', 'contextWindow' => 64_000],
            ],
        ]);

        $chat = Models::find('media-tools', 'chat-v1');
        $this->assertSame(Api::OpenAiCompletions, $chat?->api);
        $this->assertSame('https://media.example.com/v1', $chat?->baseUrl);
        $this->assertSame(2.0, $chat?->pricing->output);
        $this->assertSame(ImageApi::OpenRouterImages, Models::findOfType(ModelType::Image, 'media-tools', 'image-v1')?->api);
        $this->assertSame(ClassifierApi::TypesafeSystemOne, Models::findOfType(ModelType::Classifier, 'media-tools', 'classifier-v1')?->api);
        $this->assertSame('media-tools', ProviderRegistry::get('media-tools')?->name, 'upstream: the provider id when nothing names it');

        $this->assertFalse($auth->hasKeyFor('media-tools'), 'the reference is not set yet');
        putenv('MEDIA_TOOLS_API_KEY=sk-media');
        $this->assertTrue($auth->hasKeyFor('media-tools'));
        $this->assertSame('sk-media', $auth->apiKey('media-tools'));

        $api->unregisterProvider('media-tools');
        $this->assertNull(Models::find('media-tools', 'chat-v1'));
        $this->assertNull(Models::findOfType(ModelType::Image, 'media-tools', 'image-v1'));
    }

    /** "Registering only `baseUrl` or `headers` for an existing provider preserves its built-in models." */
    public function testABaseUrlForABuiltInProviderKeepsItsModelsUntilUnregistered(): void
    {
        $before = Models::baseModelsOf('anthropic');
        $this->assertNotSame([], $before);

        $api = new ExtensionApi('.', 'proxy.php', 'proxy');
        $api->registerProvider('anthropic', ['baseUrl' => 'https://proxy.example/v1', 'headers' => ['X-Proxy' => 'on']]);

        $after = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'anthropic'));
        $this->assertCount(count($before), $after);
        $this->assertSame('https://proxy.example/v1', $after[0]->baseUrl);
        $this->assertSame('on', $after[0]->headers['X-Proxy']);

        $api->unregisterProvider('anthropic');
        $this->assertSame($before[0]->baseUrl, Models::find('anthropic', $before[0]->id)?->baseUrl, 'and the built-in rows come back');
    }

    /** "Supplying `models` in the legacy form replaces that provider's models." */
    public function testModelsForABuiltInProviderReplaceItsList(): void
    {
        $api = new ExtensionApi('.', 'proxy.php', 'proxy');
        $api->registerProvider('anthropic', ['models' => [['id' => 'only-this', 'name' => 'Only']]]);

        $anthropic = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'anthropic'));
        $this->assertSame(['only-this'], array_map(static fn (Model $m): string => $m->id, $anthropic));
        // The api and baseUrl from the provider's own models, as `findExtensionModelDefaults()` has it.
        $this->assertSame(Api::AnthropicMessages, $anthropic[0]->api);
        $this->assertSame('https://api.anthropic.com', $anthropic[0]->baseUrl);

        $api->unregisterProvider('anthropic');
        $this->assertNotNull(Models::find('anthropic', 'claude-sonnet-4-5'));
    }

    public function testStreamSimpleSpeaksForTheModelsOnTheConfigsApi(): void
    {
        $seen = [];
        $api = new ExtensionApi('.', 'duo.php', 'duo');
        $api->registerProvider('duo', [
            'baseUrl' => 'https://duo.example',
            'api' => 'duo-chat',
            'streamSimple' => static function (Model $model, TranscriptContext $context, ?SimpleStreamOptions $options) use (&$seen): AssistantMessageEventStream {
                $seen[] = [$model->id, $options?->apiKey];
                $stream = new AssistantMessageEventStream();
                $message = new AssistantMessage([new TextContent('from duo')], $model->api, $model->provider, $model->id, new Usage(), StopReason::Stop);
                Async::spawn(static function () use ($stream, $message): void {
                    $stream->push(new StartEvent($message));
                    $stream->push(new DoneEvent(StopReason::Stop, $message));
                    $stream->end();
                });

                return $stream;
            },
            'models' => [
                ['id' => 'duo-1', 'name' => 'Duo 1'],
                ['id' => 'plain', 'name' => 'Plain', 'api' => 'openai-completions'],
            ],
        ]);

        $this->assertSame(Api::Extension, Models::find('duo', 'duo-1')?->api);
        $this->assertSame(Api::OpenAiCompletions, Models::find('duo', 'plain')?->api, 'another api is a built-in protocol');

        $answer = Async::run(static function (): ?AssistantMessage {
            $stream = Stream::simple(Models::find('duo', 'duo-1') ?? throw new \LogicException(), new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: 'k'));

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });

        $this->assertSame([['duo-1', 'k']], $seen);
        $this->assertSame('from duo', $answer?->content[0]->text ?? null);
    }

    public function testWhatPigCannotTakeIsRefusedByName(): void
    {
        $api = new ExtensionApi('.', 'x.php', 'x');

        foreach ([
            [['streamSimple' => static fn () => null], '"api" is required when registering streamSimple'],
            [['images' => ['media-images' => new \stdClass()]], '"images": "media-images" must be a Closure or a Pig\\Ai\\Extension\\ImageGenerationApi'],
            [['classifiers' => ['media-classifier' => 'classify']], '"classifiers": "media-classifier" must be a Closure or a Pig\\Ai\\Extension\\ClassificationApi'],
            [['images' => [static fn () => null]], '"images" must be an array of implementations keyed by API name'],
            [['images' => ['extension' => static fn () => null]], '"extension" is not an API name'],
            [['models' => [['type' => 'image', 'id' => 'i', 'api' => 'media-images', 'baseUrl' => 'https://x']]], 'pig has no image API "media-images", and "images" does not implement it'],
            [['models' => [['type' => 'image', 'id' => 'i', 'api' => 'extension', 'baseUrl' => 'https://x']]], 'pig has no image API "extension"'],
            [['baseUrl' => 'https://x', 'models' => [['id' => 'c']]], 'no "api" specified'],
        ] as [$config, $says]) {
            try {
                $api->registerProvider('zzc', $config);
                $this->fail("refused: {$says}");
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString($says, $error->getMessage());
            }
        }
    }

    /**
     * custom-provider.md's mixed-operation example as written: image and classifier models on API names pig
     * has never heard of, and the `images` / `classifiers` implementations keyed by those names.
     */
    public function testImagesAndClassifiersMapsImplementTheModelsOnTheirApiNames(): void
    {
        $seen = [];
        // A plain closure, not an arrow function: an arrow function would capture `$seen` by value.
        $answer = static function (string $by) use (&$seen): \Closure {
            return static function (ImageModel $model, ImagesContext $context, ImagesOptions $options) use (&$seen, $by): AssistantImages {
                $seen[] = [$by, $model->id, $options->apiKey];

                return new AssistantImages($model->api, $model->provider, $model->id, [new ImageContent('aGk=', 'image/png')], StopReason::Stop, Timestamp::nowMs(), usage: new Usage(1, 2, 0, 0, 3, new Cost(0.1, 0.2, 0.0, 0.0, 0.3)));
            };
        };
        $classifier = new class ($seen) implements ClassificationApi {
            public function __construct(private array &$seen)
            {
            }

            public function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult
            {
                $this->seen[] = ['classifier', $model->id, $options->apiKey];

                return new ClassifierResult($model->api, $model->provider, $model->id, ['ok' => new ClassifierBoolAnswer(0.75)], StopReason::Stop, Timestamp::nowMs());
            }
        };

        $api = new ExtensionApi('.', 'media.php', 'media', Auth::inMemory());
        $api->registerProvider('media-tools', [
            'baseUrl' => 'https://media.example.com/v1',
            'apiKey' => 'literal-media-key',
            'api' => 'openai-completions',
            'models' => [
                ['id' => 'chat-v1', 'name' => 'Chat V1'],
                ['type' => 'image', 'id' => 'image-v1', 'name' => 'Image V1', 'api' => 'media-images', 'input' => ['text'], 'output' => ['image'], 'cost' => ['input' => 1, 'output' => 2, 'cacheRead' => 0, 'cacheWrite' => 0]],
                ['type' => 'image', 'id' => 'image-v2', 'name' => 'Image V2', 'api' => 'media-images-2', 'input' => ['text'], 'output' => ['image']],
                ['type' => 'image', 'id' => 'router', 'name' => 'Router', 'api' => 'openrouter-images'],
                ['type' => 'classifier', 'id' => 'classifier-v1', 'name' => 'Classifier V1', 'api' => 'media-classifier', 'contextWindow' => 64_000],
            ],
            'images' => ['media-images' => $answer('first'), 'media-images-2' => $answer('second')],
            'classifiers' => ['media-classifier' => $classifier],
        ]);

        $image = Models::findOfType(ModelType::Image, 'media-tools', 'image-v1');
        $this->assertInstanceOf(ImageModel::class, $image);
        $this->assertSame(ImageApi::Extension, $image->api);
        $this->assertSame(ImageApi::OpenRouterImages, Models::findOfType(ModelType::Image, 'media-tools', 'router')?->api, 'a name "images" does not implement stays built in');
        $this->assertSame(ClassifierApi::Extension, Models::findOfType(ModelType::Classifier, 'media-tools', 'classifier-v1')?->api);

        $first = Models::generateImages($image, new ImagesContext([new TextContent('a cat')]), new ImagesOptions(apiKey: 'sk-1'));
        $second = Models::generateImages(Models::findOfType(ModelType::Image, 'media-tools', 'image-v2') ?? throw new \LogicException(), new ImagesContext([new TextContent('a dog')]), new ImagesOptions(apiKey: 'sk-2'));
        $judged = Models::classify(Models::findOfType(ModelType::Classifier, 'media-tools', 'classifier-v1') ?? throw new \LogicException(), new ClassifierContext(['x' => 1], ['ok' => new ClassifierBoolQuestion('ok?', [])]), new ClassifierOptions(apiKey: 'sk-3'));

        $this->assertSame(StopReason::Stop, $first->stopReason);
        $this->assertSame(0.3, $first->usage?->cost->total);
        $this->assertSame(StopReason::Stop, $second->stopReason);
        $this->assertSame(0.75, $judged->answers['ok']->probability ?? null);
        $this->assertSame([['first', 'image-v1', 'sk-1'], ['second', 'image-v2', 'sk-2'], ['classifier', 'classifier-v1', 'sk-3']], $seen, 'each model to the implementation of its own api name');

        // An implementation that answers with something else is an error result, not a throw.
        $api->registerProvider('media-bad', [
            'baseUrl' => 'https://bad.example',
            'models' => [['type' => 'image', 'id' => 'b', 'name' => 'B', 'api' => 'bad-images']],
            'images' => ['bad-images' => static fn (): string => 'not a result'],
        ]);
        $bad = Models::generateImages(Models::findOfType(ModelType::Image, 'media-bad', 'b') ?? throw new \LogicException(), new ImagesContext([new TextContent('x')]), new ImagesOptions(apiKey: 'k'));
        $this->assertSame(StopReason::Error, $bad->stopReason);
        $this->assertStringContainsString('did not return a Pig\\Ai\\AssistantImages', (string) $bad->errorMessage);
    }

    /** Upstream's composer: an extension's implementation for a built-in API's name wins over the base provider's. */
    public function testAnImplementationForABuiltInApiNameTakesTheProvidersModelsOver(): void
    {
        $seen = [];
        $api = new ExtensionApi('.', 'proxy.php', 'proxy');
        $api->registerProvider('openrouter', [
            'images' => ['openrouter-images' => static function (ImageModel $model) use (&$seen): AssistantImages {
                $seen[] = $model->id;

                return new AssistantImages($model->api, $model->provider, $model->id, [], StopReason::Stop, Timestamp::nowMs());
            }],
        ]);

        $model = Models::findOfType(ModelType::Image, 'openrouter', 'openai/gpt-image-1');
        $this->assertSame(ImageApi::Extension, $model?->api, 'the built-in rows stay, on the extension');
        Models::generateImages($model ?? throw new \LogicException(), new ImagesContext([new TextContent('x')]), new ImagesOptions(apiKey: 'k'));
        $this->assertSame(['openai/gpt-image-1'], $seen);

        $api->unregisterProvider('openrouter');
        $this->assertSame(ImageApi::OpenRouterImages, Models::findOfType(ModelType::Image, 'openrouter', 'openai/gpt-image-1')?->api);
    }

    /** "Legacy `ProviderConfig.refreshModels` returns mixed-operation model definitions. pig replaces that registration's live models." */
    public function testRefreshModelsReplacesTheRegistrationsModels(): void
    {
        $auth = Auth::inMemory();
        $api = new ExtensionApi('.', 'live.php', 'live', $auth);
        $api->registerProvider('zzlive', [
            'baseUrl' => 'https://live.example',
            'api' => 'openai-completions',
            'apiKey' => 'literal-key',
            'models' => [['id' => 'first', 'name' => 'First']],
            'refreshModels' => static fn (RefreshModelsContext $context): array => [
                ['id' => $context->allowNetwork ? 'fetched' : 'cached', 'name' => 'Live'],
            ],
        ]);

        // Registration ran the offline phase already.
        $this->assertNull(Models::find('zzlive', 'first'));
        $this->assertNotNull(Models::find('zzlive', 'cached'));

        $result = Async::run(static fn () => ModelRefresh::refresh($auth, ['zzlive']));
        $this->assertSame([], $result->errors);
        $this->assertNotNull(Models::find('zzlive', 'fetched'));
        $this->assertNull(Models::find('zzlive', 'cached'));
    }

    public function testRefreshedImageModelsGoToTheirImplementationToo(): void
    {
        $auth = Auth::inMemory();
        $api = new ExtensionApi('.', 'live.php', 'live', $auth);
        $api->registerProvider('zzlivepic', [
            'baseUrl' => 'https://live.example',
            'apiKey' => 'literal-key',
            'models' => [['type' => 'image', 'id' => 'first', 'name' => 'First', 'api' => 'live-images']],
            'refreshModels' => static fn (RefreshModelsContext $context): array => [
                ['type' => 'image', 'id' => $context->allowNetwork ? 'fetched' : 'cached', 'name' => 'Live', 'api' => 'live-images'],
            ],
            'images' => ['live-images' => static fn (ImageModel $model): AssistantImages => new AssistantImages($model->api, $model->provider, $model->id, [new TextContent("from {$model->id}")], StopReason::Stop, Timestamp::nowMs())],
        ]);

        $this->assertSame([], Async::run(static fn () => ModelRefresh::refresh($auth, ['zzlivepic']))->errors);
        $fetched = Models::findOfType(ModelType::Image, 'zzlivepic', 'fetched');
        $this->assertInstanceOf(ImageModel::class, $fetched);
        $result = Models::generateImages($fetched, new ImagesContext([new TextContent('x')]), new ImagesOptions(apiKey: 'k'));
        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertSame('from fetched', $result->output[0]->text ?? null);
    }
}
