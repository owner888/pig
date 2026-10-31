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

    public function testAfterASleepThePageOpensANewConnectionAndBindsItsTabsAgain(): void
    {
        // `network.js` run as it is, with a browser made of stubs: its socket still says OPEN, as
        // one can after the lid was closed, and the clock jumps a minute between two of the page's
        // looks at it. The page must not believe the old socket: a new one, every tab bound again.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            let src = fs.readFileSync(process.argv[1], 'utf8')
                .replace(/^import .*$/m, 'const t = (k) => k;')
                .replace(/^export /gm, '');
            let now = 1_000_000;
            const intervals = [];
            const sockets = [];
            const listeners = {};
            const sandbox = {
                Date: { now: () => now },
                setInterval: (fn) => intervals.push(fn),
                setTimeout: () => 0, clearTimeout: () => {},
                sessionStorage: { getItem: () => 'id', setItem: () => {} },
                fetch: () => Promise.reject(new Error('offline')),
                location: { protocol: 'http:', host: 'localhost' },
                window: { addEventListener: () => {} },
                document: { visibilityState: 'visible', addEventListener: (type, fn) => { listeners[type] = fn; } },
                WebSocket: class { static OPEN = 1; static CONNECTING = 0; constructor() { this.readyState = 1; this.closed = false; sockets.push(this); } close() { this.closed = true; } send() {} },
                console,
            };
            const make = new Function(...Object.keys(sandbox), src + '\nreturn network;');
            const network = make(...Object.values(sandbox));
            let binds = 0, lost = 0;
            const tab = { subscribe: () => binds++, onConnectionLost: () => lost++ };
            network.init({ getTabs: () => [tab] });
            network.connect();
            sockets[0].onopen();
            intervals.forEach((fn) => fn());          // an ordinary five seconds: nothing happens
            const quiet = sockets.length;
            now += 60_000;                             // the lid was closed for a minute
            intervals.forEach((fn) => fn());
            sockets[sockets.length - 1].onopen?.();
            console.log(JSON.stringify({ quiet, sockets: sockets.length, oldClosed: sockets[0].closed, binds, lost }));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/network.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $result = json_decode(implode('', $out), true);

        $this->assertSame(1, $result['quiet'], 'no new connection without a sleep');
        $this->assertSame(2, $result['sockets'], 'a new connection after it');
        $this->assertTrue($result['oldClosed']);
        $this->assertSame(1, $result['lost'], 'what waited on the old one is told it is gone');
        $this->assertSame(2, $result['binds'], 'the tab bound again, which reloads its conversation and state');
    }

    public function testAPageAwayKeepsOnlyWhatTheNextDrawNeedsAndALongLiveRunDrawsItsLastPageAgain(): void
    {
        // `catchup.js` run as it is: hidden for a moment draws as ever, hidden past `AWAY_AFTER`
        // does not; what is kept of a skipped answer is its text, the whole of it; and a tab in view
        // draws afresh after `LIVE_CAP` live messages — not while the reader is scrolled up.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8').replace(/^export /gm, '');
            const m = new Function(src + '\nreturn { AWAY_AFTER, LIVE_CAP, DRAWS_MESSAGES, away, catchUp, overCap };')();
            const tab = { currentAssistantText: 'old', currentThinkingText: '' };
            const delta = (type, text) => ({ type: 'message_update', assistantMessageEvent: { type, delta: text } });
            for (const evt of [{ type: 'message_start' }, delta('thinking_delta', 'hmm'), delta('text_delta', 'Hello, '), delta('text_delta', 'world')]) m.catchUp(tab, evt);
            const kept = { text: tab.currentAssistantText, thinking: tab.currentThinkingText };
            m.catchUp(tab, { type: 'tool_execution_start' });
            console.log(JSON.stringify({
                shown: m.away(null, 1e9),
                brief: m.away(1000, 1000 + 5000),
                long: m.away(1000, 1000 + m.AWAY_AFTER + 1),
                drawsMessages: m.DRAWS_MESSAGES.has('message_update'),
                drawsState: m.DRAWS_MESSAGES.has('agent_start') || m.DRAWS_MESSAGES.has('extension_ui_request') || m.DRAWS_MESSAGES.has('queue_update'),
                kept, afterTool: tab.currentAssistantText,
                under: m.overCap({ liveMessages: m.LIVE_CAP - 1 }),
                over: m.overCap({ liveMessages: m.LIVE_CAP }),
                reading: m.overCap({ liveMessages: m.LIVE_CAP, userScrolledUp: true }),
            }));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/catchup.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode('', $out), true);

        $this->assertFalse($r['shown']);
        $this->assertFalse($r['brief'], 'a few seconds elsewhere changes nothing');
        $this->assertTrue($r['long']);
        $this->assertTrue($r['drawsMessages']);
        $this->assertFalse($r['drawsState'], 'running state, dialogs and the queue are handled while away');
        $this->assertSame(['text' => 'Hello, world', 'thinking' => 'hmm'], $r['kept']);
        $this->assertSame('', $r['afterTool']);
        $this->assertFalse($r['under']);
        $this->assertTrue($r['over']);
        $this->assertFalse($r['reading'], 'not while the reader is scrolled up');
    }

    public function testAMessageReadJustAfterASleepDropsTheBacklogForANewConnection(): void
    {
        // `network.js` as it is: the page's timers did not run (asleep), the clock moved on, and
        // the first thing to arrive is a message from the backlog. It is not handed to a tab; the
        // connection is replaced, and the next message on the new one is.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            let src = fs.readFileSync(process.argv[1], 'utf8')
                .replace(/^import .*$/m, 'const t = (k) => k;')
                .replace(/^export /gm, '');
            let now = 1_000_000;
            const sockets = [];
            const sandbox = {
                Date: { now: () => now },
                setInterval: () => 0, setTimeout: () => 0, clearTimeout: () => {},
                sessionStorage: { getItem: () => 'id', setItem: () => {} },
                fetch: () => Promise.reject(new Error('offline')),
                location: { protocol: 'http:', host: 'localhost' },
                window: { addEventListener: () => {} },
                document: { visibilityState: 'visible', addEventListener: () => {} },
                WebSocket: class { static OPEN = 1; static CONNECTING = 0; constructor() { this.readyState = 1; this.closed = false; sockets.push(this); } close() { this.closed = true; } send() {} },
                console,
            };
            const network = new Function(...Object.keys(sandbox), src + '\nreturn network;')(...Object.values(sandbox));
            const got = [];
            const tab = { id: 'tab-1', subscribe: () => {}, onConnectionLost: () => {}, onServerMessage: (m) => got.push(m.n) };
            network.init({ getTabs: () => [tab], getActiveTab: () => tab });
            network.connect();
            sockets[0].onmessage({ data: JSON.stringify({ tabId: 'tab-1', n: 1 }) });
            now += 90_000;                            // asleep: no tick of the page's clock
            sockets[0].onmessage({ data: JSON.stringify({ tabId: 'tab-1', n: 2 }) });
            sockets[1].onmessage({ data: JSON.stringify({ tabId: 'tab-1', n: 3 }) });
            console.log(JSON.stringify({ got, sockets: sockets.length, oldClosed: sockets[0].closed }));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/network.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode('', $out), true);

        $this->assertSame([1, 3], $r['got'], 'the backlog message is dropped, the new connection\'s is not');
        $this->assertSame(2, $r['sockets']);
        $this->assertTrue($r['oldClosed']);
    }
}
