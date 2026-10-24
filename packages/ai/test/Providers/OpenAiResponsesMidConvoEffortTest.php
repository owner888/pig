<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Transcript;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Upstream's `openai-responses-mid-convo-effort.test.ts` (#10853): GPT-6 on the openai provider
 * keeps `reasoning.effort` fixed and sends the thinking level as `configuration_update` input
 * items, so a level change keeps the prompt cache.
 */
final class OpenAiResponsesMidConvoEffortTest extends TestCase
{
    private const array LEVELS = [
        'off' => 'none',
        'minimal' => null,
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'xhigh' => 'xhigh',
        'max' => 'max',
    ];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testKeepsReasoningEffortConstantAndOnlyAppendsInputWhenTheLevelChanges(): void
    {
        $history = [new UserMessage('one')];
        [$first, $firstMessage] = $this->request($history, ReasoningEffort::High);
        $history = [...$history, $firstMessage, new UserMessage('two')];
        [$second, $secondMessage] = $this->request($history, ReasoningEffort::High);
        $history = [...$history, $secondMessage, new UserMessage('three')];
        [$third, $thirdMessage] = $this->request($history, ReasoningEffort::Low);

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'one']]],
            self::update('high'),
        ], $first['input']);

        foreach ([$first, $second, $third] as $payload) {
            $this->assertSame('medium', $payload['reasoning']['effort']);
        }

        $this->assertSame($first['input'], array_slice($second['input'], 0, count($first['input'])));
        $this->assertSame($second['input'], array_slice($third['input'], 0, count($second['input'])));
        $this->assertSame([self::update('high'), self::update('high'), self::update('low')], self::updates($third['input']));
        $this->assertSame(self::update('low'), $third['input'][array_key_last($third['input'])]);
        $this->assertSame(['high', 'high', 'low'], [
            $firstMessage->providerThinkingLevel,
            $secondMessage->providerThinkingLevel,
            $thirdMessage->providerThinkingLevel,
        ]);
    }

    public function testALevelChangeMadeDuringAToolLoopAppliesToTheNextReply(): void
    {
        $history = [new UserMessage('one')];
        [$toolCall, $toolCallMessage] = $this->request($history, ReasoningEffort::High, withToolCall: true);
        $history = [...$history, $toolCallMessage, new ToolResultMessage('call_1|fc_1', 'lookup', [new TextContent('result')])];
        [$afterTool, $afterToolMessage] = $this->request($history, ReasoningEffort::Low);

        $this->assertSame($toolCall['input'], array_slice($afterTool['input'], 0, count($toolCall['input'])));
        $tail = array_slice($afterTool['input'], -2);
        $this->assertSame('function_call_output', $tail[0]['type']);
        $this->assertSame('call_1', $tail[0]['call_id']);
        $this->assertSame(self::update('low'), $tail[1]);
        $this->assertSame('low', $afterToolMessage->providerThinkingLevel);
    }

    public function testAddsItemsOnlyBeforeRepliesRecordedByThisModel(): void
    {
        [, $unstamped] = $this->request([new UserMessage('one')], ReasoningEffort::High, compat: new OpenAiCompat());
        $this->assertNull($unstamped->providerThinkingLevel);

        $copy = static fn (string $provider, string $model, ?string $level): AssistantMessage => new AssistantMessage(
            $unstamped->content,
            $unstamped->api,
            $provider,
            $model,
            $unstamped->usage,
            $unstamped->stopReason,
            providerThinkingLevel: $level,
        );
        $otherProvider = $copy('opencode', $unstamped->model, 'high');
        $otherModel = $copy($unstamped->provider, 'gpt-6-sol', 'high');
        $recorded = $copy($unstamped->provider, $unstamped->model, 'high');

        [$payload] = $this->request([
            new UserMessage('one'),
            $unstamped,
            new UserMessage('two'),
            $otherProvider,
            new UserMessage('three'),
            $otherModel,
            new UserMessage('four'),
            $recorded,
            new UserMessage('five'),
        ], ReasoningEffort::Low);

        $this->assertSame([
            'user',
            'message',
            'user',
            'message',
            'user',
            'message',
            'user',
            'configuration_update',
            'message',
            'user',
            'configuration_update',
        ], array_map(static fn (array $item): string => $item['type'] ?? $item['role'], $payload['input']));
        $this->assertSame([self::update('high'), self::update('low')], self::updates($payload['input']));
    }

    /** @return iterable<string, array{string, ?OpenAiCompat, ?ReasoningEffort, string}> */
    public static function plainEffortCases(): iterable
    {
        yield 'Sign in with ChatGPT' => ['chatgpt-access-token', new OpenAiCompat(supportsMidConvoEffort: true), ReasoningEffort::Low, 'low'];
        yield 'the compat flag turned off' => ['sk-test', new OpenAiCompat(supportsMidConvoEffort: false), ReasoningEffort::Low, 'low'];
        yield 'thinking off' => ['sk-test', new OpenAiCompat(supportsMidConvoEffort: true), null, 'none'];
    }

    #[DataProvider('plainEffortCases')]
    public function testSendsTheLevelAsReasoningEffort(string $apiKey, OpenAiCompat $compat, ?ReasoningEffort $level, string $effort): void
    {
        [, $first] = $this->request([new UserMessage('one')], ReasoningEffort::High, $apiKey, $compat);
        [$payload, $message] = $this->request([new UserMessage('one'), $first, new UserMessage('two')], $level, $apiKey, $compat);

        $this->assertSame($effort, $payload['reasoning']['effort']);
        $this->assertSame([], self::updates($payload['input']));
        $this->assertNull($message->providerThinkingLevel);
    }

    /** @return array{type: string, reasoning: array{effort: string}} */
    private static function update(string $effort): array
    {
        return ['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]];
    }

    /**
     * @param list<array<string, mixed>> $input
     * @return list<array<string, mixed>>
     */
    private static function updates(array $input): array
    {
        return array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'configuration_update'));
    }

    /**
     * One request; the body that went out and the reply.
     *
     * @param list<mixed> $messages
     * @return array{0: array<string, mixed>, 1: AssistantMessage}
     */
    private function request(
        array $messages,
        ?ReasoningEffort $reasoning,
        string $apiKey = 'sk-test',
        ?OpenAiCompat $compat = null,
        bool $withToolCall = false,
    ): array {
        $server = new CannedServer();
        $item = $withToolCall
            ? ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'lookup', 'arguments' => '{}', 'status' => 'completed']
            : [
                'type' => 'message',
                'id' => 'msg_resp_1',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'answer', 'annotations' => []]],
            ];
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ([
            ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => $item],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $item],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed']],
        ] as $event) {
            $body = 'data: ' . json_encode($event) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";
        $compat ??= new OpenAiCompat(supportsMidConvoEffort: true);
        // Sign in with ChatGPT is OpenAI's own base URL with a key that is not `sk-`, which no canned
        // server can stand behind: that request is read from `onPayload` and stopped there, and the
        // reply it records is the failed one — upstream's output carries its level from the start.
        $signIn = !str_starts_with($apiKey, 'sk-');
        $model = $this->model($signIn ? 'https://api.openai.com/v1' : rtrim($server->start($pieces), '/'), $compat);
        $sent = null;
        $onPayload = $signIn ? static function (array $params) use (&$sent): never {
            $sent = $params;

            throw new \RuntimeException('stopped before sending');
        } : null;

        $message = Async::run(function () use ($model, $messages, $reasoning, $apiKey, $onPayload): AssistantMessage {
            $stream = (new OpenAiResponses())->stream(
                $model,
                Transcript::normalizeContext(new Context($messages)),
                new OpenAiOptions(apiKey: $apiKey, reasoning: $reasoning, onPayload: $onPayload),
            );

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });

        if ($signIn) {
            $this->assertIsArray($sent);

            return [$sent, $message];
        }

        $this->assertNotSame(StopReason::Error, $message->stopReason, (string) $message->errorMessage);

        return [$server->receivedJson(), $message];
    }

    private function model(string $baseUrl, OpenAiCompat $compat): Model
    {
        return new Model(
            'gpt-6-luna',
            'GPT-6 Luna',
            Api::OpenAiResponses,
            'openai',
            $baseUrl,
            272_000,
            128_000,
            true,
            ['text'],
            new Pricing(),
            compat: $compat,
            thinkingLevelMap: self::LEVELS,
        );
    }
}
