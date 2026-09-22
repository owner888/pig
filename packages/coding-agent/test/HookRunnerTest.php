<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Hooks\Events\AgentStartEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\ToolCallEvent;
use Pig\CodingAgent\Hooks\Events\ToolResultEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Hooks\Results\ContextEventResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Hooks\Results\ToolResultEventResult;
use InvalidArgumentException;
use RuntimeException;

final class HookRunnerTest extends TestCase
{
    /** @var list<HookError> what the runner reported while a test ran */
    private array $errors = [];

    /** @param array<string, callable> $handlers one per event, which is all these tests need */
    private function hook(array $handlers, string $path = 'test.php'): LoadedHook
    {
        $api = new HookApi('.', $path);

        foreach ($handlers as $event => $handler) {
            $api->on($event, $handler);
        }

        return new LoadedHook($path, $path, $api);
    }

    /** A runner whose complaints land in $this->errors. @param list<LoadedHook> $hooks */
    private function runner(array $hooks): HookRunner
    {
        $this->errors = [];
        $runner = new HookRunner($hooks, '/work');
        $runner->onError(function (HookError $error): void {
            $this->errors[] = $error;
        });

        return $runner;
    }

    // ---- what is listening -------------------------------------------------------------

    public function testAnEmptyRunnerHasNothingListening(): void
    {
        $runner = new HookRunner();

        $this->assertTrue($runner->isEmpty());
        $this->assertFalse($runner->hasHandlers('tool_call'));
        $this->assertSame([], $runner->paths());
    }

    public function testHasHandlersFindsAcrossHooks(): void
    {
        $runner = new HookRunner([
            $this->hook(['agent_start' => static fn () => null], 'a.php'),
            $this->hook(['tool_call' => static fn () => null], 'b.php'),
        ]);

        $this->assertTrue($runner->hasHandlers('agent_start'));
        $this->assertTrue($runner->hasHandlers('tool_call'));
        $this->assertFalse($runner->hasHandlers('turn_end'));
        $this->assertSame(['a.php', 'b.php'], $runner->paths());
    }

    // ---- fire and forget ---------------------------------------------------------------

    public function testEveryHandlerSeesAFireAndForgetEvent(): void
    {
        $seen = [];
        $runner = new HookRunner([
            $this->hook(['agent_start' => static function () use (&$seen): void {
                $seen[] = 'a';
            }], 'a.php'),
            $this->hook(['agent_start' => static function () use (&$seen): void {
                $seen[] = 'b';
            }], 'b.php'),
        ]);

        $runner->emit(new AgentStartEvent());

        $this->assertSame(['a', 'b'], $seen);
    }

    /** One person's broken hook is not another's. */
    public function testAHandlerThatThrowsIsReportedAndTheRestStillRun(): void
    {
        $reached = false;
        $runner = $this->runner([
            $this->hook(['agent_start' => static function (): void {
                throw new RuntimeException('boom');
            }], 'bad.php'),
            $this->hook(['agent_start' => static function () use (&$reached): void {
                $reached = true;
            }], 'good.php'),
        ]);

        $runner->emit(new AgentStartEvent());

        $this->assertTrue($reached);
        $this->assertCount(1, $this->errors);
        $this->assertSame('bad.php', $this->errors[0]->hookPath);
        $this->assertSame('agent_start', $this->errors[0]->event);
        $this->assertStringContainsString('boom', $this->errors[0]->error);
    }

    public function testTheContextCarriesTheWorkingDirectoryAndTheCurrentModel(): void
    {
        $runner = new HookRunner([], '/work');
        $runner->initialize(getModel: static fn () => null, isIdle: static fn (): bool => false);

        $context = $runner->context();

        $this->assertSame('/work', $context->cwd);
        $this->assertNull($context->model);
        $this->assertFalse($context->isIdle());
        $this->assertFalse($context->hasQueuedMessages());
    }

    public function testAContextWithNothingWiredUpIsIdle(): void
    {
        $this->assertTrue((new HookRunner())->context()->isIdle());
    }

    // ---- tool_call ---------------------------------------------------------------------

    public function testNoHandlerMeansNoOpinion(): void
    {
        $runner = new HookRunner();

        $this->assertNull($runner->emitToolCall(new ToolCallEvent('bash', '1', [])));
    }

    public function testTheFirstBlockStopsTheRest(): void
    {
        $asked = [];
        $runner = new HookRunner([
            $this->hook(['tool_call' => static function () use (&$asked) {
                $asked[] = 'a';

                return new ToolCallEventResult(block: true, reason: 'no');
            }], 'a.php'),
            $this->hook(['tool_call' => static function () use (&$asked) {
                $asked[] = 'b';

                return null;
            }], 'b.php'),
        ]);

        $result = $runner->emitToolCall(new ToolCallEvent('bash', '1', []));

        $this->assertSame(['a'], $asked);
        $this->assertTrue($result?->block);
        $this->assertSame('no', $result->reason);
    }

    public function testAHandlerThatDoesNotBlockLetsTheNextOneAnswer(): void
    {
        $runner = new HookRunner([
            $this->hook(['tool_call' => static fn () => new ToolCallEventResult()], 'a.php'),
            $this->hook(['tool_call' => static fn () => new ToolCallEventResult(block: true)], 'b.php'),
        ]);

        $this->assertTrue($runner->emitToolCall(new ToolCallEvent('bash', '1', []))?->block);
    }

    /** A hook asked whether a tool may run and answering with an exception has not said yes. */
    public function testAToolCallHandlerThatThrowsIsNotCaughtHere(): void
    {
        $runner = new HookRunner([
            $this->hook(['tool_call' => static function (): void {
                throw new RuntimeException('guard is broken');
            }]),
        ]);

        try {
            $runner->emitToolCall(new ToolCallEvent('bash', '1', []));
            $this->fail('expected the throw to reach the caller');
        } catch (RuntimeException $error) {
            $this->assertSame('guard is broken', $error->getMessage());
        }
    }

    public function testABlockWithNoReasonStillTellsTheModelSomething(): void
    {
        $this->assertSame(
            'Tool execution was blocked by a hook',
            (new ToolCallEventResult(block: true))->message(),
        );
    }

    public function testAHandlerReturningTheWrongTypeIsReportedAndIgnored(): void
    {
        $runner = $this->runner([
            $this->hook(['tool_call' => static fn (): string => 'block it'], 'a.php'),
        ]);

        $this->assertNull($runner->emitToolCall(new ToolCallEvent('bash', '1', [])));
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('handler returned string', $this->errors[0]->error);
    }

    // ---- tool_result -------------------------------------------------------------------

    public function testTheLastToolResultHandlerWins(): void
    {
        $runner = new HookRunner([
            $this->hook(['tool_result' => static fn () => new ToolResultEventResult([new TextContent('a')])], 'a.php'),
            $this->hook(['tool_result' => static fn () => new ToolResultEventResult([new TextContent('b')])], 'b.php'),
        ]);

        $result = $runner->emitToolResult(new ToolResultEvent('read', '1', [], [new TextContent('raw')]));

        $this->assertSame('b', $result?->content[0]->text);
    }

    /** Each handler sees the tool's own output, not the edit the hook before it made. */
    public function testEachToolResultHandlerSeesTheToolsOwnOutput(): void
    {
        $seen = [];
        $runner = new HookRunner([
            $this->hook(['tool_result' => static function ($event) use (&$seen) {
                $seen[] = $event->content[0]->text;

                return new ToolResultEventResult([new TextContent('replaced')]);
            }], 'a.php'),
            $this->hook(['tool_result' => static function ($event) use (&$seen) {
                $seen[] = $event->content[0]->text;

                return null;
            }], 'b.php'),
        ]);

        $runner->emitToolResult(new ToolResultEvent('read', '1', [], [new TextContent('raw')]));

        $this->assertSame(['raw', 'raw'], $seen);
    }

    public function testAToolResultHandlerThatThrowsIsCaught(): void
    {
        $runner = $this->runner([
            $this->hook(['tool_result' => static function (): void {
                throw new RuntimeException('counted wrong');
            }]),
        ]);

        $this->assertNull($runner->emitToolResult(new ToolResultEvent('read', '1', [], [])));
        $this->assertCount(1, $this->errors);
    }

    // ---- context -----------------------------------------------------------------------

    public function testContextHandlersAreChained(): void
    {
        $runner = new HookRunner([
            $this->hook(['context' => static fn ($event) => new ContextEventResult(
                [...$event->messages, new UserMessage('from a')],
            )], 'a.php'),
            $this->hook(['context' => static fn ($event) => new ContextEventResult(
                [...$event->messages, new UserMessage('from b')],
            )], 'b.php'),
        ]);

        $out = $runner->emitContext([new UserMessage('original')]);

        $this->assertCount(3, $out);
        $this->assertSame('from b', $out[2]->content[0]->text);
    }

    public function testAContextHandlerReturningNullChangesNothing(): void
    {
        $runner = new HookRunner([$this->hook(['context' => static fn () => null])]);
        $messages = [new UserMessage('one')];

        $this->assertSame($messages, $runner->emitContext($messages));
    }

    public function testAContextHandlerThatThrowsLeavesTheConversationAlone(): void
    {
        $runner = $this->runner([
            $this->hook(['context' => static function (): void {
                throw new RuntimeException('bad edit');
            }]),
        ]);

        $messages = [new UserMessage('one')];

        $this->assertSame($messages, $runner->emitContext($messages));
        $this->assertCount(1, $this->errors);
    }

    // ---- before_agent_start ------------------------------------------------------------

    public function testTheFirstNoteWins(): void
    {
        $runner = new HookRunner([
            $this->hook(['before_agent_start' => static fn () => new BeforeAgentStartEventResult('first')], 'a.php'),
            $this->hook(['before_agent_start' => static fn () => new BeforeAgentStartEventResult('second')], 'b.php'),
        ]);

        $this->assertSame('first', $runner->emitBeforeAgentStart('hello')?->text);
    }

    public function testTheHandlerSeesThePrompt(): void
    {
        $seen = null;
        $runner = new HookRunner([
            $this->hook(['before_agent_start' => static function ($event) use (&$seen) {
                $seen = $event->prompt;

                return null;
            }]),
        ]);

        $runner->emitBeforeAgentStart('fix the tests');

        $this->assertSame('fix the tests', $seen);
    }

    // ---- the cancellable ones ----------------------------------------------------------

    public function testTheFirstRefusalStopsTheRest(): void
    {
        $asked = [];
        $runner = new HookRunner([
            $this->hook(['session_before_switch' => static function () use (&$asked) {
                $asked[] = 'a';

                return new SessionBeforeSwitchResult(cancel: true);
            }], 'a.php'),
            $this->hook(['session_before_switch' => static function () use (&$asked) {
                $asked[] = 'b';

                return null;
            }], 'b.php'),
        ]);

        $result = $runner->emitBeforeSwitch(new SessionBeforeSwitchEvent('new'));

        $this->assertTrue($result?->cancel);
        $this->assertSame(['a'], $asked);
    }

    public function testNotCancellingIsTheSameAsSayingNothing(): void
    {
        $runner = new HookRunner([
            $this->hook(['session_before_switch' => static fn () => new SessionBeforeSwitchResult()]),
        ]);

        $this->assertNull($runner->emitBeforeSwitch(new SessionBeforeSwitchEvent('resume')));
    }

    // ---- commands ----------------------------------------------------------------------

    public function testCommandsAreCollectedFromEveryHook(): void
    {
        $a = new HookApi('.', 'a.php');
        $a->registerCommand('deploy', static fn () => null, 'Ship it');
        $b = new HookApi('.', 'b.php');
        $b->registerCommand('rollback', static fn () => null);

        $runner = new HookRunner([new LoadedHook('a.php', 'a.php', $a), new LoadedHook('b.php', 'b.php', $b)]);
        [$commands, $clashes] = $runner->commands();

        $this->assertSame(['deploy', 'rollback'], array_keys($commands));
        $this->assertSame('Ship it', $commands['deploy']->description);
        $this->assertSame([], $clashes);
    }

    public function testTheFirstHookToRegisterANameKeepsIt(): void
    {
        $a = new HookApi('.', 'a.php');
        $a->registerCommand('deploy', static fn () => null, 'from a');
        $b = new HookApi('.', 'b.php');
        $b->registerCommand('deploy', static fn () => null, 'from b');

        $runner = new HookRunner([new LoadedHook('a.php', 'a.php', $a), new LoadedHook('b.php', 'b.php', $b)]);
        [$commands, $clashes] = $runner->commands();

        $this->assertSame('from a', $commands['deploy']->description);
        $this->assertCount(1, $clashes);
        $this->assertSame('b.php', $clashes[0]->hookPath);
        $this->assertStringContainsString('already registered by a.php', $clashes[0]->error);
    }

    public function testTheSameHookCannotRegisterANameTwice(): void
    {
        $api = new HookApi('.', 'a.php');
        $api->registerCommand('deploy', static fn () => null);

        try {
            $api->registerCommand('deploy', static fn () => null);
            $this->fail('expected the second registration to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('already registered /deploy', $error->getMessage());
        }
    }

    public function testALeadingSlashIsAllowedAndDropped(): void
    {
        $api = new HookApi('.', 'a.php');
        $api->registerCommand('/deploy', static fn () => null);

        $this->assertSame(['deploy'], array_keys($api->commands()));
    }

    public function testACommandNameWithASpaceIsRefused(): void
    {
        try {
            (new HookApi('.'))->registerCommand('ship it', static fn () => null);
            $this->fail('expected the name to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cannot be a command name', $error->getMessage());
        }
    }

    // ---- exec --------------------------------------------------------------------------

    public function testExecReturnsWhatTheCommandSaid(): void
    {
        $result = (new HookApi('.'))->exec(['echo', 'hello']);

        $this->assertTrue($result->ok());
        $this->assertSame("hello\n", $result->stdout);
        $this->assertFalse($result->stopped());
    }

    public function testExecRunsWhereItIsToldTo(): void
    {
        $result = (new HookApi(sys_get_temp_dir()))->exec(['pwd']);

        $this->assertSame(realpath(sys_get_temp_dir()), realpath(trim($result->stdout)));
    }

    public function testExecReportsAFailingCommandRatherThanThrowing(): void
    {
        $result = (new HookApi('.'))->exec(['sh', '-c', 'echo oops >&2; exit 3']);

        $this->assertFalse($result->ok());
        $this->assertSame(3, $result->exitCode);
        $this->assertStringContainsString('oops', $result->stderr);
    }

    // ---- the wrapped tool --------------------------------------------------------------

    public function testAToolWithNoHooksIsNotWrappedAtAll(): void
    {
        $tools = [new RecordingTool()];

        $this->assertSame($tools, HookedTool::wrap($tools, new HookRunner()));
    }

    public function testAWrappedToolLooksExactlyLikeTheToolItWraps(): void
    {
        $tool = new RecordingTool();
        $wrapped = new HookedTool($tool, new HookRunner());

        $this->assertSame('read', $wrapped->definition()->name);
        $this->assertSame('Read file', $wrapped->label());
        $this->assertSame($tool, $wrapped->inner());
    }

    public function testAToolRunsWhenNothingBlocksIt(): void
    {
        $tool = new RecordingTool();
        $wrapped = new HookedTool($tool, new HookRunner([$this->hook(['tool_call' => static fn () => null])]));

        $result = $wrapped->execute('1', ['path' => 'x']);

        $this->assertSame(1, $tool->calls);
        $this->assertSame('read x', $result->content[0]->text);
    }

    public function testABlockedToolNeverRuns(): void
    {
        $tool = new RecordingTool();
        $wrapped = new HookedTool($tool, new HookRunner([
            $this->hook(['tool_call' => static fn () => new ToolCallEventResult(block: true, reason: 'not here')]),
        ]));

        try {
            $wrapped->execute('1', ['path' => 'x']);
            $this->fail('expected the call to be blocked');
        } catch (AgentError $error) {
            $this->assertSame('not here', $error->getMessage());
        }

        $this->assertSame(0, $tool->calls);
    }

    /** A guard with a typo in it must not be a guard that waves everything through. */
    public function testAToolCallHookThatThrowsBlocksTheCall(): void
    {
        $tool = new RecordingTool();
        $wrapped = new HookedTool($tool, new HookRunner([
            $this->hook(['tool_call' => static function (): void {
                throw new RuntimeException('typo');
            }]),
        ]));

        try {
            $wrapped->execute('1', ['path' => 'x']);
            $this->fail('expected the call to be blocked');
        } catch (AgentError $error) {
            $this->assertStringContainsString('is not consent', $error->getMessage());
            $this->assertStringContainsString('typo', $error->getMessage());
        }

        $this->assertSame(0, $tool->calls);
    }

    public function testTheHookSeesTheToolNameAndArguments(): void
    {
        $seen = null;
        $wrapped = new HookedTool(new RecordingTool(), new HookRunner([
            $this->hook(['tool_call' => static function ($event) use (&$seen) {
                $seen = $event;

                return null;
            }]),
        ]));

        $wrapped->execute('call-7', ['path' => 'x']);

        $this->assertSame('read', $seen?->toolName);
        $this->assertSame('call-7', $seen->toolCallId);
        $this->assertSame(['path' => 'x'], $seen->input);
    }

    public function testAHookCanReplaceWhatTheModelIsShown(): void
    {
        $wrapped = new HookedTool(new RecordingTool(), new HookRunner([
            $this->hook(['tool_result' => static fn () => new ToolResultEventResult([new TextContent('redacted')])]),
        ]));

        $result = $wrapped->execute('1', ['path' => 'secrets']);

        $this->assertSame('redacted', $result->content[0]->text);
        // Untouched, because the hook said nothing about it.
        $this->assertSame('details for secrets', $result->details);
    }

    public function testAHookCanReplaceTheDetailsAlone(): void
    {
        $wrapped = new HookedTool(new RecordingTool(), new HookRunner([
            $this->hook(['tool_result' => static fn () => new ToolResultEventResult(details: 'other')]),
        ]));

        $result = $wrapped->execute('1', ['path' => 'x']);

        $this->assertSame('read x', $result->content[0]->text);
        $this->assertSame('other', $result->details);
    }

    public function testAHookCanTurnASuccessIntoAnError(): void
    {
        $wrapped = new HookedTool(new RecordingTool(), new HookRunner([
            $this->hook(['tool_result' => static fn () => new ToolResultEventResult(
                [new TextContent('that file is off limits')],
                isError: true,
            )]),
        ]));

        try {
            $wrapped->execute('1', ['path' => 'x']);
            $this->fail('expected the hook to raise it as an error');
        } catch (AgentError $error) {
            $this->assertSame('that file is off limits', $error->getMessage());
        }
    }

    public function testAFailingToolIsShownToTheHooksAndStillFails(): void
    {
        $seen = null;
        $wrapped = new HookedTool(new ThrowingTool(), new HookRunner([
            $this->hook(['tool_result' => static function ($event) use (&$seen) {
                $seen = $event;

                return null;
            }]),
        ]));

        try {
            $wrapped->execute('1', []);
            $this->fail('expected the tool to fail');
        } catch (RuntimeException $error) {
            $this->assertSame('disk on fire', $error->getMessage());
        }

        $this->assertTrue($seen?->isError);
        $this->assertSame('disk on fire', $seen->content[0]->text);
    }

    public function testProgressIsPassedThroughToTheWrappedTool(): void
    {
        $updates = [];
        $wrapped = new HookedTool(new RecordingTool(), new HookRunner([
            $this->hook(['tool_call' => static fn () => null]),
        ]));

        $wrapped->execute('1', ['path' => 'x'], null, static function (AgentToolResult $partial) use (&$updates): void {
            $updates[] = $partial->content[0]->text;
        });

        $this->assertSame(['working'], $updates);
    }
}

/** A tool that records that it ran, reports progress, and says what it was asked for. */
final class RecordingTool implements AgentTool
{
    public int $calls = 0;

    public function definition(): Tool
    {
        return new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);
    }

    public function label(): string
    {
        return 'Read file';
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?\Closure $onUpdate = null,
    ): AgentToolResult {
        $this->calls++;

        if ($onUpdate !== null) {
            $onUpdate(new AgentToolResult([new TextContent('working')]));
        }

        $path = (string) ($arguments['path'] ?? '');

        return new AgentToolResult([new TextContent("read {$path}")], "details for {$path}");
    }
}

final class ThrowingTool implements AgentTool
{
    public function definition(): Tool
    {
        return new Tool('write', 'Write a file', ['type' => 'object', 'properties' => []]);
    }

    public function label(): string
    {
        return 'Write file';
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?\Closure $onUpdate = null,
    ): AgentToolResult {
        throw new RuntimeException('disk on fire');
    }
}
