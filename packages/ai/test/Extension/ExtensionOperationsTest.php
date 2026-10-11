<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Extension;

use PHPUnit\Framework\TestCase;
use Pig\Ai\AssistantImages;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierBoolAnswer;
use Pig\Ai\ClassifierBoolQuestion;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\Cost;
use Pig\Ai\Extension\ClassificationApi;
use Pig\Ai\Extension\ImageGenerationApi;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\Pricing;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use Pig\Async\AbortController;
use RuntimeException;

/**
 * Upstream's provider-supplied `generateImages()` / `classify()` — a provider's `images` /
 * `classifiers` implementations — as pig's `ImageGenerationApi` / `ClassificationApi` behind
 * `ImageApi::Extension` / `ClassifierApi::Extension`, from `Models`' side.
 */
final class ExtensionOperationsTest extends TestCase
{
    /** @var list<array{0: string, 1: ?string, 2: mixed}> what the implementations were handed */
    private array $seen = [];

    #[\Override]
    protected function setUp(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
        $this->seen = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
    }

    private static function imageModel(): ImageModel
    {
        return new ImageModel('pic-1', 'Pic 1', ImageApi::Extension, 'zzimg', 'https://zzimg.example', ['text', 'image'], ['image', 'text'], new Pricing(2.0, 10.0, 0.0, 0.0));
    }

    private static function classifierModel(array $input = ['text']): ClassifierModel
    {
        return new ClassifierModel('judge-1', 'Judge 1', ClassifierApi::Extension, 'zzimg', 'https://zzimg.example', 8_000, $input, new Pricing(1.0, 0.0, 0.0, 0.0));
    }

    /** @param (\Closure(ImageModel, ImagesContext, ImagesOptions): AssistantImages)|null $images */
    private function register(?\Closure $images = null, ?\Closure $classify = null, bool $withImplementations = true): void
    {
        $seen = &$this->seen;
        $imageApi = new class ($images, $seen) implements ImageGenerationApi {
            public function __construct(private ?\Closure $run, private array &$seen)
            {
            }

            public function generateImages(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages
            {
                $this->seen[] = ['images', $options->apiKey, $options->metadata];

                return ($this->run ?? static fn (): AssistantImages => throw new RuntimeException('unset'))($model, $context, $options);
            }
        };
        $classifierApi = new class ($classify, $seen) implements ClassificationApi {
            public function __construct(private ?\Closure $run, private array &$seen)
            {
            }

            public function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult
            {
                $this->seen[] = ['classify', $options->apiKey, $context->questions];

                return ($this->run ?? static fn (): ClassifierResult => throw new RuntimeException('unset'))($model, $context, $options);
            }
        };

        ProviderRegistry::register(new Provider(
            id: 'zzimg',
            name: 'Zz Images',
            models: [],
            classifiers: [self::classifierModel()],
            images: [self::imageModel()],
            imageApi: $withImplementations ? $imageApi : null,
            classifierApi: $withImplementations ? $classifierApi : null,
        ));
    }

    public function testAnExtensionImageModelIsGeneratedByItsProvidersImplementationWithTheKeyAndItsUsage(): void
    {
        $this->register(images: static fn (ImageModel $model, ImagesContext $context): AssistantImages => new AssistantImages(
            $model->api,
            $model->provider,
            $model->id,
            [new TextContent('here'), new ImageContent('aGk=', 'image/png')],
            StopReason::Stop,
            Timestamp::nowMs(),
            usage: new Usage(10, 20, 0, 0, 30, new Cost(0.00002, 0.0002, 0.0, 0.0, 0.00022)),
        ));

        $model = Models::findOfType(ModelType::Image, 'zzimg', 'pic-1');
        $this->assertInstanceOf(ImageModel::class, $model, 'the image model is in the catalogue');
        $result = Models::generateImages($model, new ImagesContext([new TextContent('a cat')]), new ImagesOptions(apiKey: 'sk-zz', metadata: ['aspectRatio' => '16:9']));

        $this->assertSame(StopReason::Stop, $result->stopReason);
        $this->assertSame(ImageApi::Extension, $result->api);
        $this->assertInstanceOf(ImageContent::class, $result->output[1]);
        $this->assertSame(30, $result->usage?->totalTokens);
        $this->assertSame(0.00022, $result->usage?->cost->total);
        $this->assertSame([['images', 'sk-zz', ['aspectRatio' => '16:9']]], $this->seen, 'the key and the metadata reach the implementation');
    }

    public function testEveryImageFailureIsAResultAndNeverAThrow(): void
    {
        $this->register(images: static fn (): AssistantImages => throw new RuntimeException('backend down'));
        $context = new ImagesContext([new TextContent('a cat')]);

        $thrown = Models::generateImages(self::imageModel(), $context, new ImagesOptions(apiKey: 'k'));
        $this->assertSame(StopReason::Error, $thrown->stopReason);
        $this->assertSame('backend down', $thrown->errorMessage);
        $this->assertSame([], $thrown->output);

        $aborter = new AbortController();
        $aborter->abort();
        $this->assertSame(StopReason::Aborted, Models::generateImages(self::imageModel(), $context, new ImagesOptions(signal: $aborter->signal, apiKey: 'k'))->stopReason);

        $this->seen = [];
        $unconfigured = Models::generateImages(self::imageModel(), $context);
        $this->assertSame('Provider is not configured: zzimg', $unconfigured->errorMessage);
        $this->assertSame([], $this->seen, 'no key, no call');
    }

    public function testAnExtensionModelWithNoImplementationBehindItIsRefusedByName(): void
    {
        $this->register(withImplementations: false);

        $result = Models::generateImages(self::imageModel(), new ImagesContext([new TextContent('x')]), new ImagesOptions(apiKey: 'k'));
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('No extension provides image generation for zzimg/pic-1. Is its extension loaded?', $result->errorMessage);

        $classified = Models::classify(self::classifierModel(), new ClassifierContext(['a' => 1], ['q' => new ClassifierBoolQuestion('Is it?', [])]), new ClassifierOptions(apiKey: 'k'));
        $this->assertSame('No extension provides classification for zzimg/judge-1. Is its extension loaded?', $classified->errorMessage);
    }

    public function testAnExtensionClassifierIsAskedThroughItsProvidersImplementation(): void
    {
        $this->register(classify: static fn (ClassifierModel $model): ClassifierResult => new ClassifierResult(
            $model->api,
            $model->provider,
            $model->id,
            ['q' => new ClassifierBoolAnswer(0.9)],
            StopReason::Stop,
            Timestamp::nowMs(),
            new Usage(5, 0, 0, 0, 5, new Cost(0.000005, 0.0, 0.0, 0.0, 0.000005)),
        ));
        $context = new ClassifierContext(['a' => 1], ['q' => new ClassifierBoolQuestion('Is it?', [])]);

        $result = Models::classify(self::classifierModel(), $context, new ClassifierOptions(apiKey: 'sk-c'));
        $this->assertSame(StopReason::Stop, $result->stopReason);
        $this->assertSame(0.9, $result->answers['q']->probability ?? null);
        $this->assertSame(0.000005, $result->usage?->cost->total);
        $this->assertSame('sk-c', $this->seen[0][1]);

        // Upstream's `assertClassifierInputSupported()` runs before the provider is asked.
        $this->seen = [];
        $images = Models::classify(self::classifierModel(), new ClassifierContext(['a' => 1], $context->questions, [new ImageContent('aGk=', 'image/png')]), new ClassifierOptions(apiKey: 'sk-c'));
        $this->assertSame('Model zzimg/judge-1 does not accept image input', $images->errorMessage);
        $this->assertSame([], $this->seen);
    }

    public function testAClassifierThatThrowsIsAnErrorResult(): void
    {
        $this->register(classify: static fn (): ClassifierResult => throw new RuntimeException('nope'));

        $result = Models::classify(self::classifierModel(), new ClassifierContext(['a' => 1], ['q' => new ClassifierBoolQuestion('Is it?', [])]), new ClassifierOptions(apiKey: 'k'));
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('nope', $result->errorMessage);
        $this->assertSame(ClassifierApi::Extension, $result->api);
    }
}
