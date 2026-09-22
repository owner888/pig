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
