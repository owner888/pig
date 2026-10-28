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
use Pig\CodingAgent\Session\CompactionEndEvent;
use Pig\CodingAgent\Session\CompactionResult;
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
        // A `/skill:deploy ship it` prompt as the session records it — the TUI's
        // `SkillInvocationMessageComponent` draws it collapsed, and so must the page.
        $skill = new UserMessage("<skill name=\"deploy\" location=\"/skills/deploy/SKILL.md\">\n# Deploy\n\nrun the pipeline\n</skill>\n\nship it");
        $messages = [
            new UserMessage('**question**'),
            $skill,
            $compaction, $branch, $hook, $hidden, $assistant,
            new ToolResultMessage('edit1', 'edit', [new TextContent('Edited')], details: ['diff' => "-1 actual-old\n+1 actual-new"]),
            new ToolResultMessage('read1', 'read', [new TextContent('file excerpt')], details: ['notice' => 'Use offset=6 to continue']),
            new ToolResultMessage('write1', 'write', [new TextContent('Wrote 16 bytes')]),
            new BashExecution('exit 3', '', 3, timestamp: 105),
            new BashExecution('seq 2500', 'tail', 0, truncated: true, fullOutputPath: '/tmp/pig-output.txt', timestamp: 106),
            new BashExecution('sleep 5', '', null, cancelled: true, timestamp: 107),
        ];
        $fixture = [
            'messages' => array_map(SessionCodec::encode(...), $messages),
            'events' => [
                RpcEvents::encode(new MessageEndEvent($hook)),
                RpcEvents::encode(new MessageEndEvent($hidden)),
                RpcEvents::encode(new CompactionEndEvent('overflow', new CompactionResult('A **summary**', 'kept0001', 256653, 1200), false, true, summary: $compaction)),
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

    public function testADrawnPartIsCutBetweenCallsWhenThePromptIsFarBack(): void
    {
        // `cutBefore()` in app.js, run as it is: a user message within reach is still where the
        // cut goes; one prompt and then a long run is cut at a call, never before a result.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8');
            const fn = src.match(/function cutBefore\(msgs, end, count\) \{[\s\S]*?\n    \}/)[0];
            const cutBefore = eval('(' + fn + ')');
            const roles = (list) => list.map((role) => ({ role }));
            const run = roles(['user', ...Array.from({ length: 100 }, (_, i) => (i % 2 ? 'toolResult' : 'assistant')), 'assistant']);
            const near = roles(['user', 'assistant', 'user', 'assistant', 'toolResult', 'assistant']);
            const out = { run: cutBefore(run, run.length, 10), near: cutBefore(near, near.length, 3), roleAtRun: null };
            out.roleAtRun = run[out.run].role;
            console.log(JSON.stringify(out));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/app.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $result = json_decode(implode('', $out), true);

        $this->assertSame(2, $result['near'], 'the user message within reach');
        $this->assertSame('assistant', $result['roleAtRun']);
        $this->assertGreaterThan(80, $result['run'], 'about ten from the end, not back at the prompt');
    }
}
