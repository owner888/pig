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

    public function testTheConnectionBannerSaysHowMuchToWorry(): void
    {
        // The first connection of a page is routine; a connection lost is a warning; one that
        // keeps failing is an error. Same text and colour for all three hid the one that mattered.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            let src = fs.readFileSync(process.argv[1], 'utf8')
                .replace(/^import .*$/m, 'const t = (k) => k;')
                .replace(/^export /gm, '');
            const sockets = [];
            const timers = [];
            const sandbox = {
                Date: { now: () => 0 },
                setInterval: () => 0,
                setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout: () => {},
                sessionStorage: { getItem: () => 'id', setItem: () => {} },
                fetch: () => Promise.reject(new Error('offline')),
                location: { protocol: 'http:', host: 'localhost' },
                window: { addEventListener: () => {} },
                document: { visibilityState: 'visible', addEventListener: () => {} },
                WebSocket: class { static OPEN = 1; constructor() { this.readyState = 0; sockets.push(this); } close() {} send() {} },
                console,
            };
            const network = new Function(...Object.keys(sandbox), src + '\nreturn network;')(...Object.values(sandbox));
            const seen = [];
            network.init({ getTabs: () => [], showStatus: (text, level) => seen.push(text + ':' + level), hideStatus: () => seen.push('hidden') });
            const failOnce = () => { sockets[sockets.length - 1].onclose(); timers.shift()(); };
            network.connect();                    // the page opens
            sockets[0].onopen();                  // and is connected
            failOnce(); failOnce(); failOnce();   // then the connection drops and keeps failing
            console.log(JSON.stringify(seen));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/network.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $seen = json_decode(implode('', $out), true);

        $this->assertSame('connecting:info', $seen[0], 'the first connection is routine');
        $this->assertSame('hidden', $seen[1]);
        $this->assertSame('disconnected:warning', $seen[2], 'a connection lost is a warning');
        $this->assertSame('disconnected:error', end($seen), 'and an error when it keeps failing');
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

    public function testADiffInCommandOutputIsColouredAndOtherOutputIsNot(): void
    {
        // `difftext.js` as it is. git colours only on a terminal and pig runs commands without
        // one, so a bash card's `git diff` is coloured when drawn — by structure, with hunks read by
        // their counts, so a listing or a list that merely starts lines with `-` stays plain.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8').replace(/^export /gm, '');
            const m = new Function(src + '\nreturn { classifyDiffLines, highlightDiffs };')();
            const kinds = (t) => m.classifyDiffLines(t.split('\n')).map((x) => x ?? '.').join(' ');
            const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            console.log(JSON.stringify({
                git: kinds("$ git diff\ndiff --git a/r.php b/r.php\nindex 1a2b3c4..5d6e7f8 100644\n--- a/r.php\n+++ b/r.php\n@@ -1,4 +1,3 @@\n keep\n-old one\n-old two\n+new\n keep2\ndone"),
                twoFiles: kinds("diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\ndiff --git a/y b/y\n--- a/y\n+++ b/y\n@@ -2,0 +3 @@\n+c"),
                plain: kinds("--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n\\ No newline at end of file\nafter"),
                ls: kinds("total 8\n-rw-r--r-- 1 a b 0 x\n-rw-r--r-- 1 a b 0 y"),
                list: kinds("--- a\n+++ b\n- item\n+ other\n- third"),
                npm: kinds("app@1.0.0\n├── a@1.0.0\n└─┬ b@2.0.0\n  └── c@3.0.0"),
                miscount: kinds("--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n-a\nplain text"),
                cut: kinds("--- a/x\n+++ b/x\n@@ -1,5 +1,5 @@\n ctx\n-cut"),
                html: m.highlightDiffs("a<b\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-<i>\n+&", esc),
                untouched: m.highlightDiffs("- one\n+ two <x>", esc),
            }));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/difftext.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode('', $out), true);

        $this->assertSame('. meta meta meta meta hunk ctx del del add ctx .', $r['git']);
        $this->assertSame('meta meta meta hunk del add meta meta meta hunk add', $r['twoFiles']);
        $this->assertSame('meta meta hunk del add meta .', $r['plain']);
        $this->assertSame('. . .', $r['ls']);
        $this->assertSame('. . . . .', $r['list'], 'no hunk header, no diff');
        $this->assertSame('. . . .', $r['npm']);
        $this->assertSame('. . . . .', $r['miscount'], 'lines that do not add up were never a hunk');
        $this->assertSame('meta meta hunk ctx del', $r['cut'], 'output cut short colours what there is');
        $this->assertSame(
            "a&lt;b\n<span class=\"tdiff tdiff-meta\">--- a/x</span>\n<span class=\"tdiff tdiff-meta\">+++ b/x</span>\n"
            . "<span class=\"tdiff tdiff-hunk\">@@ -1 +1 @@</span>\n<span class=\"tdiff tdiff-del\">-&lt;i&gt;</span>\n<span class=\"tdiff tdiff-add\">+&amp;</span>",
            $r['html'],
        );
        $this->assertSame("- one\n+ two &lt;x&gt;", $r['untouched']);
    }

    public function testUnreachableRetriesDoNotMultiplyAndClosingATabDetachesIt(): void
    {
        // Three failing loaders (`refreshState`, `loadCommands`, `loadMessages`) used to schedule
        // a 3s timer each without deduplication, and each timer ran all three again — 3^N timers on
        // a dropped socket — while a second `Tab.close()` shadowed the one that sent `detach_session`.
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8');
            const tabMatch = src.match(/class Tab \{[\s\S]*?\n    \}/);
            const unreachMatch = src.match(/let unreachable = null;[\s\S]*?function pigReachable\(\) \{[\s\S]*?\n    \}/);
            if (!tabMatch || !unreachMatch) throw new Error('Could not extract Tab or pigUnreachable');

            const timers = new Map();
            let nextTimer = 1;
            const sent = [];
            let active = null;
            let renderingTab = null;
            const sandbox = {
                Node: { ELEMENT_NODE: 1 },
                ResizeObserver: class { observe() {} unobserve() {} disconnect() {} },
                MutationObserver: class { observe() {} disconnect() {} },
                document: {
                    createElement: () => ({ className: '', style: {}, hidden: false, addEventListener: () => {}, remove: () => {}, querySelector: () => null }),
                },
                chatArea: { appendChild: () => {} },
                scrollBottomBtn: { style: {} },
                network: { send: (m) => sent.push(m) },
                clientId: 'client-1',
                pageId: '0'.repeat(32),
                currentTheme: 'dark',
                FIRST_LOAD: 180,
                createHookState: () => ({}),
                DRAWS_MESSAGES: new Set(),
                away: () => false,
                catchUp: () => {},
                overCap: () => false,
                slashCommandsFrom: () => [],
                extraCommands: () => [],
                slashAutocomplete: null,
                imageRefs: (tab) => (tab.sessionFile && tab.cwd ? { images: 'refs' } : {}),
                withImageUrls: (_, m) => m,
                renderMessages: () => {},
                nearTop: () => false,
                fetchOlder: () => {},
                drawStreamingText: () => {},
                rememberPrompt: () => {},
                textOf: () => '',
                drawNotify: () => {},
                prependIncognitoNotice: () => {},
                setRunningState: () => {},
                appendErrorMessage: () => {},
                appendNoticeMessage: () => {},
                onHookUiRequest: () => {},
                onEvent: () => {},
                agentWorkingChanged: () => {},
                saveTabs: () => {},
                renderTabs: () => {},
                applyActiveTabToChrome: () => {},
                drawCwdChip: () => {},
                hideStatusBanner: () => {},
                showStatusBanner: () => {},
                availableModels: ['m'],
                tabs: [],
                loadModels: () => {},
                refreshState: () => active?.refreshState(),
                t: (k) => k,
                window: {},
                setTimeout: (fn) => { const id = nextTimer++; timers.set(id, fn); return id; },
                clearTimeout: (id) => { timers.delete(id); },
            };
            const run = new Function(
                ...Object.keys(sandbox),
                `let tabSeq = 0;\n${tabMatch[0]}\n${unreachMatch[0]}\nreturn { Tab, pigUnreachable, pigReachable, setActive: (t) => { active = t; } };`,
            );
            const { Tab, pigUnreachable, pigReachable, setActive } = run(...Object.values(sandbox));

            const tab = new Tab('/repo', null);
            setActive(tab);
            // 1. Multiple failures arm only one retry timer, and pigReachable cancels it.
            pigUnreachable('state', new Error('timeout'));
            pigUnreachable('commands', new Error('timeout'));
            pigUnreachable('messages', new Error('timeout'));
            const afterTripleFailure = timers.size;
            pigReachable();
            const afterReachable = timers.size;

            // 2. A dropped connection rejecting in-flight RPCs does not arm pigUnreachable at all.
            tab.onServerMessage({ type: 'session_bound', cwd: '/repo' });
            tab.onConnectionLost();
            const afterDisconnect = timers.size;

            // 3. Concurrent loadMessages() / refreshState() calls coalesce into one in-flight request.
            const tab2 = new Tab('/repo', 's.jsonl');
            setActive(tab2);
            tab2.bound = true;
            sent.length = 0;
            const p1 = tab2.loadMessages();
            const p2 = tab2.loadMessages();
            const getMessagesSent = sent.filter((m) => m.type === 'rpc_command' && m.command?.type === 'get_messages').length;

            // 4. Closing a tab sends detach_session.
            sent.length = 0;
            tab.close();
            tab2.close();
            console.log(JSON.stringify({
                afterTripleFailure,
                afterReachable,
                afterDisconnect,
                samePromise: p1 === p2,
                getMessagesSent,
                detached: sent.some((m) => m.type === 'detach_session' && m.tabId === tab.id),
            }));
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/app.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode('', $out), true);

        $this->assertSame(1, $r['afterTripleFailure'], 'at most one retry timer is armed at a time');
        $this->assertSame(0, $r['afterReachable'], 'pigReachable cancels the pending retry timer');
        $this->assertSame(0, $r['afterDisconnect'], 'a disconnected tab does not arm pigUnreachable');
        $this->assertTrue($r['samePromise'], 'concurrent loadMessages() calls share one in-flight Promise');
        $this->assertSame(1, $r['getMessagesSent'], 'only one get_messages RPC goes out while in-flight');
        $this->assertTrue($r['detached'], 'closing a tab sends detach_session');
    }

    public function testAnyFileCanBePickedOrDroppedAndNonImagesInsertTheirStagedPath(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../src/Web/assets/index.html');
        $this->assertStringNotContainsString('id="file-input" accept=', $html, 'the file picker must not grey out non-image files');

        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not available to run the browser code');
        }

        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8');
            const start = src.indexOf('const IMAGE_MIME_BY_EXT =');
            const end = src.indexOf('function renderPendingImages()', start);
            if (start === -1 || end === -1) throw new Error('Could not extract file handlers');

            const pendingImages = [];
            const promptInput = {
                value: '',
                selectionStart: 0,
                selectionEnd: 0,
                focus() {},
                setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
            };
            const attachBtn = { disabled: false };
            const fn = new Function(
                'promptInput', 'attachBtn', 'pendingImages', 'renderPendingImages', 'resizePrompt', 'randomHex', 'fetch', 'FileReader', 'appendErrorMessage', 't',
                `${src.slice(start, end)}\nreturn { handleFiles, insertPathsAtCursor };`,
            );
            const { handleFiles } = fn(
                promptInput,
                attachBtn,
                pendingImages,
                () => {},
                () => {},
                () => '0123456789abcdef0123456789abcdef',
                async (url) => ({ ok: true, status: 200, json: async () => ({ ok: true, path: '/tmp/pig-upload/' + new URL('http://x' + url).searchParams.get('name') }) }),
                class { readAsDataURL(f) { this.onload({ target: { result: 'data:image/png;base64,AA==' } }); } },
                (e) => { throw new Error(e); },
                (k) => k,
            );

            (async () => {
                const fakeFile = (name, type) => ({ name, type, size: 4, slice: () => ({ arrayBuffer: async () => new ArrayBuffer(4) }) });
                await handleFiles([fakeFile('changelog-1.5.3.md', 'text/markdown'), fakeFile('shot.PNG', '')]);
                const firstPrompt = promptInput.value;
                promptInput.value = '!adb install';
                promptInput.selectionStart = promptInput.selectionEnd = promptInput.value.length;
                await handleFiles([fakeFile('Doka 1.3.apk', 'application/vnd.android.package-archive')]);
                console.log(JSON.stringify({
                    firstPrompt,
                    bashPrompt: promptInput.value,
                    images: pendingImages.length,
                    imageMime: pendingImages[0]?.mimeType,
                }));
            })();
            JS;
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' ' . escapeshellarg(realpath(__DIR__ . '/../src/Web/assets/js/app.js')) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode('', $out), true);

        $this->assertSame('/tmp/pig-upload/changelog-1.5.3.md ', $r['firstPrompt']);
        $this->assertSame("!adb install '/tmp/pig-upload/Doka 1.3.apk' ", $r['bashPrompt']);
        $this->assertSame(1, $r['images']);
        $this->assertSame('image/png', $r['imageMime']);
    }
}
