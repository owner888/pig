<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Bedrock;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/**
 * Upstream's `anthropic-error-tool-result-images.test.ts` and its Bedrock half (#10794): an error
 * tool result's images go after all the turn's results, under a caption naming the call, and the
 * result keeps a note in their place — Anthropic and Claude on Bedrock refuse images in an error result.
 */
final class ErrorToolResultImagesTest extends TestCase
{
    public function testAnthropicSendsAnErrorResultsImagesAfterTheTurnsResults(): void
    {
        $server = new CannedServer();
        $url = rtrim($server->start(["HTTP/1.1 400 Bad Request\r\nContent-Length: 2\r\n\r\n{}"]), '/');
        $model = new Model('claude-test', 'C', Api::AnthropicMessages, 'anthropic', $url, 200_000, 8_000, input: ['text', 'image']);

        Async::run(fn () => (new Anthropic())->stream($model, self::transcript(Api::AnthropicMessages, 'anthropic', 'claude-test'), new AnthropicOptions(apiKey: 'k'))->result()->await());

        $turn = $server->receivedJson()['messages'][2]['content'];
        $this->assertSame(['tool_result', 'tool_result', 'text', 'image'], array_column($turn, 'type'));
        $this->assertSame("failed\n(image attached after the tool results)", $turn[0]['content']);
        $this->assertTrue($turn[0]['is_error']);
        // A successful result keeps its image in place.
        $this->assertSame(['text', 'image'], array_column($turn[1]['content'], 'type'));
        $this->assertSame('Images from the error result of tool call call_1 (shot):', $turn[2]['text']);
        $this->assertSame('aGk=', $turn[3]['source']['data']);
    }

    public function testBedrockSplitsThemForClaudeOnly(): void
    {
        $convert = new \ReflectionMethod(Bedrock::class, 'convertMessages');
        $claude = new Model('anthropic.claude-sonnet-4-5', 'C', Api::BedrockConverseStream, 'amazon-bedrock', 'https://bedrock-runtime.us-east-1.amazonaws.com', 200_000, 8_000, input: ['text', 'image']);
        $other = new Model('amazon.nova-pro-v1:0', 'N', Api::BedrockConverseStream, 'amazon-bedrock', 'https://bedrock-runtime.us-east-1.amazonaws.com', 200_000, 8_000, input: ['text', 'image']);

        $turn = $convert->invoke(null, self::transcript(Api::BedrockConverseStream, 'amazon-bedrock', $claude->id), $claude, 'none', null)[2]['content'];
        $this->assertSame([['text' => 'failed'], ['text' => '(image attached after the tool results)']], $turn[0]['toolResult']['content']);
        $this->assertSame('error', $turn[0]['toolResult']['status']);
        $this->assertSame('Images from the error result of tool call call_1 (shot):', $turn[2]['text']);
        $this->assertArrayHasKey('image', $turn[3]);
        $this->assertCount(4, $turn);

        $turn = $convert->invoke(null, self::transcript(Api::BedrockConverseStream, 'amazon-bedrock', $other->id), $other, 'none', null)[2]['content'];
        $this->assertCount(2, $turn);
        $this->assertArrayHasKey('image', $turn[0]['toolResult']['content'][1]);
    }

    private static function transcript(Api $api, string $provider, string $model): TranscriptContext
    {
        $image = new ImageContent('aGk=', 'image/png');

        return new TranscriptContext([
            new UserMessage('go', 1),
            new AssistantMessage([new ToolCall('call_1', 'shot', []), new ToolCall('call_2', 'shot', [])], $api, $provider, $model, new Usage(), StopReason::ToolUse),
            new ToolResultMessage('call_1', 'shot', [new TextContent('failed'), $image], true),
            new ToolResultMessage('call_2', 'shot', [new TextContent('ok'), $image]),
        ]);
    }
}
