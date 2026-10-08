<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Upstream's `xai-responses.test.ts`: `xaiProvider()` serves every built-in xAI model through the
 * Responses API (`openAIResponsesApi()`), where pig used to send them to Chat Completions. The
 * request is checked against a canned server standing in for `https://api.x.ai/v1`, the row's base
 * URL swapped for its address; upstream mocks `fetch` and reads the same request.
 *
 * Not ported: the User-Agent cases (pig's own `PigUserAgent`, checked in `RequestOptionsTest`) and
 * the xAI OAuth sign-in, which pig does not have.
 */
final class XaiResponsesTest extends TestCase
{
    private ?CannedServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testRetiredAndRedundantModelsAreNotInTheBuiltInCatalogue(): void
    {
        foreach (['grok-3', 'grok-3-fast', 'grok-4.20-0309-non-reasoning', 'grok-4.20-0309-reasoning', 'grok-build-0.1', 'grok-code-fast-1'] as $id) {
            $this->assertNull(Models::find('xai', $id), $id);
        }
    }

    public function testEveryBuiltInXaiModelIsRoutedThroughResponses(): void
    {
        $models = array_values(array_filter(Models::all(), static fn (Model $model): bool => $model->provider === 'xai'));
        $this->assertNotSame([], $models);

        foreach ($models as $model) {
            $this->assertSame(Api::OpenAiResponses, $model->api, $model->id);
            $this->assertSame('https://api.x.ai/v1', $model->baseUrl, $model->id);
            // `XAI_RESPONSES_COMPAT`.
            $this->assertEquals(new OpenAiCompat(supportsLongCacheRetention: false), $model->compat, $model->id);
        }

        $this->assertSame(['low', 'medium', 'high'], self::model('grok-4.5')->supportedThinkingLevels());
        $this->assertSame(['low', 'medium', 'high', 'xhigh'], self::model('grok-4.6')->supportedThinkingLevels());
        $this->assertSame(['low', 'medium', 'high', 'xhigh'], self::model('grok-4.7')->supportedThinkingLevels());
        $this->assertSame(['off', 'low', 'medium', 'high'], self::model('grok-4.3')->supportedThinkingLevels());
    }

    public function testGrok47CarriesItsCapabilitiesAndLongContextPricing(): void
    {
        $model = self::model('grok-4.7');

        $this->assertSame([true, ['text', 'image'], 500_000, 500_000], [$model->reasoning, $model->input, $model->contextWindow, $model->maxTokens]);
        $this->assertSame([2.0, 6.0, 0.5, 0.0], [$model->pricing->input, $model->pricing->output, $model->pricing->cacheRead, $model->pricing->cacheWrite]);
        $this->assertCount(1, $model->pricing->tiers);
        $tier = $model->pricing->tiers[0];
        $this->assertSame([200_000, 4.0, 12.0, 1.0, 0.0], [$tier->inputTokensAbove, $tier->input, $tier->output, $tier->cacheRead, $tier->cacheWrite]);
    }

    public function testResponsesWithBearerAuthAndXaiCompatibleRequestFields(): void
    {
        $this->send(self::model('grok-4.5'), new OpenAiOptions(
            apiKey: 'xai-test-token',
            sessionId: 'pi-session-123',
            cacheRetention: 'long',
            reasoning: ReasoningEffort::Medium,
        ), 'You are a careful coding assistant.');

        $head = $this->server()->receivedHead();
        $this->assertStringStartsWith('POST /responses HTTP/1.1', $head);
        $this->assertMatchesRegularExpression('/^authorization: Bearer xai-test-token\r?$/mi', $head);
        $this->assertMatchesRegularExpression('/^session_id: pi-session-123\r?$/mi', $head);

        $body = $this->server()->receivedJson();
        $this->assertSame('grok-4.5', $body['model'] ?? null);
        $this->assertFalse($body['store'] ?? null);
        $this->assertTrue($body['stream'] ?? null);
        $this->assertSame('pi-session-123', $body['prompt_cache_key'] ?? null);
        // `toMatchObject()`: the effort; `summary` rides along as it does for every Responses model.
        $this->assertSame('medium', $body['reasoning']['effort'] ?? null);
        $this->assertSame(['reasoning.encrypted_content'], $body['include'] ?? null);
        // `XAI_RESPONSES_COMPAT`: no `prompt_cache_retention` for `cacheRetention: long`.
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);
        $this->assertContains(['role' => 'developer', 'content' => 'You are a careful coding assistant.'], $body['input'] ?? []);
    }

    public function testEncryptedReasoningIsAskedForWithoutAnEffortOverride(): void
    {
        $this->send(self::model('grok-4.5'), new OpenAiOptions(apiKey: 'xai-test-token'));

        $body = $this->server()->receivedJson();
        $this->assertSame(['grok-4.5', false, ['reasoning.encrypted_content']], [$body['model'] ?? null, $body['store'] ?? null, $body['include'] ?? null]);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testGrok47TakesXhighEffortOverResponses(): void
    {
        $this->send(self::model('grok-4.7'), new OpenAiOptions(apiKey: 'xai-test-token', reasoning: ReasoningEffort::Xhigh), 'You are a careful coding assistant.');

        $this->assertStringStartsWith('POST /responses HTTP/1.1', $this->server()->receivedHead());
        $body = $this->server()->receivedJson();
        $this->assertSame('xhigh', $body['reasoning']['effort'] ?? null);
        $this->assertSame(['reasoning.encrypted_content'], $body['include'] ?? null);
    }

    public function testGrok43OverResponses(): void
    {
        $this->send(self::model('grok-4.3'), new OpenAiOptions(apiKey: 'xai-test-token', reasoning: ReasoningEffort::Low));

        $this->assertStringStartsWith('POST /responses HTTP/1.1', $this->server()->receivedHead());
        $body = $this->server()->receivedJson();
        $this->assertSame(['grok-4.3', false, ['reasoning.encrypted_content'], 'low'], [$body['model'] ?? null, $body['store'] ?? null, $body['include'] ?? null, $body['reasoning']['effort'] ?? null]);
    }

    private static function model(string $id): Model
    {
        $model = Models::find('xai', $id);
        self::assertNotNull($model, "xai/{$id}");

        return $model;
    }

    private function send(Model $model, OpenAiOptions $options, ?string $systemPrompt = null): void
    {
        $this->server = new CannedServer();
        $event = [
            'type' => 'response.completed',
            'sequence_number' => 0,
            'response' => [
                'id' => 'resp_xai_test',
                'status' => 'completed',
                'output' => [],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2, 'input_tokens_details' => ['cached_tokens' => 0]],
            ],
        ];
        $base = rtrim($this->server->start([
            "HTTP/1.1 200 OK\r\ncontent-type: text/event-stream\r\n\r\n",
            'data: ' . json_encode($event) . "\n\ndata: [DONE]\n\n",
        ]), '/');
        $served = new Model($model->id, $model->name, $model->api, $model->provider, $base, $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, $model->headers, $model->compat, $model->thinkingLevelMap, $model->inputLimits, $model->promptCache);

        $message = Async::run(static fn (): AssistantMessage => Stream::start($served, new Context([new UserMessage('hello')], $systemPrompt), $options)->result()->await());

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
    }

    private function server(): CannedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }
}
