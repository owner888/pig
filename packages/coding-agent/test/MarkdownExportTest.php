<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;

final class MarkdownExportTest extends TestCase
{
    private function assistant(array $content, StopReason $stop = StopReason::Stop): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-6',
            new Usage(),
            $stop,
        );
    }

    public function testRenderFormatsAllMessageTypes(): void
    {
        $messages = [
            new UserMessage('How do I fix this bug?'),
            $this->assistant([
                new ThinkingContent('Inspecting the codebase first...'),
                new ToolCall('call_1', 'read', ['path' => 'src/Foo.php']),
                new TextContent('Here is the solution to your issue.'),
            ]),
            new ToolResultMessage('call_1', 'read', [new TextContent('file content here')]),
        ];

        $md = MarkdownExport::render($messages, '/Users/dev/project');

        $this->assertStringContainsString('# Conversation Export', $md);
        $this->assertStringContainsString('Working Directory: `/Users/dev/project`', $md);
        $this->assertStringContainsString('### 👤 User', $md);
        $this->assertStringContainsString('How do I fix this bug?', $md);
        $this->assertStringContainsString('### 🤖 Assistant', $md);
        $this->assertStringContainsString('Thinking Process', $md);
        $this->assertStringContainsString('Tool Call:** `read`', $md);
        $this->assertStringContainsString('Here is the solution to your issue.', $md);
        $this->assertStringContainsString('Tool Result: read', $md);
    }

    public function testWriteOutputsFileToDisk(): void
    {
        $dir = sys_get_temp_dir() . '/pig-md-export-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $sessionPath = $dir . '/test.jsonl';
        $exportPath = $dir . '/exported.md';

        $store = SessionManager::create($dir, $sessionPath);
        $store->append(new UserMessage('Hello export!'));
        $store->append($this->assistant([new TextContent('Exported answer.')]));

        $written = MarkdownExport::write($store, $exportPath);
        $this->assertSame($exportPath, $written);
        $this->assertFileExists($exportPath);

        $content = file_get_contents($exportPath);
        $this->assertStringContainsString('Hello export!', $content);
        $this->assertStringContainsString('Exported answer.', $content);
    }

    public function testGeneratePrDescriptionWithScriptedProvider(): void
    {
        $streamFn = function () {
            $stream = new \Pig\Ai\Utils\AssistantMessageEventStream();
            $msg = $this->assistant([new TextContent("## 🎯 Summary\nFixed null pointer and added tests.\n\n## 🛠️ Key Changes\n- Guard against null\n\n## 📂 Modified Files\n- `src/Foo.php`\n\n## ✅ Verification\nPassed all tests.")]);
            \Pig\Async\Async::spawn(static function () use ($stream, $msg): void {
                $stream->push(new \Pig\Ai\StartEvent($msg));
                $stream->push(new \Pig\Ai\DoneEvent(StopReason::Stop, $msg));
                $stream->end();
            });
            return $stream;
        };

        $agent = new Agent(new AgentOptions(
            streamFn: $streamFn,
            apiKey: 'test-key',
        ));

        $session = new AgentSession($agent, '/tmp');
        $session->restore([
            new UserMessage('Fix the null pointer bug'),
            $this->assistant([new TextContent('Fixed in src/Foo.php')]),
        ]);

        \Pig\Async\Async::run(static function () use ($session, $streamFn, &$pr): void {
            $pr = MarkdownExport::generatePrDescription($session, $streamFn);
        });

        $this->assertStringContainsString('Summary', $pr);
        $this->assertStringContainsString('Fixed null pointer and added tests.', $pr);
        $this->assertStringContainsString('Key Changes', $pr);
        $this->assertStringContainsString('Modified Files', $pr);
    }
}
