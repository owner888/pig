<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\AssistantImages;
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
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\ScriptedServer;
use Pig\Test\WithoutProviderKeys;

/**
 * Upstream's `openrouter-images.test.ts` (which fakes the `openai` SDK; here a server answers its
 * one request) and the catalogue half of `images-models.test.ts` / `image-model-data.test.ts`.
 */
final class OpenRouterImagesTest extends TestCase
{
    use WithoutProviderKeys;

    private ?ScriptedServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->forgetProviderKeys();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->restoreProviderKeys();
    }

    public function testTextAndImagesBothReachTheOutput(): void
    {
        $model = $this->served(['text', 'image'], ['HTTP-Referer' => 'https://example.com']);

        $output = self::generate($model, new ImagesContext([new TextContent('Generate a dog')]), new ImagesOptions(apiKey: 'test'));

        $this->assertSame(StopReason::Stop, $output->stopReason, (string) $output->errorMessage);
        $this->assertSame('img-1', $output->responseId);
        $this->assertEquals(new TextContent('Here is your image.'), $output->output[0]);
        $this->assertEquals(new ImageContent('ZmFrZS1wbmc=', 'image/png'), $output->output[1]);

        $request = $this->server()->requests[0];
        $this->assertSame('/api/v1/chat/completions', $request['path']);
        $this->assertSame('Bearer test', $request['headers']['authorization'] ?? null);
        $this->assertSame('https://example.com', $request['headers']['http-referer'] ?? null);
        $params = json_decode($request['body'], true);
        $this->assertFalse($params['stream']);
        $this->assertSame(['image', 'text'], $params['modalities']);
        $this->assertSame(['type' => 'text', 'text' => 'Generate a dog'], $params['messages'][0]['content'][0]);
        // Upstream's own `parseUsage()`: the prompt at the input rate, the completion at the output's.
        $this->assertSame([12, 34, 46], [$output->usage?->input, $output->usage?->output, $output->usage?->totalTokens]);
        $this->assertEqualsWithDelta(0.015 / 1e6 * 12 + 0.03 / 1e6 * 34, $output->usage?->cost->total, 1e-15);
    }

    public function testAnImageOnlyModelDoesNotAskForTextAndReferenceImagesGoAsDataUrls(): void
    {
        $model = $this->served(['image']);

        $output = self::generate($model, new ImagesContext([new TextContent('Make it red'), new ImageContent('aGk=', 'image/jpeg')]), new ImagesOptions(apiKey: 'test'));

        $this->assertTrue((bool) array_filter($output->output, static fn ($item): bool => $item instanceof ImageContent));
        $params = json_decode($this->server()->requests[0]['body'], true);
        // "Image-only models must not request text output."
        $this->assertSame(['image'], $params['modalities']);
        $this->assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,aGk=']], $params['messages'][0]['content'][1]);
    }

    public function testACancelledRequestIsAbortedAndSaysSo(): void
    {
        $model = $this->served(['image']);
        $controller = new AbortController();
        $controller->abort();

        $output = self::generate($model, new ImagesContext([new TextContent('Generate a dog')]), new ImagesOptions(apiKey: 'test', signal: $controller->signal));

        $this->assertSame(StopReason::Aborted, $output->stopReason);
        $this->assertSame('Request aborted', $output->errorMessage);
        $this->assertSame([], $this->server()->requests);
    }

    public function testARefusalIsTheSdksErrorAndNotAThrow(): void
    {
        $this->server = new ScriptedServer();
        $base = $this->server->start(static fn (): array => [402, [], '{"error":{"message":"Insufficient credits","code":402}}']);
        $model = new ImageModel('black-forest-labs/flux.2-pro', 'FLUX.2 Pro', ImageApi::OpenRouterImages, 'openrouter', $base . '/api/v1', ['text', 'image'], ['image']);

        $output = self::generate($model, new ImagesContext([new TextContent('x')]), new ImagesOptions(apiKey: 'test', maxRetries: 0));

        $this->assertSame(StopReason::Error, $output->stopReason);
        $this->assertSame('402: {"message":"Insufficient credits","code":402}', $output->errorMessage);
    }

    public function testWithoutAKeyTheProviderIsNotConfigured(): void
    {
        $model = Models::findOfType(ModelType::Image, 'openrouter', 'black-forest-labs/flux.2-pro');
        $this->assertInstanceOf(ImageModel::class, $model);

        $output = Models::generateImages($model, new ImagesContext([new TextContent('x')]));

        $this->assertSame(StopReason::Error, $output->stopReason);
        $this->assertSame('Provider is not configured: openrouter', $output->errorMessage);
    }

    public function testOpenRoutersImageModelsAreImageModelsOnly(): void
    {
        $images = Models::allOfType(ModelType::Image);

        $this->assertCount(61, $images);

        foreach ($images as $model) {
            $this->assertSame(['openrouter', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1'], [$model->provider, $model->api, $model->baseUrl], $model->id);
            $this->assertContains('image', $model->output, $model->id);
            // `applyImageInputMetadata()`: the default resize on a model that takes images, nothing otherwise.
            $this->assertSame(in_array('image', $model->input, true) ? ['images' => ['resize' => ['maxWidth' => 2000, 'maxHeight' => 2000, 'maxBytes' => 4_718_592, 'jpegQuality' => 80]]] : null, $model->inputLimits, $model->id);
        }

        // An id served for chat and for images is two entries, one per operation.
        $this->assertNotNull(Models::find('openrouter', 'openrouter/auto'));
        $this->assertInstanceOf(ImageModel::class, Models::findOfType(ModelType::Image, 'openrouter', 'openrouter/auto'));
        $this->assertNull(Models::find('openrouter', 'black-forest-labs/flux.2-pro'));
    }

    /**
     * @param list<string> $output
     * @param array<string, string> $headers
     */
    private function served(array $output, array $headers = []): ImageModel
    {
        $this->server = new ScriptedServer();
        $base = $this->server->start(static fn (): array => [200, [], json_encode([
            'id' => 'img-1',
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 34, 'prompt_tokens_details' => ['cached_tokens' => 0]],
            'choices' => [['message' => ['content' => 'Here is your image.', 'images' => [['image_url' => 'data:image/png;base64,ZmFrZS1wbmc=']]]]],
        ])]);

        return new ImageModel('google/gemini-3.1-flash-image-preview', 'Gemini 3.1 Flash Image Preview', ImageApi::OpenRouterImages, 'openrouter', $base . '/api/v1', ['text', 'image'], $output, new Pricing(0.015, 0.03), $headers);
    }

    private function server(): ScriptedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }

    private static function generate(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages
    {
        return Async::run(static fn (): AssistantImages => Models::generateImages($model, $context, $options));
    }
}
