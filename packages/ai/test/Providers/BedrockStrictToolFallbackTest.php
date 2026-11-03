<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\BedrockCompat;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Bedrock;
use Pig\Ai\Providers\BedrockOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Aws\EventStream;
use Pig\Ai\Utils\Transcript;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Upstream's `bedrock-strict-tool-fallback.test.ts` (#11416): a request Bedrock rejects for Claude's
 * strict tool limits is sent once more with the `strict: "prefer"` tools non-strict, as the Anthropic
 * provider already does; `require` tools stay strict.
 */
final class BedrockStrictToolFallbackTest extends TestCase
{
    /** Observed from live Bedrock with 20 optional parameters across strict tools. */
    private const string UNION_LIMIT_MESSAGE = 'The model returned the following errors: Schemas contains too many parameters with union types (20 parameters with type arrays or anyOf). This causes exponential compilation cost. Reduce the number of nullable or union-typed parameters (limit: 16 parameters with unions).';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testAStrictToolLimitRejectionIsRetriedWithPreferredToolsNonStrictAndRequiredOnesStrict(): void
    {
        $payloads = 0;
        [$message, $bodies] = $this->converse(
            [self::rejection(self::UNION_LIMIT_MESSAGE), self::success()],
            [self::tool('preferred', 'prefer'), self::tool('required', 'require'), self::tool('ordinary')],
            static function (array $payload) use (&$payloads): array {
                $payloads++;

                return [...$payload, 'requestMetadata' => ['team' => 'a']];
            },
        );

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame(2, $payloads);
        $this->assertCount(2, $bodies);
        [$first, $second] = $bodies;

        $this->assertTrue($first['toolConfig']['tools'][0]['toolSpec']['strict']);
        $this->assertSame(['value', 'optional'], $first['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json']['required']);
        $this->assertArrayNotHasKey('strict', $second['toolConfig']['tools'][0]['toolSpec']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'optional' => ['type' => 'number']], 'required' => ['value']],
            $second['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json'],
        );
        $this->assertSame($first['toolConfig']['tools'][1], $second['toolConfig']['tools'][1]);
        $this->assertTrue($second['toolConfig']['tools'][1]['toolSpec']['strict']);
        $this->assertSame($first['toolConfig']['tools'][2], $second['toolConfig']['tools'][2]);
        $this->assertSame(['team' => 'a'], $second['requestMetadata']);

        $this->assertCount(1, $message->diagnostics);
        $diagnostic = $message->diagnostics[0];
        $this->assertSame('bedrock_strict_tools_fallback', $diagnostic->type);
        $this->assertSame(self::UNION_LIMIT_MESSAGE, $diagnostic->error?->message);
        $this->assertSame(['reason' => 'strict_tool_limits_exceeded'], $diagnostic->details);
    }

    public function testWithoutAPreferredStrictToolThereIsNoRetry(): void
    {
        [$message, $bodies] = $this->converse([self::rejection(self::UNION_LIMIT_MESSAGE)], [self::tool('required', 'require'), self::tool('ordinary')]);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Validation error: ' . self::UNION_LIMIT_MESSAGE, $message->errorMessage);
        $this->assertCount(1, $bodies);
    }

    public function testAnotherValidationErrorIsNotRetried(): void
    {
        [$message, $bodies] = $this->converse([self::rejection('messages: field required')], [self::tool('preferred', 'prefer')]);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertCount(1, $bodies);
    }

    public function testItIsRetriedOnlyOnce(): void
    {
        [$message, $bodies] = $this->converse(
            [self::rejection(self::UNION_LIMIT_MESSAGE), self::rejection(self::UNION_LIMIT_MESSAGE)],
            [self::tool('preferred', 'prefer')],
        );

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertCount(2, $bodies);
    }

    /**
     * @param list<list<string>> $replies
     * @param list<Tool> $tools
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $onPayload
     * @return array{0: AssistantMessage, 1: list<array<string, mixed>>}
     */
    private function converse(array $replies, array $tools, ?\Closure $onPayload = null): array
    {
        $server = new CannedServer();
        $url = rtrim($server->startSequence($replies), '/');
        $model = new Model(
            'us.anthropic.claude-opus-5-5',
            'Claude Opus 5.5 (US)',
            Api::BedrockConverseStream,
            'amazon-bedrock',
            $url,
            200_000,
            100,
            false,
            ['text'],
            new Pricing(),
            compat: new BedrockCompat(true),
        );
        $context = new Context([new UserMessage('Hello', 1)], null, $tools);
        $message = null;

        Async::run(function () use ($model, $context, $onPayload, &$message): void {
            $stream = (new Bedrock(sleep: static function (int $ms): void {
            }))->stream($model, Transcript::normalizeContext($context), new BedrockOptions(
                region: 'us-east-1',
                cacheRetention: 'none',
                env: ['AWS_BEDROCK_SKIP_AUTH' => '1'],
                onPayload: $onPayload,
            ));

            foreach ($stream as $event) {
            }

            $message = $stream->result()->await();
        });

        $bodies = [];

        foreach (preg_split('/(?=POST \/)/', $server->received(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $request) {
            $bodies[] = json_decode(explode("\r\n\r\n", $request, 2)[1], true, flags: JSON_THROW_ON_ERROR);
        }

        $server->stop();
        $this->assertInstanceOf(AssistantMessage::class, $message);

        return [$message, $bodies];
    }

    private static function tool(string $name, ?string $strict = null): Tool
    {
        return new Tool(
            $name,
            'Look up a value',
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'optional' => ['type' => 'number']], 'required' => ['value']],
            constrainedSampling: $strict === null ? null : ['type' => 'json_schema', 'strict' => $strict],
        );
    }

    /** @return list<string> */
    private static function rejection(string $message): array
    {
        $body = (string) json_encode(['message' => $message]);

        return ["HTTP/1.1 400 Bad Request\r\ncontent-type: application/json\r\nx-amzn-errortype: ValidationException:http://internal.amazon.com/coral/com.amazon.bedrock/\r\nx-amzn-requestid: req-1\r\ncontent-length: " . strlen($body) . "\r\n\r\n{$body}"];
    }

    /** @return list<string> */
    private static function success(): array
    {
        $frames = [
            EventStream::encode([':event-type' => 'messageStart', ':content-type' => 'application/json', ':message-type' => 'event'], '{"role":"assistant"}'),
            EventStream::encode([':event-type' => 'messageStop', ':content-type' => 'application/json', ':message-type' => 'event'], '{"stopReason":"end_turn"}'),
        ];

        return ["HTTP/1.1 200 OK\r\ncontent-type: application/vnd.amazon.eventstream\r\ncontent-length: " . strlen(implode('', $frames)) . "\r\n\r\n", ...$frames];
    }
}
