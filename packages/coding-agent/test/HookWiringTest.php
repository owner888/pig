<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Hooks\Results\ContextEventResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeCompactResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeTreeResult;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * Where the hooks are actually fired from.
 *
 * `HookRunnerTest` checks what the runner does with a handler; this checks that the
 * session calls it at all, and at the right moment — which is the half of a hook system
 * that goes wrong silently.
 */
final class HookWiringTest extends TestCase
{
    use AssertsThrows;

    /** @var list<array{0: string, 1: mixed}> every event a hook saw, in order */
    private array $seen = [];

    private string $home = '';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->seen = [];

        // Session files land under the agent's home, and a test must not write into the
        // real one.
        $this->home = sys_get_temp_dir() . '/pig-hook-home-' . bin2hex(random_bytes(4));
        putenv("PIG_HOME={$this->home}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');

        foreach (glob($this->home . '/sessions/*/*.jsonl') ?: [] as $file) {
            unlink($file);
        }
    }

    // ---- the run events ----------------------------------------------------------------

    /**
     * Every event a plain run fires, in order.
     *
     * `before_agent_start` comes first because it is the one that can still change what
     * is about to be sent — by the time `agent_start` goes out, the prompt is already in
     * the conversation.
     */
    public function testARunReportsItselfFromStartToEnd(): void
    {
        $session = $this->session(['hello there'], $this->watching(HookApi::EVENTS));

        Async::run(static fn () => $session->prompt('are you there'));

        $this->assertSame(
            ['before_agent_start', 'agent_start', 'turn_start', 'turn_end', 'agent_end'],
            array_column($this->seen, 0),
        );
    }

    public function testTheTurnEndCarriesTheAnswer(): void
    {
        $session = $this->session(['the answer'], $this->watching(['turn_end']));

        Async::run(static fn () => $session->prompt('ask'));

        $this->assertSame('the answer', $this->seen[0][1]->message->content[0]->text);
        $this->assertSame(0, $this->seen[0][1]->turnIndex);
    }

    public function testTheTurnCounterStartsOverForEachRun(): void
    {
        $session = $this->session(['one', 'two'], $this->watching(['turn_start']));

        Async::run(static fn () => $session->prompt('first'));
        Async::run(static fn () => $session->prompt('second'));

        $this->assertSame([0, 0], array_map(static fn (array $e): int => $e[1]->turnIndex, $this->seen));
    }

    public function testASessionWithNoHooksStillRuns(): void
    {
        $session = $this->session(['fine']);

        Async::run(static fn () => $session->prompt('hello'));

        $this->assertSame('fine', $session->lastAssistantText());
    }

    // ---- before_agent_start ------------------------------------------------------------

    public function testANoteFromAHookGoesInFrontOfThePrompt(): void
    {
        $session = $this->session(['ok'], [
            'before_agent_start' => static fn () => new BeforeAgentStartEventResult('on branch main'),
        ]);

        Async::run(static fn () => $session->prompt('what changed'));

        $messages = $session->messages();

        $this->assertSame('on branch main', $messages[0]->content[0]->text);
        $this->assertSame('what changed', $messages[1]->content[0]->text);
    }

    /** The note is a message like any other, so a resumed session still has it. */
    public function testTheNoteIsWrittenToTheSessionFile(): void
    {
        $store = SessionManager::create(sys_get_temp_dir() . '/pig-hook-note-' . bin2hex(random_bytes(3)));
        $session = $this->session(['ok'], [
            'before_agent_start' => static fn () => new BeforeAgentStartEventResult('on branch main'),
        ], $store);

        Async::run(static fn () => $session->prompt('what changed'));

        $reopened = SessionManager::open($store->path)->messages();
        unlink($store->path);

        $this->assertSame('on branch main', $reopened[0]->content[0]->text);
        $this->assertSame('what changed', $reopened[1]->content[0]->text);
    }

    public function testAnEmptyNoteIsNotAMessage(): void
    {
        $session = $this->session(['ok'], [
            'before_agent_start' => static fn () => new BeforeAgentStartEventResult('   '),
        ]);

        Async::run(static fn () => $session->prompt('what changed'));

        $this->assertSame('what changed', $session->messages()[0]->content[0]->text);
    }

    public function testImagesStillReachTheModelWhenAHookAddsANote(): void
    {
        $session = $this->session(['ok'], [
            'before_agent_start' => static fn () => new BeforeAgentStartEventResult('note'),
        ]);

        Async::run(static fn () => $session->prompt('look at this', [new \Pig\Ai\ImageContent('AAA', 'image/png')]));

        $this->assertCount(2, $session->messages()[1]->content);
    }

    // ---- context -----------------------------------------------------------------------

    /** The agent a coding agent builds is the one that runs the `context` event. */
    public function testCodingAgentInstallsTheContextHook(): void
    {
        $hooks = $this->hooks([
            'context' => static fn ($event) => new ContextEventResult(
                [...$event->messages, new UserMessage('added for this request only')],
            ),
        ]);

        $transform = CodingAgent::create(
            $this->model(),
            sys_get_temp_dir(),
            [],
            apiKey: 'test-key',
            hooks: $hooks,
        )->options()->transformContext;

        $this->assertNotNull($transform);

        $sent = $transform([new UserMessage('the only thing I said')], null);

        $this->assertCount(2, $sent);
        $this->assertSame('added for this request only', $sent[1]->content[0]->text);
    }

    public function testNoHooksMeansNoContextStepAtAll(): void
    {
        $agent = CodingAgent::create($this->model(), sys_get_temp_dir(), [], apiKey: 'test-key');

        $this->assertNull($agent->options()->transformContext);
    }

    public function testTheToolsACodingAgentIsGivenAreWrappedWhenThereAreHooks(): void
    {
        $hooks = $this->hooks(['tool_call' => static fn () => null]);
        $agent = CodingAgent::create($this->model(), sys_get_temp_dir(), ['read'], apiKey: 'test-key', hooks: $hooks);

        $this->assertInstanceOf(HookedTool::class, $agent->state->tools[0]);
    }

    public function testTheToolsAreLeftAloneWhenThereAreNone(): void
    {
        $agent = CodingAgent::create($this->model(), sys_get_temp_dir(), ['read'], apiKey: 'test-key');

        $this->assertFalse($agent->state->tools[0] instanceof HookedTool);
    }

    /**
     * A hook that edits the context has not edited the conversation.
     *
     * That is the whole point of the event running on the way out: a hook that trims what
     * one request carries has not deleted anybody's messages.
     */
    public function testAContextHookDoesNotChangeTheConversationThatIsKept(): void
    {
        $asked = null;
        $hooks = $this->hooks([
            'context' => static fn ($event) => new ContextEventResult(
                [...$event->messages, new UserMessage('added for this request only')],
            ),
        ]);

        $agent = new Agent(new AgentOptions(
            transformContext: static fn (array $messages): array => $hooks->emitContext($messages),
            streamFn: function (Model $model, Context $context, SimpleStreamOptions $options) use (&$asked) {
                $asked = $context;

                return $this->replay('ok');
            },
            apiKey: 'test-key',
        ));

        $agent->setModel($this->model());
        $session = new AgentSession($agent, sys_get_temp_dir(), null, null, $hooks);
        Async::run(static fn () => $session->prompt('the only thing I said'));

        $sent = array_map(static fn (mixed $m): string => $m->content[0]->text ?? '', $asked?->messages ?? []);

        $this->assertContains('added for this request only', $sent);
        $this->assertSame(
            ['the only thing I said', 'ok'],
            array_map(static fn (mixed $m): string => $m->content[0]->text, $session->messages()),
        );
    }

    // ---- compaction --------------------------------------------------------------------

    public function testAHookCanStopACompaction(): void
    {
        $session = $this->session([], [
            'session_before_compact' => static fn () => new SessionBeforeCompactResult(cancel: true),
        ]);
        $session->restore($this->longConversation());

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $session->compact()),
            'A hook stopped the compaction',
        );
    }

    public function testAHookCanWriteTheSummaryItself(): void
    {
        $session = $this->session([], [
            'session_before_compact' => static fn () => new SessionBeforeCompactResult(
                compaction: new CompactionSummary('a hook wrote this', ['a.php'], ['b.php'], 1234),
            ),
            'session_compact' => $this->record('session_compact'),
        ]);
        $session->restore($this->longConversation());

        $summary = Async::run(static fn () => $session->compact());

        $this->assertSame('a hook wrote this', $summary?->summary);
        $this->assertSame(['a.php'], $summary->readFiles);
        // `replaced` is the session's, not the hook's: it is what replaying the file needs.
        $this->assertGreaterThan(0, $summary->replaced);
        $this->assertSame('session_compact', $this->seen[0][0]);
        $this->assertTrue($this->seen[0][1]->fromHook);
    }

    public function testACompactionByTheModelIsReportedAsNotFromAHook(): void
    {
        $session = $this->session(['it was about the tests'], [
            'session_compact' => $this->record('session_compact'),
            'session_before_compact' => static fn () => null,
        ]);
        $session->restore($this->longConversation());

        Async::run(static fn () => $session->compact());

        $this->assertFalse($this->seen[0][1]->fromHook);
    }

    public function testTheBeforeCompactEventCarriesWhatWouldBeReplaced(): void
    {
        $session = $this->session(['summary'], [
            'session_before_compact' => $this->record('session_before_compact'),
        ]);
        $session->restore($this->longConversation());

        Async::run(static fn () => $session->compact('focus on the tests'));

        $event = $this->seen[0][1];

        $this->assertNotSame([], $event->messages);
        $this->assertSame('focus on the tests', $event->customInstructions);
        $this->assertStringContainsString('focus on the tests', $event->request);
    }

    // ---- the tree ----------------------------------------------------------------------

    public function testAHookCanStopAJump(): void
    {
        [$session, $store, $target] = $this->savedConversation([
            'session_before_tree' => static fn () => new SessionBeforeTreeResult(cancel: true),
        ]);

        $before = $store->leaf();

        $this->assertThrows(
            AgentError::class,
            static fn () => $session->goTo($target),
            'A hook stopped the jump',
        );
        $this->assertSame($before, $store->leaf());

        unlink($store->path);
    }

    public function testTheJumpEventSaysWhereItWentAndWhatWasLeft(): void
    {
        [$session, $store, $target] = $this->savedConversation([
            'session_before_tree' => $this->record('session_before_tree'),
            'session_tree' => $this->record('session_tree'),
        ]);

        $before = $store->leaf();
        $session->goTo($target);

        [$beforeName, $beforeEvent] = $this->seen[0];
        [$afterName, $afterEvent] = $this->seen[1];

        $this->assertSame('session_before_tree', $beforeName);
        $this->assertSame($target, $beforeEvent->targetId);
        $this->assertSame($before, $beforeEvent->oldLeafId);
        $this->assertCount(2, $beforeEvent->entries);

        $this->assertSame('session_tree', $afterName);
        $this->assertSame($target, $afterEvent->newLeafId);
        $this->assertSame($before, $afterEvent->oldLeafId);

        unlink($store->path);
    }

    // ---- errors ------------------------------------------------------------------------

    public function testAHookThatThrowsDuringARunDoesNotStopTheRun(): void
    {
        $errors = [];
        $hooks = $this->hooks(['turn_end' => static function (): void {
            throw new RuntimeException('bad hook');
        }]);
        $hooks->onError(static function (HookError $error) use (&$errors): void {
            $errors[] = $error;
        });

        $session = $this->sessionWith($hooks, ['still answered']);
        Async::run(static fn () => $session->prompt('hello'));

        $this->assertSame('still answered', $session->lastAssistantText());
        $this->assertCount(1, $errors);
        $this->assertSame('turn_end', $errors[0]->event);
    }

    // ---- scaffolding -------------------------------------------------------------------

    /** @param array<string, callable> $handlers */
    private function hooks(array $handlers): HookRunner
    {
        $api = new HookApi('.', 'test.php');

        foreach ($handlers as $event => $handler) {
            $api->on($event, $handler);
        }

        return new HookRunner([new LoadedHook('test.php', 'test.php', $api)], sys_get_temp_dir());
    }

    /** A handler that records the event under its name. */
    private function record(string $name): Closure
    {
        return function (mixed $event) use ($name) {
            $this->seen[] = [$name, $event];

            return null;
        };
    }

    /**
     * One recording handler per event named.
     *
     * @param list<string> $events
     * @return array<string, callable>
     */
    private function watching(array $events): array
    {
        $handlers = [];

        foreach ($events as $event) {
            $handlers[$event] = $this->record($event);
        }

        return $handlers;
    }

    /**
     * @param list<string>            $answers  one per model call
     * @param array<string, callable> $handlers
     */
    private function session(array $answers, array $handlers = [], ?SessionManager $store = null): AgentSession
    {
        return $this->sessionWith($handlers === [] ? null : $this->hooks($handlers), $answers, $store);
    }

    /** @param list<string> $answers */
    private function sessionWith(?HookRunner $hooks, array $answers, ?SessionManager $store = null): AgentSession
    {
        $index = 0;
        $agent = new Agent(new AgentOptions(
            streamFn: function () use ($answers, &$index): AssistantMessageEventStream {
                return $this->replay($answers[$index++] ?? throw new RuntimeException('out of scripted answers'));
            },
            apiKey: 'test-key',
        ));

        $agent->setModel($this->model());

        return new AgentSession($agent, sys_get_temp_dir(), $store, null, $hooks);
    }

    /**
     * A conversation long enough to be worth compacting.
     *
     * The usage on the last answer is what `shouldCompact` and `tokensBefore` read, and
     * the length is what `cutPoint` needs to find somewhere to cut.
     *
     * @return list<mixed>
     */
    private function longConversation(): array
    {
        $messages = [];
        $filler = str_repeat('a lot has been said about this already. ', 400);

        for ($turn = 0; $turn < 8; $turn++) {
            $messages[] = new UserMessage("question {$turn}: {$filler}");
            $messages[] = new AssistantMessage(
                [new TextContent("answer {$turn}: {$filler}")],
                Api::AnthropicMessages,
                'anthropic',
                'test-model',
                new Usage(50_000, 2_000),
                StopReason::Stop,
            );
        }

        return $messages;
    }

    /**
     * A saved two-message conversation, and a point two messages back to jump to.
     *
     * @param array<string, callable> $handlers
     * @return array{0: AgentSession, 1: SessionManager, 2: string}
     */
    private function savedConversation(array $handlers): array
    {
        $store = SessionManager::create(sys_get_temp_dir() . '/pig-hook-tree-' . bin2hex(random_bytes(3)));
        $store->append(new UserMessage('one'));
        $store->append($this->answer('first'));
        $target = $store->branch()[1]['id'];
        $store->append(new UserMessage('two'));
        $store->append($this->answer('second'));

        $session = $this->session([], $handlers, $store);
        $session->restore($store->messages());

        return [$session, $store, $target];
    }

    private function answer(string $text): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Stop,
        );
    }

    private function replay(string $text): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $message = new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Stop,
        );

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push(new DoneEvent(StopReason::Stop, $message));
            $stream->end();
        });

        return $stream;
    }

    private function model(): Model
    {
        return new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000);
    }
}
