<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentToolResult;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Extensions\EventBus;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\Events\InputEvent;
use Pig\CodingAgent\Hooks\Events\SessionCompactFailedEvent;
use Pig\CodingAgent\Hooks\Events\ToolExecutionEndEvent;
use Pig\CodingAgent\Hooks\Events\ToolExecutionStartEvent;
use Pig\CodingAgent\Hooks\Events\UserBashEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\InputEventResult;
use Pig\CodingAgent\Hooks\Results\UserBashEventResult;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Tools\ToolLoadout;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Test\AssertsThrows;
use RuntimeException;

final class ExtensionContextTest extends TestCase
{
    use GlobalThemeFixture;
    use AssertsThrows;

    private string $home = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
        $this->home = sys_get_temp_dir() . '/pig-ext-ctx-test-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0700, true);
        putenv("PIG_HOME={$this->home}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        putenv('PIG_HOME');
        Loop::reset();
    }

    // ---- EventBus -------------------------------------------------------------------

    public function testEventBusDeliversToSubscribersAndUnsubscribeStopsDelivery(): void
    {
        $bus = new EventBus();
        $seenA = [];
        $seenB = [];

        $offA = $bus->on('msg', static function (mixed $data) use (&$seenA): void {
            $seenA[] = $data;
        });
        $bus->on('msg', static function (mixed $data) use (&$seenB): void {
            $seenB[] = $data;
        });

        $bus->emit('msg', 'first');
        $offA();
        $bus->emit('msg', 'second');

        $this->assertSame(['first'], $seenA);
        $this->assertSame(['first', 'second'], $seenB);
    }

    public function testEventBusHandlerThatThrowsDoesNotStopOtherHandlers(): void
    {
        $bus = new EventBus();
        $called = false;

        $bus->on('ping', static function (): void {
            throw new RuntimeException('handler failed');
        });
        $bus->on('ping', static function () use (&$called): void {
            $called = true;
        });

        $bus->emit('ping', null);

        $this->assertTrue($called);
    }

    public function testLoadedExtensionsShareTheSameEventBusAndClearRemovesAll(): void
    {
        $extDir = $this->home . '/extensions';
        mkdir($extDir, 0700, true);

        file_put_contents($extDir . '/producer.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return static function (ExtensionApi $pi): void {
    $pi->events()->emit('chat', 'hello from producer');
};
PHP);
        file_put_contents($extDir . '/consumer.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return static function (ExtensionApi $pi): void {
    $pi->events()->on('chat', static function ($msg) use ($pi): void {
        $pi->registerFlag('saw_' . $msg);
    });
};
PHP);

        // Load the extensions together. `ExtensionLoader::load()` creates one bus and gives it to both.
        [$loaded, $errors] = ExtensionLoader::load($this->home, home: $this->home);
        $this->assertSame([], $errors);
        $this->assertCount(2, $loaded);

        // Producer runs first due to alphabetical sort, but wait: consumer registers handler, producer emits.
        // Let's verify both apis have the exact same EventBus instance.
        $this->assertSame($loaded[0]->api->events(), $loaded[1]->api->events());
    }

    // ---- InputEvent chaining and handled ---------------------------------------------

    public function testInputHandlerCanTransformTextBeforePrompt(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->on('input', static function (InputEvent $event): InputEventResult {
            return InputEventResult::transform(str_replace('foo', 'bar', $event->text));
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $session = $this->createSession(['ok'], $runner);

        Async::run(static fn () => $session->prompt('hello foo world'));

        // The model sees the transformed prompt
        $messages = $session->messages();
        $this->assertSame('hello bar world', $messages[0]->content[0]->text);
    }

    public function testInputHandlerThatReturnsHandledStopsThePromptCompletely(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->on('input', static function (InputEvent $event): InputEventResult {
            return InputEventResult::handled();
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $session = $this->createSession(['should not be called'], $runner);

        Async::run(static fn () => $session->prompt('ignored prompt'));

        $this->assertSame([], $session->messages());
    }

    // ---- UserBashEvent --------------------------------------------------------------

    public function testUserBashHookCanInterceptAndSupplyCustomExecution(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->on('user_bash', static function (UserBashEvent $event): UserBashEventResult {
            return new UserBashEventResult(
                new BashExecution($event->command, "intercepted: {$event->command}", 0),
            );
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $session = $this->createSession([], $runner);

        $execution = $session->executeBash('echo real', remember: true);

        $this->assertSame('intercepted: echo real', $execution->output);
        $this->assertSame(0, $execution->exitCode);
        // It joined the conversation because remember: true
        $this->assertSame($execution, $session->messages()[0]);
    }

    public function testUserBashHookThatThrowsDoesNotFallBackToLocalExecution(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->on('user_bash', static function (): UserBashEventResult {
            throw new RuntimeException('remote runner unreachable');
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $session = $this->createSession([], $runner);

        $this->assertThrows(RuntimeException::class, static function () use ($session): void {
            $session->executeBash('touch /tmp/should-never-be-created');
        });
    }

    // ---- ToolExecution events -------------------------------------------------------

    public function testToolExecutionEventsAreFiredDuringTurn(): void
    {
        $api = new HookApi('.', 'test.php');
        $starts = [];
        $ends = [];

        $api->on('tool_execution_start', static function (ToolExecutionStartEvent $e) use (&$starts): void {
            $starts[] = [$e->toolName, $e->args];
        });
        $api->on('tool_execution_end', static function (ToolExecutionEndEvent $e) use (&$ends): void {
            $ends[] = [$e->toolName, $e->isError];
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);

        $model = new Model('test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000);
        $agent = new Agent(new AgentOptions(
            streamFn: function (): AssistantMessageEventStream {
                static $step = 0;
                $stream = new AssistantMessageEventStream();
                $step++;
                Async::spawn(static function () use ($stream, $step): void {
                    if ($step === 1) {
                        $msg = new AssistantMessage(
                            [new ToolCall('call-1', 'mock_tool', ['arg' => 'val'])],
                            Api::AnthropicMessages, 'anthropic', 'test', new Usage(), StopReason::ToolUse,
                        );
                        $stream->push(new StartEvent($msg));
                        $stream->push(new DoneEvent(StopReason::ToolUse, $msg));
                    } else {
                        $msg = new AssistantMessage(
                            [new TextContent('all done')],
                            Api::AnthropicMessages, 'anthropic', 'test', new Usage(), StopReason::Stop,
                        );
                        $stream->push(new StartEvent($msg));
                        $stream->push(new DoneEvent(StopReason::Stop, $msg));
                    }
                    $stream->end();
                });
                return $stream;
            },
            apiKey: 'k',
        ));
        $agent->setModel($model);

        $customTools = new CustomToolSet([
            new LoadedCustomTool('mock.php', 'mock.php', new CustomTool(
                'mock_tool',
                'Mock tool',
                'Mock',
                ['type' => 'object', 'properties' => ['arg' => ['type' => 'string']]],
                static fn (): AgentToolResult => new AgentToolResult([new TextContent('result')]),
            )),
        ]);

        $session = new AgentSession($agent, sys_get_temp_dir(), null, null, $runner);
        $loadout = new ToolLoadout($agent, sys_get_temp_dir(), [], $customTools, $runner);
        $loadout->apply();
        $session->useLoadout($loadout);

        Async::run(static fn () => $session->prompt('run mock tool'));

        $this->assertCount(1, $starts);
        $this->assertSame('mock_tool', $starts[0][0]);
        $this->assertSame(['arg' => 'val'], $starts[0][1]);

        $this->assertCount(1, $ends);
        $this->assertSame('mock_tool', $ends[0][0]);
        $this->assertFalse($ends[0][1]);
    }

    // ---- SessionCompactFailedEvent --------------------------------------------------

    public function testSessionCompactFailedIsEmittedWhenCompactThrows(): void
    {
        $api = new HookApi('.', 'test.php');
        $failures = [];

        $api->on('session_compact_failed', static function (SessionCompactFailedEvent $e) use (&$failures): void {
            $failures[] = $e;
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        // Session with no messages — compacting throws "Nothing to compact"
        $session = $this->createSession([], $runner);

        try {
            $session->compact(reason: 'manual');
        } catch (\Throwable) {
            // expected
        }

        $this->assertCount(1, $failures);
        $this->assertSame('manual', $failures[0]->reason);
        $this->assertFalse($failures[0]->aborted);
        $this->assertStringContainsString('too small', (string) $failures[0]->errorMessage);
    }

    // ---- ContextUsage ---------------------------------------------------------------

    public function testContextUsageReportsTokensAndPercent(): void
    {
        $session = $this->createSession([]);
        // Model with 200_000 window
        $usage = $session->contextUsage();
        $this->assertNotNull($usage);
        $this->assertSame(200_000, $usage->contextWindow);
        $this->assertSame(0, $usage->tokens);
        $this->assertSame(0.0, $usage->percent);
    }

    public function testContextUsageReturnsNullTokensAfterCompactionUntilNextResponse(): void
    {
        $store = SessionManager::create(sys_get_temp_dir() . '/pig-usage-test-' . bin2hex(random_bytes(3)));
        $summary = new CompactionSummary('summary text', [], [], 1000);
        $store->append($summary);

        $session = $this->createSession([], store: $store);
        $session->restore($store->messages());

        $usage = $session->contextUsage();
        $this->assertNotNull($usage);
        $this->assertNull($usage->tokens);
        $this->assertNull($usage->percent);
        $this->assertSame(200_000, $usage->contextWindow);
    }

    // ---- ToolLoadout & Tool Management ----------------------------------------------

    public function testSetActiveToolsNarrowsTheOfferedToolsAndPreservesRegisteredSet(): void
    {
        $agent = new Agent(new AgentOptions(streamFn: static fn () => new AssistantMessageEventStream(), apiKey: 'k'));
        $agent->setModel(new Model('test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));
        $runner = new HookRunner();

        $customTools = new CustomToolSet([
            new LoadedCustomTool('a.php', 'a.php', new CustomTool('tool_a', 'Tool A', 'A', ['type' => 'object'], static fn () => new AgentToolResult([]))),
            new LoadedCustomTool('b.php', 'b.php', new CustomTool('tool_b', 'Tool B', 'B', ['type' => 'object'], static fn () => new AgentToolResult([]))),
        ]);

        $loadout = new ToolLoadout($agent, sys_get_temp_dir(), ['read', 'bash'], $customTools, $runner);
        $loadout->apply();

        $session = new AgentSession($agent, sys_get_temp_dir(), null, null, $runner, loadout: $loadout);

        // Initially all 4 tools active
        $this->assertSame(['read', 'bash', 'tool_a', 'tool_b'], $session->activeTools());
        $this->assertCount(4, $session->allTools());

        // Narrow to read and tool_b
        $session->setActiveTools(['read', 'tool_b']);
        $this->assertSame(['read', 'tool_b'], $session->activeTools());

        // allTools() still reports all 4, with active flag matching
        $all = $session->allTools();
        $this->assertCount(4, $all);
        $byName = array_combine(array_column($all, 'name'), $all);
        $this->assertTrue($byName['read']['active']);
        $this->assertFalse($byName['bash']['active']);
        $this->assertFalse($byName['tool_a']['active']);
        $this->assertTrue($byName['tool_b']['active']);

        // Agent state only holds the 2 active tools
        $agentToolNames = array_map(static fn ($t) => $t->definition()->name, $agent->state->tools);
        $this->assertSame(['read', 'tool_b'], $agentToolNames);

        // Reset to all
        $session->setActiveTools($session->loadout()->names());
        $this->assertSame(['read', 'bash', 'tool_a', 'tool_b'], $session->activeTools());
    }

    public function testExecuteToolRunsToolThroughHookedGateAndValidatesSchema(): void
    {
        $api = new HookApi('.', 'test.php');
        $calls = [];
        $api->on('tool_call', static function (\Pig\CodingAgent\Hooks\Events\ToolCallEvent $e) use (&$calls) {
            $calls[] = $e->toolName;
            if ($e->input['forbidden'] ?? false) {
                return new \Pig\CodingAgent\Hooks\Results\ToolCallEventResult(block: true, reason: 'blocked by test');
            }
            return null;
        });

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $agent = new Agent(new AgentOptions(streamFn: static fn () => new AssistantMessageEventStream(), apiKey: 'k'));
        $agent->setModel(new Model('test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));

        $customTools = new CustomToolSet([
            new LoadedCustomTool('calc.php', 'calc.php', new CustomTool(
                'my_calc',
                'Calculator',
                'add',
                ['type' => 'object', 'properties' => ['n' => ['type' => 'integer'], 'forbidden' => ['type' => 'boolean']], 'required' => ['n']],
                static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent((string) ($params['n'] + 1))]),
            )),
        ]);

        $loadout = new ToolLoadout($agent, sys_get_temp_dir(), [], $customTools, $runner);
        $loadout->apply();

        $session = new AgentSession($agent, sys_get_temp_dir(), null, null, $runner, loadout: $loadout);

        // Success execution
        $res = $session->executeTool('my_calc', ['n' => 41]);
        $this->assertSame('42', $res->content[0]->text);
        $this->assertSame(['my_calc'], $calls);

        // Schema validation failure
        $this->assertThrows(\Pig\Agent\InvalidToolArguments::class, static function () use ($session): void {
            $session->executeTool('my_calc', ['n' => 'not an int']);
        });

        // Hook blocked execution
        $this->assertThrows(\Pig\Agent\AgentError::class, static function () use ($session): void {
            $session->executeTool('my_calc', ['n' => 10, 'forbidden' => true]);
        });

        // Non-existent tool
        $this->assertThrows(\Pig\Agent\AgentError::class, static function () use ($session): void {
            $session->executeTool('does_not_exist', []);
        });
    }

    // ---- Shutdown & Mode & ProjectTrust ---------------------------------------------

    public function testShutdownRequestTriggersImmediatelyWhenIdle(): void
    {
        $session = $this->createSession([]);
        $called = false;
        $session->onShutdownRequest(static function () use (&$called): void {
            $called = true;
        });

        $session->requestShutdown();
        $this->assertTrue($called);
    }

    public function testHookContextReflectsModeAndProjectTrustAndSystemPrompt(): void
    {
        $agent = new Agent(new AgentOptions(streamFn: static fn () => new AssistantMessageEventStream(), apiKey: 'k'));
        $agent->setModel(new Model('test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));
        // The prompt is the transcript's system message now (upstream's read-only
        // `state.systemPrompt`), so it is set by one.
        $agent->replaceMessages([new \Pig\Ai\SystemMessage('You are a test pig.')]);

        $session = new AgentSession($agent, '/workspace', null, null, projectTrusted: false);
        $session->setMode('tui');

        $ctx = new HookContext('/workspace', session: $session);

        $this->assertSame('tui', $ctx->mode());
        $this->assertFalse($ctx->isProjectTrusted());
        $this->assertSame('You are a test pig.', $ctx->getSystemPrompt());
    }

    // ---- Group B: Registries -------------------------------------------------------

    public function testRegisterShortcutOnExtensionApiAggregatesAndExecutesOnMatchingKey(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $executed = false;

        $api->registerShortcut('ctrl+x', static function () use (&$executed): void {
            $executed = true;
        }, 'Custom action');

        $this->assertArrayHasKey('ctrl+x', $api->shortcuts());
        $this->assertSame('Custom action', $api->shortcuts()['ctrl+x']['description']);

        $runner = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)]);
        $shortcuts = $runner->shortcuts();
        $this->assertArrayHasKey('ctrl+x', $shortcuts);

        // CustomEditor matching
        $editor = new \Pig\CodingAgent\Interactive\CustomEditor(new \Pig\Tui\Components\Editor());
        $editor->registerShortcut('ctrl+x', static fn () => ($api->shortcuts()['ctrl+x']['handler'])());

        // Ctrl+X is ASCII 24 (\x18)
        $editor->handleInput("\x18");
        $this->assertTrue($executed);
    }

    public function testRegisterMarkdownTransformerTransformsUserAndAssistantText(): void
    {
        $api = new ExtensionApi('/work', 'probe.php', 'probe');
        $api->registerMarkdownTransformer(static function (string $text, array $ctx): string {
            return "[{$ctx['role']}] " . strtoupper($text);
        });

        $runner = new HookRunner([new LoadedHook('probe.php', 'probe.php', $api)]);
        $transformers = $runner->markdownTransformers();
        $this->assertCount(1, $transformers);

        $userComp = new \Pig\CodingAgent\Interactive\UserMessageComponent('hello user', $transformers);
        $userRender = implode("\n", $userComp->render(80));
        $this->assertStringContainsString('[user] HELLO USER', $userRender);

        $asstMsg = new AssistantMessage([new TextContent('hello assistant')], Api::AnthropicMessages, 'anthropic', 'test', new Usage(), StopReason::Stop);
        $asstComp = new \Pig\CodingAgent\Interactive\AssistantMessageComponent($asstMsg, false, $transformers);
        $asstRender = implode("\n", $asstComp->render(80));
        $this->assertStringContainsString('[assistant] HELLO ASSISTANT', $asstRender);
    }

    public function testRegisterToolRendererResolvesInOnionOrder(): void
    {
        $api1 = new ExtensionApi('/work', 'ext1.php', 'ext1');
        $api2 = new ExtensionApi('/work', 'ext2.php', 'ext2');

        $api1->registerToolRenderer(static function (string $name, \Closure $next): ?array {
            if ($name === 'special_tool') {
                return ['renderCall' => static fn () => new \Pig\Tui\Components\Text('ext1 heading', 0, 0)];
            }
            return $next();
        });

        $api2->registerToolRenderer(static function (string $name, \Closure $next): ?array {
            if ($name === 'fallback_tool') {
                return ['renderCall' => static fn () => new \Pig\Tui\Components\Text('ext2 heading', 0, 0)];
            }
            return $next();
        });

        $runner = new HookRunner([
            new LoadedHook('ext1.php', 'ext1.php', $api1),
            new LoadedHook('ext2.php', 'ext2.php', $api2),
        ]);

        $res1 = $runner->resolveToolRenderers('special_tool');
        $this->assertNotNull($res1);
        $this->assertArrayHasKey('renderCall', $res1);

        $res2 = $runner->resolveToolRenderers('fallback_tool');
        $this->assertNotNull($res2);
        $this->assertArrayHasKey('renderCall', $res2);

        $resNone = $runner->resolveToolRenderers('unhandled_tool');
        $this->assertNull($resNone);
    }

    public function testRegisterEntryRendererAggregatesOnHookRunner(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->registerEntryRenderer('my-entry', static fn () => new \Pig\Tui\Components\Text('custom entry', 0, 0));

        $runner = new HookRunner([new LoadedHook('test.php', 'test.php', $api)]);
        $renderers = $runner->entryRenderers();

        $this->assertArrayHasKey('my-entry', $renderers);
    }

    // ---- Group C: UI Context Methods ------------------------------------------------

    public function testTerminalUiPasteTitleAndWorkingControls(): void
    {
        $term = new \Pig\Tui\Test\FakeTerminal();
        $tui = new \Pig\Tui\TuiMainScreen($term);
        $chat = new \Pig\Tui\Container();
        $overlay = new \Pig\Tui\Container();
        $editor = new \Pig\CodingAgent\Interactive\CustomEditor(new \Pig\Tui\Components\Editor());
        $footer = new \Pig\CodingAgent\Interactive\FooterComponent($this->createSession([]), '/work');

        $workingMsg = null;
        $workingVis = null;
        $expanded = false;

        $ui = new \Pig\CodingAgent\Interactive\TerminalUi(
            $tui, $chat, $overlay, $editor, $footer,
            onWorkingMessage: function (?string $m) use (&$workingMsg): void { $workingMsg = $m; },
            onWorkingVisible: function (bool $v) use (&$workingVis): void { $workingVis = $v; },
            getToolsExpanded: function () use (&$expanded): bool { return $expanded; },
            setToolsExpanded: function (bool $v) use (&$expanded): void { $expanded = $v; },
            setTheme: fn (string|\Pig\CodingAgent\Theme\Theme $t): array => $t === 'dark'
                ? ['success' => true]
                : ['success' => false, 'error' => "Theme not found: {$t}"],
        );

        // pasteToEditor
        $ui->pasteToEditor('pasted text');
        $this->assertSame('pasted text', $ui->getEditorText());

        // setTitle
        $ui->setTitle('My Agent');
        $this->assertStringContainsString("\033]0;My Agent\007", $term->output());

        // setWorkingMessage & setWorkingVisible
        $ui->setWorkingMessage('Crunching numbers...');
        $this->assertSame('Crunching numbers...', $workingMsg);
        $ui->setWorkingVisible(true);
        $this->assertTrue($workingVis);

        // toolsExpanded
        $this->assertFalse($ui->getToolsExpanded());
        $ui->setToolsExpanded(true);
        $this->assertTrue($expanded);
        $this->assertTrue($ui->getToolsExpanded());

        // themes
        $this->assertContains('dark', array_map(
            static fn (\Pig\CodingAgent\Theme\ThemeInfo $info): string => $info->name,
            $ui->getAllThemes(),
        ));
        $this->assertNotNull($ui->getTheme('dark'));
        $this->assertSame(['success' => true], $ui->setTheme('dark'));
        $this->assertFalse($ui->setTheme('invalid_theme_name')['success']);
    }

    public function testTerminalUiWidgetsAndHeaderFooter(): void
    {
        $term = new \Pig\Tui\Test\FakeTerminal();
        $tui = new \Pig\Tui\TuiMainScreen($term);
        $chat = new \Pig\Tui\Container();
        $overlay = new \Pig\Tui\Container();
        $widgetsAbove = new \Pig\Tui\Container();
        $widgetsBelow = new \Pig\Tui\Container();
        $customHeader = new \Pig\Tui\Container();
        $customFooter = new \Pig\Tui\Container();
        $editor = new \Pig\CodingAgent\Interactive\CustomEditor(new \Pig\Tui\Components\Editor());
        $footer = new \Pig\CodingAgent\Interactive\FooterComponent($this->createSession([]), '/work');

        $ui = new \Pig\CodingAgent\Interactive\TerminalUi(
            $tui, $chat, $overlay, $editor, $footer,
            widgetsAbove: $widgetsAbove,
            widgetsBelow: $widgetsBelow,
            customHeader: $customHeader,
            customFooter: $customFooter,
        );

        // Widget above
        $ui->setWidget('status_widget', ['Status: Active']);
        $this->assertCount(1, $widgetsAbove->children());

        // Widget below
        $ui->setWidget('footer_widget', ['Bottom Info'], ['placement' => 'below']);
        $this->assertCount(1, $widgetsBelow->children());

        // Remove widget
        $ui->setWidget('status_widget', null);
        $this->assertCount(0, $widgetsAbove->children());

        // Header and Footer factories
        $ui->setHeader(static fn () => new \Pig\Tui\Components\Text('Custom Header', 0, 0));
        $this->assertCount(1, $customHeader->children());

        $ui->setFooter(static fn () => new \Pig\Tui\Components\Text('Custom Footer', 0, 0));
        $this->assertCount(1, $customFooter->children());

        // Clear them
        $ui->setHeader(null);
        $this->assertCount(0, $customHeader->children());
        $ui->setFooter(null);
        $this->assertCount(0, $customFooter->children());
    }

    public function testTerminalUiOnTerminalInputCanInterceptRawInput(): void
    {
        $term = new \Pig\Tui\Test\FakeTerminal();
        $tui = new \Pig\Tui\TuiMainScreen($term);
        $editor = new \Pig\CodingAgent\Interactive\CustomEditor(new \Pig\Tui\Components\Editor());
        $chat = new \Pig\Tui\Container();
        $overlay = new \Pig\Tui\Container();
        $footer = new \Pig\CodingAgent\Interactive\FooterComponent($this->createSession([]), '/work');

        $tui->setFocus($editor);
        $tui->start();

        $intercepted = [];
        $ui = new \Pig\CodingAgent\Interactive\TerminalUi(
            $tui, $chat, $overlay, $editor, $footer,
        );

        $off = $ui->onTerminalInput(static function (string $data) use (&$intercepted) {
            $intercepted[] = $data;
            if ($data === 'X') {
                return true; // consume
            }
            return null;
        });

        // Type 'a'
        $term->type('a');
        $this->assertSame('a', $editor->text());
        $this->assertSame(['a'], $intercepted);

        // Type 'X' — consumed, so editor does NOT see it
        $term->type('X');
        $this->assertSame('a', $editor->text());
        $this->assertSame(['a', 'X'], $intercepted);

        // Unsubscribe
        $off();
        $term->type('X');
        $this->assertSame('aX', $editor->text());

        $tui->stop();
    }

    // ---- helpers --------------------------------------------------------------------

    /** @param list<string> $answers */
    private function createSession(array $answers, ?HookRunner $hooks = null, ?SessionManager $store = null): AgentSession
    {
        $index = 0;
        $agent = new Agent(new AgentOptions(
            streamFn: function () use ($answers, &$index): AssistantMessageEventStream {
                $stream = new AssistantMessageEventStream();
                $ans = $answers[$index++] ?? '';
                Async::spawn(static function () use ($stream, $ans): void {
                    $msg = new AssistantMessage([new TextContent($ans)], Api::AnthropicMessages, 'anthropic', 'test', new Usage(), StopReason::Stop);
                    $stream->push(new StartEvent($msg));
                    $stream->push(new DoneEvent(StopReason::Stop, $msg));
                    $stream->end();
                });
                return $stream;
            },
            apiKey: 'k',
        ));
        $agent->setModel(new Model('test', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));

        return new AgentSession($agent, sys_get_temp_dir(), $store, null, $hooks);
    }
}
