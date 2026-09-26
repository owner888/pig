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
            $this->assertFalse(isset($body['thinking']));
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
        $body = $this->sendCompletions('gpt-5.2', ReasoningEffort::Xhigh);

        $this->assertSame('xhigh', $body['reasoning_effort'] ?? null);
    }

    public function testAnOrdinaryCompletionsModelStillClampsXhigh(): void
    {
        // The other half: nothing else in the registry speaks xhigh, and sending it would be a
        // request the provider rejects.
        $body = $this->sendCompletions('kimi-k2-thinking', ReasoningEffort::Xhigh);

        $this->assertSame('high', $body['reasoning_effort'] ?? null);
    }

    /** @return array<string, mixed> the request body an openai-completions provider sent */
    private function sendCompletions(string $id, ReasoningEffort $reasoning): array
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
        );

        Async::run(function () use ($model, $reasoning): void {
            $options = new SimpleStreamOptions(apiKey: 'k', reasoning: $reasoning);

            foreach (Stream::simple($model, new Context([new UserMessage('hi')]), $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    public function testGeminiCliIsToldHowHardToThinkLikeEveryOtherProvider(): void
    {
        // `translate()` had no arm for `google-gemini-cli` at all, so it fell to the default and
        // the provider was handed plain `StreamOptions`: `--thinking high` on a Gemini CLI model
        // asked for no thinking and got none, silently. Upstream has the arm.
        $body = $this->sendGeminiCli(new SimpleStreamOptions(apiKey: self::geminiCliKey(), reasoning: ReasoningEffort::High));

        $thinking = $body['request']['generationConfig']['thinkingConfig'] ?? null;

        $this->assertIsArray($thinking, 'a turn that asked to think says so on the wire');
        $this->assertTrue($thinking['includeThoughts']);
        $this->assertSame(16_384, $thinking['thinkingBudget'] ?? null);
    }

    public function testGeminiCliAsksForNoThinkingWhenNobodyAskedForAny(): void
    {
        $body = $this->sendGeminiCli(new SimpleStreamOptions(apiKey: self::geminiCliKey()));

        // Code Assist refuses a `thinkingConfig` on a model that cannot think and treats its
        // absence as "off" — which is the one case the missing arm got right by accident.
        $this->assertFalse(isset($body['request']['generationConfig']['thinkingConfig']));
    }

    public function testAGemini3ModelOnCodeAssistGetsALevelRatherThanABudget(): void
    {
        $body = $this->sendGeminiCli(
            new SimpleStreamOptions(apiKey: self::geminiCliKey(), reasoning: ReasoningEffort::Medium),
            'gemini-3-pro-preview',
        );

        $thinking = $body['request']['generationConfig']['thinkingConfig'] ?? null;

        // Gemini 3 ignores a budget and takes a named level, and Pro has only two of them — so
        // `medium` is `HIGH` rather than a level the model would refuse.
        $this->assertSame('HIGH', $thinking['thinkingLevel'] ?? null);
        $this->assertFalse(isset($thinking['thinkingBudget']));
    }

    /** The key `/login` stores for Code Assist: a token and a project in one string. */
    private static function geminiCliKey(): string
    {
        return (string) json_encode(['token' => 'ya29.a', 'projectId' => 'proj-1']);
    }

    /** @return array<string, mixed> the request body the Code Assist provider sent */
    private function sendGeminiCli(SimpleStreamOptions $options, string $id = 'gemini-2.5-pro'): array
    {
        $chunk = 'data: ' . json_encode([
            'response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]],
        ]) . "\n\n";

        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk($chunk),
            "0\r\n\r\n",
        ]);

        $model = new Model(
            $id,
            'Gemini',
            Api::GoogleGeminiCli,
            'google-gemini-cli',
            rtrim($url, '/'),
            1_000_000,
            64_000,
            reasoning: true,
            pricing: new Pricing(),
        );

        Async::run(function () use ($model, $options): void {
            foreach (Stream::simple($model, new Context([new UserMessage('hi')]), $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    public function testMaxTokensDefaultsToTheSmallerOfTheModelAndTheCap(): void
    {
        // The model allows 63k; the unified default caps at 32k.
        $this->assertSame(32_000, $this->sendAndCaptureBody(new SimpleStreamOptions(apiKey: 'k'))['max_tokens']);

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
    private function sendAndCaptureBody(?SimpleStreamOptions $options): array
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

        Async::run(function () use ($url, $options): void {
            foreach (Stream::simple($this->model($url), new Context([new UserMessage('hi')]), $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    private function chunk(string $body): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($body), $body);
    }

    private function model(string $baseUrl = 'http://127.0.0.1:1'): Model
    {
        return new Model(
            'claude-sonnet-4-5',
            'Claude Sonnet 4.5',
            Api::AnthropicMessages,
            'anthropic',
            rtrim($baseUrl, '/'),
            200_000,
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
