<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\ProviderError;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;

final class StreamTest extends TestCase
{
    use AssertsThrows;

    private CannedServer $server;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();

        foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_OAUTH_TOKEN', 'OPENAI_API_KEY'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    public function testAnOauthTokenWinsOverAnApiKey(): void
    {
        putenv('ANTHROPIC_API_KEY=plain');
        $this->assertSame('plain', Stream::envApiKey('anthropic'));

        putenv('ANTHROPIC_OAUTH_TOKEN=sk-ant-oat-abc');
        $this->assertSame('sk-ant-oat-abc', Stream::envApiKey('anthropic'));

        $this->restoreEnv();
    }

    public function testAnUnknownProviderHasNoEnvironmentKey(): void
    {
        $this->assertNull(Stream::envApiKey('some-new-provider'));
        $this->restoreEnv();
    }

    public function testAMissingKeyIsAMisconfigurationNotAFailedStream(): void
    {
        // Nothing in the environment and nothing passed in.
        $this->assertThrows(
            ProviderError::class,
            fn () => Async::run(fn () => Stream::simple($this->model(), new Context([new UserMessage('hi')]))),
            'No API key for provider: anthropic',
        );

        $this->restoreEnv();
    }

    #[DataProvider('reasoningLevels')]
    public function testReasoningBecomesAThinkingBudget(?ReasoningEffort $reasoning, bool $enabled, int $budget): void
    {
        $body = $this->sendAndCaptureBody(new SimpleStreamOptions(apiKey: 'k', reasoning: $reasoning));

        if (!$enabled) {
            // No reasoning asked for is thinking off **said**, as upstream's `streamSimple()`
            // passes `thinkingEnabled: false` and its provider sends `{type: "disabled"}`. This
            // used to assert the field was absent — pig left it to the API's default, which an
            // adaptive model reads as "think as you like".
            $this->assertSame(['type' => 'disabled'], $body['thinking']);
            $this->restoreEnv();

            return;
        }

        $this->assertSame('enabled', $body['thinking']['type']);
        $this->assertSame($budget, $body['thinking']['budget_tokens']);
        $this->restoreEnv();
    }

    /** @return array<string, array{0: ?ReasoningEffort, 1: bool, 2: int}> */
    public static function reasoningLevels(): array
    {
        return [
            'none' => [null, false, 0],
            'minimal' => [ReasoningEffort::Minimal, true, 1024],
            'low' => [ReasoningEffort::Low, true, 2048],
            'medium' => [ReasoningEffort::Medium, true, 8192],
            'high' => [ReasoningEffort::High, true, 16384],
            // Anthropic has no xhigh, so it clamps to high rather than being rejected.
            'xhigh clamps' => [ReasoningEffort::Xhigh, true, 16384],
        ];
    }

    public function testXhighSurvivesOnAModelThatHasItWhateverProtocolItSpeaks(): void
    {
        // A `models.json` proxy reselling `gpt-5.2` over chat-completions — the common shape where
        // the direct API is unreachable. xhigh belongs to the model, not to the protocol, and the
        // completions arm used to clamp it away on the grounds that "xhigh is OpenAI's alone".
        // The model says so in its map, as upstream requires; the id alone no longer does
        // (`ModelTest::testWithNoMapXhighIsNotOfferedWhateverTheId`).
        $body = $this->sendCompletions('gpt-5.2', ReasoningEffort::Xhigh, ['xhigh' => 'xhigh']);

        $this->assertSame('xhigh', $body['reasoning_effort'] ?? null);
    }

    public function testAnOrdinaryCompletionsModelStillClampsXhigh(): void
    {
        // The other half: nothing else in the registry speaks xhigh, and sending it would be a
        // request the provider rejects.
        $body = $this->sendCompletions('kimi-k2-thinking', ReasoningEffort::Xhigh);

        $this->assertSame('high', $body['reasoning_effort'] ?? null);
    }

    /**
     * @param array<string, string|null> $levels
     * @return array<string, mixed> the request body an openai-completions provider sent
     */
    private function sendCompletions(string $id, ReasoningEffort $reasoning, array $levels = []): array
    {
        $chunk = 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]) . "\n\n";

        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk($chunk),
            $this->chunk("data: [DONE]\n\n"),
            "0\r\n\r\n",
        ]);

        $model = new Model(
            $id,
            'A resold model',
            Api::OpenAiCompletions,
            'my-box',
            rtrim($url, '/'),
            200_000,
            64_000,
            reasoning: true,
            pricing: new Pricing(),
            thinkingLevelMap: $levels,
        );

        Async::run(function () use ($model, $reasoning): void {
            $options = new SimpleStreamOptions(apiKey: 'k', reasoning: $reasoning);

            foreach (Stream::simple($model, new Context([new UserMessage('hi')]), $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    public function testMaxTokensDefaultsToTheModelsOwnCeiling(): void
    {
        // Upstream's `buildBaseOptions()`: `options?.maxTokens ?? model.maxTokens`, clamped to the
        // context. This used to be capped at 32,000 here, a cap upstream no longer has.
        $this->assertSame(63_000, $this->sendAndCaptureBody(new SimpleStreamOptions(apiKey: 'k'))['max_tokens']);

        $this->restoreEnv();
    }

    public function testTheCeilingIsCutToTheRoomTheConversationLeaves(): void
    {
        // `clampMaxTokensToContext()`: window − the conversation's estimate − 4,096. A 70,000-token
        // window and a 7,000-character message (3.5 characters a token, so 2,000) leave 63,904 —
        // more than the model's 63,000, so that wins; at a 60,000-token window, 53,904 does.
        $message = new UserMessage(str_repeat('x', 7_000));

        $this->assertSame(63_000, $this->sendAndCaptureBody(new SimpleStreamOptions(apiKey: 'k'), $message, 70_000)['max_tokens']);
        $this->server = new CannedServer();
        $this->assertSame(53_904, $this->sendAndCaptureBody(new SimpleStreamOptions(apiKey: 'k'), $message, 60_000)['max_tokens']);

        $this->restoreEnv();
    }

    public function testABudgetThinkingTurnRaisesTheCeilingByItsBudget(): void
    {
        // Upstream's `adjustMaxTokensForThinking()`: the caller's 700 is the answer, and the
        // thinking budget goes on top of it (700 + 8,192), up to the model's ceiling — and the
        // budget is cut to what is left above 1,024 for the answer. pig used to send `max_tokens:
        // 700` with an 8,192-token budget, which Anthropic refuses: the budget must be smaller.
        $body = $this->sendAndCaptureBody(new SimpleStreamOptions(maxTokens: 700, apiKey: 'k', reasoning: ReasoningEffort::Medium));

        $this->assertSame(8_892, $body['max_tokens']);
        $this->assertSame(7_868, $body['thinking']['budget_tokens']);

        $this->restoreEnv();
    }

    public function testToolChoiceMetadataAndTheSessionReachAnthropic(): void
    {
        // Upstream's `streamSimple()` passes `toolChoice` and `buildBaseOptions()` passes
        // `metadata`, `sessionId` and `cacheRetention` through to the provider.
        $body = $this->sendAndCaptureBody(new SimpleStreamOptions(
            apiKey: 'k',
            toolChoice: 'none',
            cacheRetention: 'none',
            metadata: ['user_id' => 'u-1', 'ignored' => true],
        ));

        $this->assertSame(['type' => 'none'], $body['tool_choice']);
        $this->assertSame(['user_id' => 'u-1'], $body['metadata']);
        // `cacheRetention: none` reached the provider: no breakpoint anywhere.
        $this->assertStringNotContainsString('cache_control', $this->server->received());

        $this->restoreEnv();
    }

    public function testTheResponsesApiGetsTheLevelClampedToTheModelsMap(): void
    {
        // Upstream's `clampThinkingLevel()` in the Responses `streamSimple()`: gpt-5.5 has no
        // `minimal` (its map says null), so the nearest level up, `low`, is what goes out.
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk("data: " . json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed']]) . "\n\n"),
            "0\r\n\r\n",
        ]);
        $model = new Model('gpt-5.5', 'GPT-5.5', Api::OpenAiResponses, 'openai', rtrim($url, '/'), 400_000, 128_000, true,
            thinkingLevelMap: ['off' => 'none', 'minimal' => null, 'xhigh' => 'xhigh']);

        Async::run(function () use ($model): void {
            foreach (Stream::simple($model, new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: 'k', reasoning: ReasoningEffort::Minimal)) as $ignored) {
            }
        });

        $this->assertSame('low', $this->server->receivedJson()['reasoning']['effort']);
        $this->restoreEnv();
    }

    public function testAnExplicitMaxTokensWins(): void
    {
        $body = $this->sendAndCaptureBody(new SimpleStreamOptions(maxTokens: 700, apiKey: 'k'));

        $this->assertSame(700, $body['max_tokens']);
        $this->restoreEnv();
    }

    public function testTheKeyIsTakenFromTheEnvironmentWhenNoneIsPassed(): void
    {
        putenv('ANTHROPIC_API_KEY=from-env');
        $this->sendAndCaptureBody(null);

        $this->assertStringContainsString('x-api-key: from-env', $this->server->receivedHead());
        $this->restoreEnv();
    }

    /** @return array<string, mixed> the request body the provider actually sent */
    private function sendAndCaptureBody(?SimpleStreamOptions $options, ?UserMessage $message = null, int $window = 200_000): array
    {
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk("event: message_delta\ndata: " . json_encode([
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => [],
            ]) . "\n\n"),
            "0\r\n\r\n",
        ]);

        Async::run(function () use ($url, $options, $message, $window): void {
            foreach (Stream::simple($this->model($url, $window), new Context([$message ?? new UserMessage('hi')]), $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    private function chunk(string $body): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($body), $body);
    }

    private function model(string $baseUrl = 'http://127.0.0.1:1', int $window = 200_000): Model
    {
        return new Model(
            'claude-sonnet-4-5',
            'Claude Sonnet 4.5',
            Api::AnthropicMessages,
            'anthropic',
            rtrim($baseUrl, '/'),
            $window,
            63_000,
            reasoning: true,
            pricing: new Pricing(input: 3.0, output: 15.0),
        );
    }

    private function restoreEnv(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }
}
