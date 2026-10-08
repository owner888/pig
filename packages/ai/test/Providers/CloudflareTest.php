<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\Cloudflare;
use Pig\Ai\Providers\CloudflareAiBinding;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\ScriptedServer;
use TypeError;

/**
 * Cloudflare's two providers: upstream's `cloudflare-stream.test.ts` (the endpoint placeholders
 * filled in before dispatch, kept when the env has nothing for them), the auth `cloudflare-auth.ts`
 * resolves (the key and the ids, and the gateway's `cf-aig-authorization` in place of the SDKs' own
 * auth headers), and `cloudflare-ai-binding.test.ts`' check of the binding.
 *
 * The requests go to a `ScriptedServer` standing in for `api.cloudflare.com` and
 * `gateway.ai.cloudflare.com`, the row's base URL rehosted with its placeholders kept.
 */
final class CloudflareTest extends TestCase
{
    use AssertsThrows;

    private ?ScriptedServer $server = null;

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

    public function testTheEndpointIsMaterialisedFromTheProviderEnvBeforeDispatch(): void
    {
        $model = self::model(Api::OpenAiCompletions, 'https://gateway.ai.cloudflare.com/v1/{CLOUDFLARE_ACCOUNT_ID}/{CLOUDFLARE_GATEWAY_ID}/openai');

        $resolved = Cloudflare::resolveCloudflareModel($model, ['CLOUDFLARE_ACCOUNT_ID' => 'account', 'CLOUDFLARE_GATEWAY_ID' => 'gateway']);

        $this->assertSame('https://gateway.ai.cloudflare.com/v1/account/gateway/openai', $resolved->baseUrl);
    }

    public function testPlaceholdersTheEnvDoesNotResolveAreKept(): void
    {
        $model = self::model(Api::OpenAiCompletions, 'https://gateway.ai.cloudflare.com/v1/{CLOUDFLARE_ACCOUNT_ID}/{CLOUDFLARE_GATEWAY_ID}/openai');

        $this->assertSame($model, Cloudflare::resolveCloudflareModel($model, []));
        $this->assertSame($model, Cloudflare::resolveCloudflareModel($model, null));
    }

    public function testWorkersAiSendsTheKeyAsABearerToTheAccountsEndpoint(): void
    {
        $model = $this->rehosted(self::row('cloudflare-workers-ai', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'), 'https://api.cloudflare.com');

        $message = self::send($model, new SimpleStreamOptions(apiKey: 'cf-key', sessionId: 'session-1', env: ['CLOUDFLARE_ACCOUNT_ID' => 'account-id']));

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $request = $this->server()->requests[0];
        $this->assertSame('/client/v4/accounts/account-id/ai/v1/chat/completions', $request['path']);
        $this->assertSame('Bearer cf-key', $request['headers']['authorization'] ?? null);
        $this->assertArrayNotHasKey('cf-aig-authorization', $request['headers']);
        // `{sendSessionAffinityHeaders: true}` on every Workers AI row.
        $this->assertSame('session-1', $request['headers']['x-session-affinity'] ?? null);
    }

    public function testTheGatewaySendsTheKeyAsCfAigAuthorizationAndNoProviderAuth(): void
    {
        $model = $this->rehosted(self::row('cloudflare-ai-gateway', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'), 'https://gateway.ai.cloudflare.com');

        $message = self::send($model, new SimpleStreamOptions(apiKey: 'cf-key', env: ['CLOUDFLARE_ACCOUNT_ID' => 'account-id', 'CLOUDFLARE_GATEWAY_ID' => 'gw']));

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $request = $this->server()->requests[0];
        $this->assertSame('/v1/account-id/gw/compat/chat/completions', $request['path']);
        $this->assertSame('Bearer cf-key', $request['headers']['cf-aig-authorization'] ?? null);
        $this->assertArrayNotHasKey('authorization', $request['headers']);
        $this->assertSame('workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast', json_decode($request['body'], true)['model']);
    }

    public function testTheGatewaysClaudeGoesToTheAnthropicPassthroughWithoutAnApiKeyHeader(): void
    {
        $model = $this->rehosted(self::row('cloudflare-ai-gateway', 'claude-sonnet-4-5'), 'https://gateway.ai.cloudflare.com', anthropic: true);

        $message = self::send($model, new SimpleStreamOptions(apiKey: 'cf-key', env: ['CLOUDFLARE_ACCOUNT_ID' => 'account-id', 'CLOUDFLARE_GATEWAY_ID' => 'gw']));

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $request = $this->server()->requests[0];
        $this->assertSame('/v1/account-id/gw/anthropic/v1/messages', $request['path']);
        $this->assertSame('Bearer cf-key', $request['headers']['cf-aig-authorization'] ?? null);
        $this->assertArrayNotHasKey('x-api-key', $request['headers']);
        $this->assertArrayNotHasKey('authorization', $request['headers']);
    }

    public function testACallersOwnAuthHeaderWinsOverTheGateways(): void
    {
        $model = $this->rehosted(self::row('cloudflare-ai-gateway', 'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast'), 'https://gateway.ai.cloudflare.com');

        self::send($model, new SimpleStreamOptions(apiKey: 'cf-key', headers: ['CF-AIG-Authorization' => 'Bearer mine'], env: ['CLOUDFLARE_ACCOUNT_ID' => 'a', 'CLOUDFLARE_GATEWAY_ID' => 'g']));

        $this->assertSame('Bearer mine', $this->server()->requests[0]['headers']['cf-aig-authorization'] ?? null);
    }

    public function testWithoutTheAccountOrTheGatewayTheProviderIsNotConfigured(): void
    {
        $this->assertThrows(
            ProviderError::class,
            static fn () => Stream::simple(self::row('cloudflare-workers-ai', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'), new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: 'cf-key', env: ['CLOUDFLARE_ACCOUNT_ID' => ''])),
            'Provider is not configured: cloudflare-workers-ai',
        );
        $this->assertThrows(
            ProviderError::class,
            static fn () => Stream::simple(self::row('cloudflare-ai-gateway', 'gpt-5'), new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: 'cf-key', env: ['CLOUDFLARE_ACCOUNT_ID' => 'a', 'CLOUDFLARE_GATEWAY_ID' => ''])),
            'Provider is not configured: cloudflare-ai-gateway',
        );
    }

    public function testBothProvidersReadUpstreamsKeyVariable(): void
    {
        $this->assertSame('k', Stream::envApiKey('cloudflare-workers-ai', ['CLOUDFLARE_API_KEY' => 'k']));
        $this->assertSame('k', Stream::envApiKey('cloudflare-ai-gateway', ['CLOUDFLARE_API_KEY' => 'k']));
    }

    public function testTheGatewayRowsAreOnTheirPassthroughsAndResoldIdsStayTheirMakers(): void
    {
        $this->assertSame([Api::AnthropicMessages, Cloudflare::CLOUDFLARE_AI_GATEWAY_ANTHROPIC_BASE_URL], [self::row('cloudflare-ai-gateway', 'claude-opus-5-5')->api, self::row('cloudflare-ai-gateway', 'claude-opus-5-5')->baseUrl]);
        $this->assertSame([Api::OpenAiResponses, Cloudflare::CLOUDFLARE_AI_GATEWAY_OPENAI_BASE_URL], [self::row('cloudflare-ai-gateway', 'gpt-5.5')->api, self::row('cloudflare-ai-gateway', 'gpt-5.5')->baseUrl]);
        $this->assertSame(Cloudflare::CLOUDFLARE_WORKERS_AI_BASE_URL, self::row('cloudflare-workers-ai', '@cf/openai/gpt-oss-120b')->baseUrl);
        $this->assertSame('anthropic', Models::get('claude-opus-5-5')?->provider);
        $this->assertSame('openai', Models::get('gpt-5.5')?->provider);
    }

    public function testTheAiBindingMustExposeFetch(): void
    {
        $this->assertThrows(TypeError::class, static fn () => CloudflareAiBinding::createAiBindingFetch(new \stdClass()), 'createAiBindingFetch: the AI binding does not expose fetch()');

        $binding = new class () {
            /** @var list<array{0: mixed, 1: mixed}> */
            public array $calls = [];

            public function fetch(mixed $input, mixed $init = null): string
            {
                $this->calls[] = [$input, $init];

                return 'response';
            }
        };
        $fetch = CloudflareAiBinding::createAiBindingFetch($binding);

        $this->assertSame('response', $fetch('https://workers-binding.ai/ai-gateway/gateways/g/anthropic/v1/messages', ['method' => 'POST']));
        $this->assertSame([['https://workers-binding.ai/ai-gateway/gateways/g/anthropic/v1/messages', ['method' => 'POST']]], $binding->calls);
        $this->assertSame('cloudflare-gateway-binding', CloudflareAiBinding::CLOUDFLARE_GATEWAY_BINDING_AUTH_SENTINEL);
    }

    private static function model(Api $api, string $baseUrl): Model
    {
        return new Model('model', 'model', $api, 'cloudflare-ai-gateway', $baseUrl, 1000, 100);
    }

    private static function row(string $provider, string $id): Model
    {
        $model = Models::find($provider, $id);
        self::assertNotNull($model, "{$provider}/{$id}");

        return $model;
    }

    /** The row with its host swapped for a server that answers as the row's API does, placeholders kept. */
    private function rehosted(Model $model, string $host, bool $anthropic = false): Model
    {
        $this->server = new ScriptedServer();
        $base = $this->server->start(static fn (): array => [200, ['content-type' => 'text/event-stream'], $anthropic ? self::anthropicStream() : self::completionsStream()]);

        return new Model($model->id, $model->name, $model->api, $model->provider, str_replace($host, $base, $model->baseUrl), $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, $model->headers, $model->compat, $model->thinkingLevelMap, $model->inputLimits, $model->promptCache);
    }

    private function server(): ScriptedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }

    private static function send(Model $model, SimpleStreamOptions $options): AssistantMessage
    {
        return Async::run(static fn (): AssistantMessage => Stream::simple($model, new Context([new UserMessage('hi')]), $options)->result()->await());
    }

    private static function completionsStream(): string
    {
        return 'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    private static function anthropicStream(): string
    {
        $event = static fn (string $type, array $data): string => "event: {$type}\ndata: " . json_encode(['type' => $type, ...$data]) . "\n\n";

        return $event('message_start', ['message' => ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'model' => 'm', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]])
            . $event('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
            . $event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']])
            . $event('content_block_stop', ['index' => 0])
            . $event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]])
            . $event('message_stop', []);
    }
}
