<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\MessageEndEvent;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Rpc\RpcEvents;
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionCodec;

/** Run the actual browser renderers with the messages PHP actually sends, not invented JSON. */
final class WebMessageBlocksTest extends TestCase
{
    public function testReplayAndLiveEventsKeepTheSameMessagesAndResultDetails(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser renderers');
        }

        $compaction = new CompactionSummary('A **summary**', tokensBefore: 256653, timestamp: 101);
        $branch = new BranchSummary('A branch handover', fromHook: true, timestamp: 102);
        $hook = new HookMessage('build', [new TextContent("one\ntwo\nthree\nfour\nfive\nsix")], timestamp: 103);
        $hidden = new HookMessage('secret', [new TextContent('do not display')], display: false, timestamp: 104);
        $assistant = new AssistantMessage([
            new ToolCall('edit1', 'edit', ['path' => 'a.php', 'oldText' => 'preview-old', 'newText' => 'preview-new']),
            new ToolCall('read1', 'read', ['path' => 'a.php']),
            new ToolCall('write1', 'write', ['path' => 'b.php', 'content' => 'written contents']),
        ], Api::AnthropicMessages, 'anthropic', 'test', new Usage(), StopReason::ToolUse);
        $messages = [
            new UserMessage('**question**'),
            $compaction, $branch, $hook, $hidden, $assistant,
            new ToolResultMessage('edit1', 'edit', [new TextContent('Edited')], details: ['diff' => "-1 actual-old\n+1 actual-new"]),
            new ToolResultMessage('read1', 'read', [new TextContent('file excerpt')], details: ['notice' => 'Use offset=6 to continue']),
            new ToolResultMessage('write1', 'write', [new TextContent('Wrote 16 bytes')]),
            new BashExecution('exit 3', '', 3, timestamp: 105),
            new BashExecution('seq 2500', 'tail', 0, truncated: true, spillPath: '/tmp/pig-output.txt', timestamp: 106),
            new BashExecution('sleep 5', '', null, cancelled: true, timestamp: 107),
        ];
        $fixture = [
            'messages' => array_map(SessionCodec::encode(...), $messages),
            'events' => [
                RpcEvents::encode(new MessageEndEvent($hook)),
                RpcEvents::encode(new MessageEndEvent($hidden)),
                RpcEvents::encode(new AutoCompactionEndEvent(true, true, $compaction)),
                RpcEvents::encode(new MessageEndEvent($compaction)),
            ],
        ];
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/web-message-blocks.mjs')
            . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js'))
            . ' ' . escapeshellarg(base64_encode(json_encode($fixture, JSON_THROW_ON_ERROR))) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Web message blocks: passed', implode("\n", $out));
    }
}
