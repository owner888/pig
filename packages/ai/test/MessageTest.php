<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;

final class MessageTest extends TestCase
{
    public function testAUserMessageWrapsABareStringInATextBlock(): void
    {
        $message = new UserMessage('what changed in this file?');

        $this->assertCount(1, $message->content);
        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('what changed in this file?', $message->content[0]->text);
    }

    public function testAUserMessageKeepsAContentListAsGiven(): void
    {
        $blocks = [new TextContent('look at this'), new ImageContent('AAAA', 'image/png')];
        $message = new UserMessage($blocks);

        $this->assertSame($blocks, $message->content);
    }

    public function testMessagesStampThemselvesUnlessToldOtherwise(): void
    {
        $before = (int) (microtime(true) * 1000);
        $stamped = new UserMessage('now');
        $explicit = new UserMessage('then', 1_700_000_000_000);

        $this->assertGreaterThanOrEqual($before, $stamped->timestamp);
        $this->assertSame(1_700_000_000_000, $explicit->timestamp);
    }

    public function testToolCallsPicksOutOnlyToolCallsAndKeepsOrder(): void
    {
        $message = $this->assistant([
            new ThinkingContent('the user wants the file read'),
            new ToolCall('call_1', 'read', ['path' => 'a.php']),
            new TextContent('reading now'),
            new ToolCall('call_2', 'read', ['path' => 'b.php']),
        ]);

        $calls = $message->toolCalls();

        $this->assertCount(2, $calls);
        $this->assertSame('call_1', $calls[0]->id);
        $this->assertSame('call_2', $calls[1]->id);
    }

    public function testToolCallsIsEmptyWhenThereAreNone(): void
    {
        $this->assertSame([], $this->assistant([new TextContent('just talking')])->toolCalls());
    }

    public function testOnlyErrorAndAbortedCountAsFailures(): void
    {
        $this->assertTrue(StopReason::Error->isFailure());
        $this->assertTrue(StopReason::Aborted->isFailure());
        $this->assertFalse(StopReason::Stop->isFailure());
        $this->assertFalse(StopReason::ToolUse->isFailure());
        $this->assertFalse(StopReason::Length->isFailure());
    }

    public function testOnlyXhighClampsDown(): void
    {
        $this->assertSame(ReasoningEffort::High, ReasoningEffort::Xhigh->clampToHigh());
        $this->assertSame(ReasoningEffort::Low, ReasoningEffort::Low->clampToHigh());
        $this->assertSame(ReasoningEffort::High, ReasoningEffort::High->clampToHigh());
    }

    /** @param list<\Pig\Ai\AssistantContent> $content */
    private function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(),
            StopReason::ToolUse,
        );
    }
}
