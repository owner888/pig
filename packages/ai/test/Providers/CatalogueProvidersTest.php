<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\StreamOptions;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use RuntimeException;

/**
 * The built-in rows of the providers upstream's catalogue adds on the APIs pig already speaks, as
 * upstream's per-provider tests drive them: `baseten-models.test.ts`, `together-models.test.ts`,
 * `fireworks-models.test.ts`, `qwen-token-plan-models.test.ts`, `xiaomi-models.test.ts`,
 * `zai-coding-plan-models.test.ts`, `openrouter-cache-control-models.test.ts` and
 * `opencode-provider-headers.test.ts`.
 *
 * Upstream reads the payload in `onPayload` and throws to stop the request there; so does this, so
 * the rows are checked through `Stream` — compat, level map and all — without a request leaving.
 * The OpenCode header and Fireworks' session affinity are headers, which `onPayload` does not see,
 * so those go to a canned server with the row's base URL pointed at it.
 */
final class CatalogueProvidersTest extends TestCase
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

    public function testBasetensGlm52EndpointsAreTextOnly(): void
    {
        $this->assertSame(['text'], self::model('baseten', 'zai-org/GLM-5.2')->input);
        $this->assertSame(['text'], self::model('baseten', 'zai-org/GLM-5.2-Fast')->input);
    }

    public function testBasetenModelsKimiK26ReasoningAsAnExplicitOffOnToggle(): void
    {
        $model = self::model('baseten', 'moonshotai/Kimi-K2.6');

        $this->assertSame(['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], $model->thinkingLevelMap);
        $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
        $this->assertSame([false, 'baseten', ['enable_thinking' => ['$var' => 'thinking.enabled']]], [$model->compat->reasoningEffort, $model->compat->thinkingFormat, $model->compat->chatTemplateArgs]);
        $this->assertSame(['off', 'high'], $model->supportedThinkingLevels());

        $payload = self::payload($model, ReasoningEffort::High);

        $this->assertSame(['enable_thinking' => true], $payload['chat_template_args'] ?? null);
        $this->assertArrayNotHasKey('reasoning_effort', $payload);
    }

    public function testBasetenSendsItsTemplateArgumentsWithTheReasoningEffort(): void
    {
        $payload = self::payload(self::model('baseten', 'zai-org/GLM-5.2'), ReasoningEffort::High);

        $this->assertSame(['enable_thinking' => true], $payload['chat_template_args'] ?? null);
        $this->assertSame('high', $payload['reasoning_effort'] ?? null);
    }

    public function testBasetenTurnsOptInReasoningOffWhenThinkingIsOff(): void
    {
        $payload = self::payload(self::model('baseten', 'zai-org/GLM-5.2'), null);

        $this->assertSame(['enable_thinking' => false], $payload['chat_template_args'] ?? null);
        $this->assertSame('none', $payload['reasoning_effort'] ?? null);
    }

    public function testTogetherServesKimiK3OverChatCompletions(): void
    {
        $model = self::model('together', 'moonshotai/Kimi-K3');

        $this->assertSame([Api::OpenAiCompletions, 'https://api.together.ai/v1', true], [$model->api, $model->baseUrl, $model->reasoning]);
        $this->assertSame(['minimal' => null, 'low' => null, 'medium' => null], $model->thinkingLevelMap);
        $this->assertSame(['text', 'image'], $model->input);
        $this->assertSame([1_048_576, 131_072], [$model->contextWindow, $model->maxTokens]);
        $this->assertSame([3.0, 15.0, 0.3, 0.0], [$model->pricing->input, $model->pricing->output, $model->pricing->cacheRead, $model->pricing->cacheWrite]);
        $this->assertEquals(new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningEffort: false,
            maxTokensField: 'max_tokens',
            strictMode: false,
            thinkingFormat: 'together',
            supportsLongCacheRetention: false,
        ), $model->compat);
    }

    public function testTogethersReasoningControlsAreItsApisOwn(): void
    {
        $gptOss = self::model('together', 'openai/gpt-oss-120b');
        $this->assertEquals(['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'max' => null, 'xhigh' => null], $gptOss->thinkingLevelMap);
        $this->assertInstanceOf(OpenAiCompat::class, $gptOss->compat);
        $this->assertSame([true, 'openai'], [$gptOss->compat->reasoningEffort, $gptOss->compat->thinkingFormat]);

        $deepSeek = self::model('together', 'deepseek-ai/DeepSeek-V4-Pro-0813');
        $this->assertSame(['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null], $deepSeek->thinkingLevelMap);
        $this->assertInstanceOf(OpenAiCompat::class, $deepSeek->compat);
        $this->assertSame([true, 'together'], [$deepSeek->compat->reasoningEffort, $deepSeek->compat->thinkingFormat]);

        $minimax = self::model('together', 'MiniMaxAI/MiniMax-M2.7');
        $this->assertSame(['off' => null, 'minimal' => null, 'low' => null, 'medium' => null], $minimax->thinkingLevelMap);
        $this->assertInstanceOf(OpenAiCompat::class, $minimax->compat);
        $this->assertSame([null, false], [$minimax->compat->thinkingFormat, $minimax->compat->reasoningEffort]);
    }

    public function testFireworksServesItsOtherModelsOverItsMessagesApi(): void
    {
        $model = self::model('fireworks', 'accounts/fireworks/models/qwen3p8-max');

        $this->assertSame([Api::AnthropicMessages, 'https://api.fireworks.ai/inference', true], [$model->api, $model->baseUrl, $model->reasoning]);
        $this->assertInstanceOf(AnthropicCompat::class, $model->compat);
        // "sets Fireworks-specific compat for session affinity and unsupported tool fields"
        $this->assertSame(
            [true, false, false, false, true],
            [$model->compat->sendSessionAffinityHeaders, $model->compat->supportsEagerToolInputStreaming, $model->compat->supportsCacheControlOnTools, $model->compat->supportsLongCacheRetention, $model->compat->allowEmptySignature],
        );
    }

    public function testFireworksRoutesKimiK3ThroughChatCompletionsWithNativeEffort(): void
    {
        $model = self::model('fireworks', 'accounts/fireworks/models/kimi-k3');

        $this->assertSame([Api::OpenAiCompletions, 'https://api.fireworks.ai/inference/v1'], [$model->api, $model->baseUrl]);
        $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
        $this->assertSame([true, 'openai', true, true], [$model->compat->reasoningContentOnAssistantMessages, $model->compat->thinkingFormat, $model->compat->supportsMidConvoSystemMessages, $model->compat->supportsMidConvoToolAdditions]);
        // "Fireworks maps medium to high on both APIs; do not expose it as a distinct level."
        $this->assertTrue(array_key_exists('medium', $model->thinkingLevelMap) && $model->thinkingLevelMap['medium'] === null);
        $this->assertSame('high', self::payload($model, ReasoningEffort::High)['reasoning_effort'] ?? null);
    }

    public function testFireworksGlmIsSentNoPromptCacheRetention(): void
    {
        $payload = self::payload(self::model('fireworks', 'accounts/fireworks/models/glm-5p3'), null, 'long');

        $this->assertArrayNotHasKey('prompt_cache_retention', $payload);
    }

    public function testFireworksMessagesModelsWithEffortThinkAdaptively(): void
    {
        $model = self::model('fireworks', 'accounts/fireworks/models/qwen3p8-2p4t-a95b');

        $this->assertInstanceOf(AnthropicCompat::class, $model->compat);
        $this->assertTrue($model->compat->forceAdaptiveThinking);
        // "The 2.4T alias omits the verified toggle in models.dev": off is `none` on it anyway.
        $this->assertSame('none', $model->thinkingLevelMap['off'] ?? null);

        $payload = self::payload($model, ReasoningEffort::Xhigh);

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $payload['thinking'] ?? null);
        $this->assertSame(['effort' => 'xhigh'], $payload['output_config'] ?? null);
    }

    public function testFireworksSendsItsSessionAffinityHeaderAndNoCacheControlOnTools(): void
    {
        $model = $this->served(self::model('fireworks', 'accounts/fireworks/models/qwen3p8-max'), self::anthropicStream());
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => (object) []]);

        $this->send($model, new StreamOptions(apiKey: 'test-fireworks-key', sessionId: 'fireworks-session-1'), [$tool]);

        $this->assertStringContainsString("x-session-affinity: fireworks-session-1\r\n", strtolower($this->server()->received()));
        $tools = $this->server()->receivedJson()['tools'] ?? [];
        $this->assertArrayNotHasKey('cache_control', end($tools));
        $this->assertArrayNotHasKey('eager_input_streaming', $tools[0]);
    }

    public function testEveryQwenTokenPlanThinkingModelIsSentQwensThinkingField(): void
    {
        foreach (['qwen-token-plan', 'qwen-token-plan-cn'] as $provider) {
            foreach (['deepseek-v4-pro', 'glm-5.2', 'kimi-k2.6', 'qwen3.7-max', 'qwen3.8-flash'] as $id) {
                $payload = self::payload(self::model($provider, $id), ReasoningEffort::High);

                $this->assertTrue($payload['enable_thinking'] ?? null, "{$provider}/{$id}");
                $this->assertArrayNotHasKey('thinking', $payload, "{$provider}/{$id}");
            }
        }
    }

    public function testTheQwenTokenPlansReasoningEffortModelsSayAndSendTheirLevels(): void
    {
        foreach ([['qwen-token-plan', 'glm-5'], ['qwen-token-plan-cn', 'deepseek-v4-flash'], ['qwen-token-plan-individual', 'deepseek-v4-pro-0813']] as [$provider, $id]) {
            $model = self::model($provider, $id);

            foreach (['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'] as $level => $sent) {
                // `array_key_exists`, not `??`, which reads the null that refuses a level as no key.
                $this->assertTrue(array_key_exists($level, $model->thinkingLevelMap), "{$provider}/{$id} {$level}");
                $this->assertSame($sent, $model->thinkingLevelMap[$level], "{$provider}/{$id} {$level}");
            }

            $this->assertSame('high', self::payload($model, ReasoningEffort::High)['reasoning_effort'] ?? null, "{$provider}/{$id}");
        }

        $payload = self::payload(self::model('qwen-token-plan-individual', 'qwen3.8-max'), ReasoningEffort::Xhigh);
        $this->assertSame([true, 'xhigh'], [$payload['enable_thinking'] ?? null, $payload['reasoning_effort'] ?? null]);
    }

    public function testTheIndividualTokenPlanIsExactlyItsDocumentedTextModels(): void
    {
        $ids = array_map(static fn (Model $model): string => $model->id, self::of('qwen-token-plan-individual'));
        sort($ids);

        $this->assertSame(['deepseek-v4-flash-0731', 'deepseek-v4-pro', 'deepseek-v4-pro-0813', 'glm-5.2', 'qwen3.6-flash', 'qwen3.7-max', 'qwen3.7-plus', 'qwen3.8-flash', 'qwen3.8-max'], $ids);

        foreach (['qwen-token-plan', 'qwen-token-plan-cn', 'qwen-token-plan-individual'] as $provider) {
            $this->assertNull(Models::find($provider, 'qwen3.8-max-preview'), $provider);
            $this->assertNull(Models::find($provider, 'qwen-image-2.0'), $provider);
        }
    }

    public function testXiaomiKeepsItsReplacementModelsAndNotTheRetiredOnes(): void
    {
        foreach (['xiaomi', 'xiaomi-token-plan-cn', 'xiaomi-token-plan-ams', 'xiaomi-token-plan-sgp'] as $provider) {
            foreach (['mimo-v2-flash', 'mimo-v2-omni', 'mimo-v2-pro'] as $id) {
                $this->assertNull(Models::find($provider, $id), "{$provider}/{$id}");
            }

            foreach (['mimo-v2.5', 'mimo-v2.5-pro'] as $id) {
                $this->assertNotNull(Models::find($provider, $id), "{$provider}/{$id}");
            }
        }
    }

    public function testTheChinaCodingPlanCarriesGlm46vAndApiEquivalentCosts(): void
    {
        $model = self::model('zai-coding-cn', 'glm-4.6v');

        $this->assertSame([Api::OpenAiCompletions, 'https://open.bigmodel.cn/api/coding/paas/v4', true, ['text', 'image']], [$model->api, $model->baseUrl, $model->reasoning, $model->input]);
        $this->assertSame([0.3, 0.9, 0.0, 0.0], [$model->pricing->input, $model->pricing->output, $model->pricing->cacheRead, $model->pricing->cacheWrite]);
        $this->assertSame([128_000, 32_768], [$model->contextWindow, $model->maxTokens]);
        $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
        $this->assertSame(['max_tokens', 'zai', true], [$model->compat->maxTokensField, $model->compat->thinkingFormat, $model->compat->zaiToolStream]);

        $highspeed = self::model('zai-coding-cn', 'glm-5.3-highspeed');
        $this->assertSame([0.0, 0.0, 0.0, 0.0], [$highspeed->pricing->input, $highspeed->pricing->output, $highspeed->pricing->cacheRead, $highspeed->pricing->cacheWrite]);
        $this->assertSame([1.4, 4.4, 0.26], [self::model('zai-coding-cn', 'glm-5.3')->pricing->input, self::model('zai-coding-cn', 'glm-5.3')->pricing->output, self::model('zai-coding-cn', 'glm-5.3')->pricing->cacheRead]);
    }

    public function testOpenRoutersAnthropicLatestAliasesKeepCompletionsCacheControl(): void
    {
        foreach (['~anthropic/claude-fable-latest', '~anthropic/claude-haiku-latest', '~anthropic/claude-opus-latest', '~anthropic/claude-sonnet-latest'] as $id) {
            $model = self::model('openrouter', $id);

            $this->assertSame(Api::OpenAiCompletions, $model->api, $id);
            $this->assertInstanceOf(OpenAiCompat::class, $model->compat);
            $this->assertSame('anthropic', $model->compat->cacheControlFormat, $id);
        }
    }

    public function testOpenCodeIsSentItsSessionHeaderEvenWithoutCacheRetention(): void
    {
        $model = $this->served(self::model('opencode', 'glm-5.3'), self::completionsStream());

        $this->send($model, new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'none', sessionId: 'conversation-1'));

        $this->assertStringContainsString("x-opencode-session: conversation-1\r\n", strtolower($this->server()->received()));
    }

    public function testACallersOwnOpenCodeSessionHeaderWinsWhateverItsCase(): void
    {
        $model = $this->served(self::model('opencode-go', 'glm-5.3'), self::completionsStream());

        $this->send($model, new OpenAiOptions(apiKey: 'test-key', sessionId: 'generated-value', headers: ['X-OpenCode-Session' => 'caller-value']));

        $received = strtolower($this->server()->received());
        $this->assertStringContainsString("x-opencode-session: caller-value\r\n", $received);
        $this->assertStringNotContainsString('generated-value', $received);
    }

    public function testANullOpenCodeSessionHeaderSuppressesIt(): void
    {
        $model = $this->served(self::model('opencode', 'glm-5.3'), self::completionsStream());

        $this->send($model, new OpenAiOptions(apiKey: 'test-key', sessionId: 'generated-value', headers: ['X-OpenCode-Session' => null]));

        $this->assertStringNotContainsString('x-opencode-session', strtolower($this->server()->received()));
    }

    public function testNoOpenCodeSessionHeaderIsMadeUpWithoutASession(): void
    {
        $model = $this->served(self::model('opencode', 'glm-5.3'), self::completionsStream());

        $this->send($model, new OpenAiOptions(apiKey: 'test-key', headers: ['x-custom' => 'value']));

        $received = strtolower($this->server()->received());
        $this->assertStringContainsString("x-custom: value\r\n", $received);
        $this->assertStringNotContainsString('x-opencode-session', $received);
    }

    public function testAnotherProviderIsNotSentOpenCodesHeader(): void
    {
        $model = $this->served(self::model('zai-coding-cn', 'glm-5.3'), self::completionsStream());

        $this->send($model, new OpenAiOptions(apiKey: 'test-key', sessionId: 'conversation-1'));

        $this->assertStringNotContainsString('x-opencode-session', strtolower($this->server()->received()));
    }

    public function testEachProvidersKeyIsReadFromUpstreamsVariable(): void
    {
        foreach ([
            'baseten' => 'BASETEN_API_KEY',
            'together' => 'TOGETHER_API_KEY',
            'fireworks' => 'FIREWORKS_API_KEY',
            'deepseek' => 'DEEPSEEK_API_KEY',
            'ant-ling' => 'ANT_LING_API_KEY',
            'huggingface' => 'HF_TOKEN',
            'nvidia' => 'NVIDIA_API_KEY',
            'vercel-ai-gateway' => 'AI_GATEWAY_API_KEY',
            'minimax' => 'MINIMAX_API_KEY',
            'minimax-cn' => 'MINIMAX_CN_API_KEY',
            'moonshotai' => 'MOONSHOT_API_KEY',
            'moonshotai-cn' => 'MOONSHOT_API_KEY',
            'kimi-coding' => 'KIMI_API_KEY',
            'meta' => 'META_API_KEY',
            'opencode' => 'OPENCODE_API_KEY',
            'opencode-go' => 'OPENCODE_API_KEY',
            'qwen-token-plan' => 'QWEN_TOKEN_PLAN_API_KEY',
            // "reuses the international Token Plan environment variable"
            'qwen-token-plan-individual' => 'QWEN_TOKEN_PLAN_API_KEY',
            'qwen-token-plan-cn' => 'QWEN_TOKEN_PLAN_CN_API_KEY',
            'xiaomi' => 'XIAOMI_API_KEY',
            'xiaomi-token-plan-cn' => 'XIAOMI_TOKEN_PLAN_CN_API_KEY',
            'xiaomi-token-plan-ams' => 'XIAOMI_TOKEN_PLAN_AMS_API_KEY',
            'xiaomi-token-plan-sgp' => 'XIAOMI_TOKEN_PLAN_SGP_API_KEY',
            'zai-coding-cn' => 'ZAI_CODING_CN_API_KEY',
        ] as $provider => $variable) {
            $this->assertSame('key-' . $provider, Stream::envApiKey($provider, [$variable => 'key-' . $provider]), $provider);
        }
    }

    /** One of the catalogue's rows, which a test that names it needs to exist. */
    private static function model(string $provider, string $id): Model
    {
        $model = Models::find($provider, $id);
        self::assertNotNull($model, "{$provider}/{$id} is not in the table");

        return $model;
    }

    /** @return list<Model> */
    private static function of(string $provider): array
    {
        return array_values(array_filter(Models::all(), static fn (Model $model): bool => $model->provider === $provider));
    }

    /**
     * What `Stream::simple()` would send, read in `onPayload` and stopped there, as upstream's tests do.
     *
     * @return array<string, mixed>
     */
    private static function payload(Model $model, ?ReasoningEffort $reasoning, ?string $cacheRetention = null): array
    {
        $captured = new \ArrayObject();
        $options = new SimpleStreamOptions(
            apiKey: 'test-key',
            cacheRetention: $cacheRetention,
            reasoning: $reasoning,
            onPayload: static function (mixed $payload) use ($captured): never {
                $captured['payload'] = $payload;

                throw new RuntimeException('payload captured');
            },
        );

        $message = Async::run(static fn (): AssistantMessage => Stream::simple($model, new Context([new UserMessage('test')]), $options)->result()->await());

        self::assertSame(StopReason::Error, $message->stopReason);
        self::assertIsArray($captured['payload'] ?? null, (string) $message->errorMessage);

        return $captured['payload'];
    }

    /**
     * The row, pointed at a canned server answering `$pieces`.
     *
     * @param list<string> $pieces
     */
    private function served(Model $model, array $pieces): Model
    {
        $this->server = new CannedServer();
        $base = rtrim($this->server->start($pieces), '/');

        return new Model(
            $model->id,
            $model->name,
            $model->api,
            $model->provider,
            $base,
            $model->contextWindow,
            $model->maxTokens,
            $model->reasoning,
            $model->input,
            $model->pricing,
            $model->headers,
            $model->compat,
            $model->thinkingLevelMap,
            $model->inputLimits,
            $model->promptCache,
        );
    }

    /** @param list<Tool> $tools */
    private function send(Model $model, StreamOptions $options, array $tools = []): void
    {
        $message = Async::run(static fn (): AssistantMessage => Stream::start($model, new Context([new UserMessage('hi')], 'sys', $tools), $options)->result()->await());

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
    }

    private function server(): CannedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }

    /** @return list<string> */
    private static function completionsStream(): array
    {
        return [
            "HTTP/1.1 200 OK\r\ncontent-type: text/event-stream\r\n\r\n",
            'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => null]]]) . "\n\n",
            'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]) . "\n\n",
            "data: [DONE]\n\n",
        ];
    }

    /** @return list<string> */
    private static function anthropicStream(): array
    {
        $event = static fn (string $type, array $data): string => "event: {$type}\ndata: " . json_encode(['type' => $type, ...$data]) . "\n\n";

        return [
            "HTTP/1.1 200 OK\r\ncontent-type: text/event-stream\r\n\r\n",
            $event('message_start', ['message' => ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'model' => 'm', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]),
            $event('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            $event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]),
            $event('content_block_stop', ['index' => 0]),
            $event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]),
            $event('message_stop', []),
        ];
    }
}
