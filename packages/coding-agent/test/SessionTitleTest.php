<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\SessionTitle;

final class SessionTitleTest extends TestCase
{
    public function testTheExcerptIsTheFirstQuestionAndTheFirstAnswerInWords(): void
    {
        $messages = [
            new UserMessage('the login page returns 500'),
            self::assistant([new ToolCall('1', 'bash', ['command' => 'ls'])]), // no words yet
            self::assistant([new TextContent('The session table was missing.')]),
            new UserMessage('and the logout page?'),
        ];

        $this->assertSame("User: the login page returns 500\nAssistant: The session table was missing.", SessionTitle::excerpt($messages));
        $this->assertSame('User: hi', SessionTitle::excerpt([new UserMessage('hi')]), 'an aborted first prompt still has a question');
        $this->assertNull(SessionTitle::excerpt([]));
    }

    public function testALongMessageIsCut(): void
    {
        $excerpt = (string) SessionTitle::excerpt([new UserMessage(str_repeat('写', 500))]);

        $this->assertSame('User: ' . str_repeat('写', 300) . '…', $excerpt);
    }

    public function testTheAnswerBecomesATitle(): void
    {
        $this->assertSame('Fix the login bug', SessionTitle::clean("\"Fix the login bug.\"\n"));
        $this->assertSame('修改默认模型为 Gemini', SessionTitle::clean('标题：「修改默认模型为 Gemini」。'));
        $this->assertSame('Fix login', SessionTitle::clean('Title: **Fix login**'));
        $this->assertNull(SessionTitle::clean('?'), 'too short to be one');
        $this->assertNull(SessionTitle::clean(str_repeat('word ', 20)), 'too long: the model talking, not a title');
    }

    /** @param list<mixed> $content */
    private static function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage($content, Api::AnthropicMessages, 'anthropic', 'test-model', new Usage(), StopReason::Stop);
    }
}
