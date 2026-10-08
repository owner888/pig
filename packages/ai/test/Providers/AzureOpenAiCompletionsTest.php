<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\StreamOptions;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * The built-in Azure DeepSeek V4 Pro row over Chat Completions — upstream's
 * `test/azure-openai-completions.test.ts`, "Regression for #9645: Azure Foundry rejects DeepSeek's
 * thinking field and every prompt cache parameter here."
 *
 * Upstream drives `azureProvider()` with the catalogue's `getModel("azure", "deepseek-v4-pro")` and
 * reads the payload its mocked SDK was handed; this drives `Stream` with `Models`' row against a
 * canned server and reads the body that arrived. So it checks the row — its compat and its level map
 * — as much as the provider. `max` has no `ReasoningEffort` in pig, so the clamp is checked from
 * `xhigh`, which the row's map also refuses.
 */
final class AzureOpenAiCompletionsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
        $base = rtrim($this->server->start([
            "HTTP/1.1 200 OK\r\ncontent-type: text/event-stream\r\n\r\n",
            'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => null]]]) . "\n\n",
            'data: ' . json_encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]) . "\n\n",
            "data: [DONE]\n\n",
        ]), '/');

        foreach (['PI_CACHE_RETENTION', 'AZURE_OPENAI_DEPLOYMENT_NAME_MAP', 'AZURE_OPENAI_RESOURCE_NAME', 'AZURE_OPENAI_BASE_URL'] as $name) {
            $this->set($name, null);
        }

        $this->set('AZURE_OPENAI_BASE_URL', $base);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();

        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testThinkingIsTurnedOnWithReasoningEffortInsteadOfDeepSeeksThinkingField(): void
    {
        $body = $this->simple(new SimpleStreamOptions(apiKey: 'test-key', reasoning: ReasoningEffort::High));

        $this->assertSame('high', $body['reasoning_effort'] ?? null);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function testALevelTheDeploymentDoesNotAcceptIsClamped(): void
    {
        $body = $this->simple(new SimpleStreamOptions(apiKey: 'test-key', reasoning: ReasoningEffort::Xhigh));

        $this->assertSame('high', $body['reasoning_effort'] ?? null);
    }

    public function testNoLevelSendsNoReasoningEffort(): void
    {
        $body = $this->simple(new SimpleStreamOptions(apiKey: 'test-key'));

        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function testPromptCacheParametersAreOmittedEvenWhenLongRetentionIsAskedFor(): void
    {
        $this->set('PI_CACHE_RETENTION', 'long');
        $fromEnvironment = $this->start(new OpenAiOptions(apiKey: 'test-key', sessionId: 'session-env'));

        $this->assertArrayNotHasKey('prompt_cache_key', $fromEnvironment);
        $this->assertArrayNotHasKey('prompt_cache_retention', $fromEnvironment);
    }

    public function testPromptCacheParametersAreOmittedWhenTheCallerAsksForLongRetention(): void
    {
        $body = $this->start(new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'long', sessionId: 'session-1'));

        $this->assertArrayNotHasKey('prompt_cache_key', $body);
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);
    }

    public function testTheSystemPromptGoesOutUnderTheSystemRole(): void
    {
        // "The deployment discards a `developer` system message once reasoning_effort is set, without
        // billing it, so the system prompt has to go out under the system role."
        $body = $this->start(new OpenAiOptions(apiKey: 'test-key', reasoning: ReasoningEffort::Low));

        $this->assertSame(['role' => 'system', 'content' => 'sys'], $body['messages'][0]);
    }

    public function testMidConversationSystemMessagesStayInPlace(): void
    {
        $body = $this->start(new OpenAiOptions(apiKey: 'test-key'), new Context([
            new UserMessage('hi'),
            new SystemMessage('second'),
            new UserMessage('again'),
        ], 'first'));

        $this->assertSame(['system', 'user', 'system', 'user'], array_column($body['messages'], 'role'));
    }

    public function testReasoningContentIsReplayedOnAssistantTurns(): void
    {
        $assistant = new AssistantMessage(
            [new ThinkingContent('internal reasoning', 'reasoning_content'), new TextContent('answer')],
            Api::OpenAiCompletions,
            'azure',
            'deepseek-v4-pro',
            new Usage(),
            StopReason::Stop,
        );
        $body = $this->start(new OpenAiOptions(apiKey: 'test-key'), new Context([new UserMessage('first'), $assistant, new UserMessage('second')], 'sys'));

        $replayed = array_values(array_filter($body['messages'], static fn (array $m): bool => $m['role'] === 'assistant'));
        $this->assertSame('internal reasoning', $replayed[0]['reasoning_content'] ?? null);
    }

    public function testTheModelIdIsSentAndKeptOnTheMessage(): void
    {
        // "The id is persisted on the assistant message and read back by name, so it has to stay a catalog id."
        [$body, $message] = $this->send(static fn (Model $model, Context $context): mixed => Stream::start($model, $context, new OpenAiOptions(apiKey: 'test-key')));

        $this->assertSame('deepseek-v4-pro', $body['model']);
        $this->assertSame('deepseek-v4-pro', $message->model);
    }

    public function testAMappedDeploymentIsSentAndTheCatalogIdKeptOnTheMessage(): void
    {
        $this->set('AZURE_OPENAI_DEPLOYMENT_NAME_MAP', 'deepseek-v4-pro=my-deepseek');
        [$body, $message] = $this->send(static fn (Model $model, Context $context): mixed => Stream::simple($model, $context, new SimpleStreamOptions(apiKey: 'test-key')));

        $this->assertSame('my-deepseek', $body['model']);
        $this->assertSame('deepseek-v4-pro', $message->model);
    }

    public function testTheDeploymentNameReachesACallersOnPayload(): void
    {
        $this->set('AZURE_OPENAI_DEPLOYMENT_NAME_MAP', 'deepseek-v4-pro=my-deepseek');
        $seen = null;
        $body = $this->start(new OpenAiOptions(apiKey: 'test-key', onPayload: static function (mixed $payload) use (&$seen): array {
            $seen = $payload['model'];

            return [...$payload, 'temperature' => 0.1];
        }));

        $this->assertSame('my-deepseek', $seen);
        $this->assertSame(['my-deepseek', 0.1], [$body['model'], $body['temperature']]);
    }

    public function testAnUnconfiguredEndpointIsTheStreamsErrorRatherThanAThrow(): void
    {
        $this->set('AZURE_OPENAI_BASE_URL', null);
        $message = Async::run(static function (): AssistantMessage {
            $model = Models::find(Models::AZURE, 'deepseek-v4-pro');
            self::assertNotNull($model);

            return Stream::start($model, new Context([new UserMessage('hi')], 'sys'), new OpenAiOptions(apiKey: 'test-key'))->result()->await();
        });

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('Azure OpenAI base URL is required', (string) $message->errorMessage);
        $this->assertSame(0, $this->server->connections());
    }

    /** @return array<string, mixed> */
    private function simple(SimpleStreamOptions $options): array
    {
        return $this->send(static fn (Model $model, Context $context): mixed => Stream::simple($model, $context, $options))[0];
    }

    /** @return array<string, mixed> */
    private function start(StreamOptions $options, ?Context $context = null): array
    {
        return $this->send(static fn (Model $model, Context $default): mixed => Stream::start($model, $context ?? $default, $options))[0];
    }

    /**
     * @param \Closure(Model, Context): \Pig\Ai\Utils\AssistantMessageEventStream $send
     * @return array{0: array<string, mixed>, 1: AssistantMessage}
     */
    private function send(\Closure $send): array
    {
        $model = Models::find(Models::AZURE, 'deepseek-v4-pro');
        $this->assertNotNull($model);

        $message = Async::run(static fn (): AssistantMessage => $send($model, new Context([new UserMessage('hi')], 'sys'))->result()->await());

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertStringStartsWith('POST /chat/completions ', $this->server->received());

        return [$this->server->receivedJson(), $message];
    }

    private function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }
}
