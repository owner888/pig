<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentState;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\SystemMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use ReflectionClass;
use RuntimeException;

final class AgentTest extends TestCase
{
    use AssertsThrows;

    /** @var list<SimpleStreamOptions> every options object the provider was called with */
    private array $options = [];

    /** @var list<TranscriptContext> every context the provider was called with */
    private array $contexts = [];

    /**
     * The agent a mid-run hook acts on.
     *
     * A hook wants the agent, and the agent wants the provider the hook lives in, so one
     * of the two has to be filled in after construction. The hook only runs once the
     * agent exists, which makes this the safe end to defer.
     */
    private ?Agent $current = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->options = [];
        $this->contexts = [];
        $this->current = null;
    }

    public function testAPromptAndItsAnswerBothLandInTheConversation(): void
    {
        $agent = $this->agent(['hello there']);

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertCount(2, $agent->state->messages);
        $this->assertInstanceOf(UserMessage::class, $agent->state->messages[0]);
        $this->assertSame('hi', $agent->state->messages[0]->content[0]->text);
        $this->assertSame('hello there', $agent->state->messages[1]->content[0]->text);
        $this->assertNull($agent->state->error);
    }

    public function testTheConversationCarriesAcrossPrompts(): void
    {
        $agent = $this->agent(['first answer', 'second answer']);

        Async::run(static function () use ($agent): void {
            $agent->prompt('one');
            $agent->prompt('two');
        });

        $this->assertCount(4, $agent->state->messages);
        // The second call was given everything from the first.
        $this->assertCount(3, $this->contexts[1]->messages);
    }

    public function testStreamingIsTrueWhileWorkingAndFalseAfter(): void
    {
        $duringRun = null;
        $agent = $this->agent(['ok']);

        Async::run(static function () use ($agent, &$duringRun): void {
            $agent->subscribe(static function (AgentEvent $event) use ($agent, &$duringRun): void {
                if ($event instanceof AgentStartEvent) {
                    $duringRun = $agent->state->isStreaming;
                }
            });

            $agent->prompt('hi');
        });

        $this->assertTrue($duringRun);
        $this->assertFalse($agent->state->isStreaming);
    }

    public function testSubscribersSeeEventsUntilTheyUnsubscribe(): void
    {
        $agent = $this->agent(['one', 'two']);
        $seen = [];

        Async::run(static function () use ($agent, &$seen): void {
            $unsubscribe = $agent->subscribe(static function (AgentEvent $event) use (&$seen): void {
                $seen[] = (new ReflectionClass($event))->getShortName();
            });

            $agent->prompt('a');
            $afterFirst = count($seen);
            $unsubscribe();
            $agent->prompt('b');

            $seen[] = 'after unsubscribe: ' . (count($seen) - $afterFirst) . ' more';
        });

        $this->assertSame('AgentStartEvent', $seen[0]);
        $this->assertSame('after unsubscribe: 0 more', end($seen));
    }

    /**
     * A throw that escapes the loop is a failed turn that goes out through the same four events a
     * provider's error does — upstream's `handleRunFailure()`. It used to go out as `agent_end`
     * alone, and every reader of a message (the session file, the screen, a host, the retry step)
     * is on `message_end`: a `TypeError` in a TUI listener ended the turn with nothing written and
     * nothing drawn. Red when the three events in front of `agent_end` are taken away again.
     */
    public function testAThrowThatEscapesTheLoopIsAFailedTurnEveryListenerSees(): void
    {
        $agent = $this->agent(['one']);
        $seen = [];
        $thrown = false;

        Async::run(static function () use ($agent, &$seen, &$thrown): void {
            $agent->subscribe(static function (AgentEvent $event) use (&$seen, &$thrown): void {
                $seen[] = (new ReflectionClass($event))->getShortName();

                // Once: the failure's own `message_start` comes through this listener too, and a
                // listener that throws on every message is a different (and upstream's) hazard.
                if ($event instanceof MessageStartEvent && $event->message instanceof AssistantMessage && !$thrown) {
                    $thrown = true;

                    throw new RuntimeException('a listener broke');
                }
            });

            $agent->prompt('a');
        });

        $this->assertSame(
            ['MessageStartEvent', 'MessageEndEvent', 'TurnEndEvent', 'AgentEndEvent'],
            array_slice($seen, -4),
            'the failure goes out as a whole turn, not as agent_end alone',
        );

        $last = end($agent->state->messages);
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame(StopReason::Error, $last->stopReason);
        $this->assertSame('a listener broke', $last->errorMessage);
        $this->assertSame('a listener broke', $agent->state->error);
        $this->assertFalse($agent->state->isStreaming);
    }

    public function testPromptingWhileWorkingIsRefused(): void
    {
        $refusal = null;

        $agent = $this->agent(['ok'], hook: static function (Agent $agent) use (&$refusal): void {
            // Reached while the first run is still in flight.
            try {
                $agent->prompt('me too');
            } catch (AgentError $error) {
                $refusal = $error->getMessage();
            }
        });

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('already working', $refusal);
    }

    public function testContinuingWhileWorkingIsRefusedToo(): void
    {
        // The other half of the same guard, and upstream tests both: `continue()` starting a second
        // loop over the same agent would have two runs writing into one `AgentState`.
        $refusal = null;

        $agent = $this->agent(['ok'], hook: static function (Agent $agent) use (&$refusal): void {
            try {
                $agent->continue();
            } catch (AgentError $error) {
                $refusal = $error->getMessage();
            }
        });

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('already working', $refusal);
    }

    public function testAbortingWhenNothingIsRunningDoesNothing(): void
    {
        // Upstream pins it: `abort()` on an idle agent must not throw. There is no controller to
        // abort, and a UI's escape key does not know whether a turn is in flight.
        $agent = $this->agent([]);

        $agent->abort();

        $this->assertFalse($agent->state->isStreaming);
    }

    public function testTheStateMutatorsEachWriteTheirOwnField(): void
    {
        $agent = $this->agent([]);
        $tool = new class implements \Pig\Agent\AgentTool {
            public function definition(): \Pig\Ai\Tool
            {
                return new \Pig\Ai\Tool('noop', 'does nothing', ['properties' => []]);
            }

            public function label(): string
            {
                return 'Noop';
            }

            public function execute(
                string $toolCallId,
                array $arguments,
                ?\Pig\Async\AbortSignal $signal = null,
                ?Closure $onUpdate = null,
            ): \Pig\Agent\AgentToolResult {
                return new \Pig\Agent\AgentToolResult([new TextContent('')]);
            }
        };

        // No `setSystemPrompt()` any more: upstream's `state.systemPrompt` is read-only, replayed from
        // the transcript's system messages, so the prompt is set by a system message.
        $agent->setThinkingLevel(ThinkingLevel::High);
        $agent->setTools([$tool]);
        $agent->replaceMessages([new SystemMessage('be brief'), new UserMessage('one')]);
        $agent->appendMessage(new UserMessage('two'));

        $this->assertSame('be brief', $agent->state->systemPrompt());
        $this->assertSame(ThinkingLevel::High, $agent->state->thinkingLevel);
        $this->assertSame([$tool], $agent->state->tools);
        $this->assertCount(3, $agent->state->messages);

        $agent->clearMessages();

        $this->assertSame([], $agent->state->messages);
    }

    public function testAToolRegisteredDuringARunReachesTheNextRequestOfThatRun(): void
    {
        // What `tool_search` does: registers a tool from inside a tool call, and the model is meant
        // to see it on its very next call — not on the next prompt. The loop took a snapshot of the
        // tools when it started, so without `getTools` the second request still had the old list.
        $tool = new class implements \Pig\Agent\AgentTool {
            public function definition(): \Pig\Ai\Tool
            {
                return new \Pig\Ai\Tool('found_later', 'arrived mid-run', ['type' => 'object', 'properties' => []]);
            }

            public function label(): string
            {
                return 'Found later';
            }

            public function execute(string $toolCallId, array $arguments, ?\Pig\Async\AbortSignal $signal = null, ?Closure $onUpdate = null): \Pig\Agent\AgentToolResult
            {
                return new \Pig\Agent\AgentToolResult([new TextContent('')]);
            }
        };

        // The provider's hook runs before each request; on the first it registers the tool and
        // queues a follow-up so there is a second request in the same run.
        $calls = 0;
        $agent = $this->agent(['first', 'second'], static function (Agent $agent) use (&$calls, $tool): void {
            if ($calls++ === 0) {
                $agent->setTools([$tool]);
                $agent->followUp(new UserMessage('again'));
            }
        });

        Async::run(fn () => $agent->prompt('hi'));

        $this->assertCount(2, $this->contexts);
        // Declared by a system message the loop puts in before the second request
        // (`declareToolChanges()`), so the transcript's replayed tools are what each request had.
        $this->assertSame([], Transcript::getCurrentTools($this->contexts[0]->messages), 'nothing declared on the first request');
        $this->assertSame(['found_later'], array_map(static fn ($t) => $t->name, Transcript::getCurrentTools($this->contexts[1]->messages)), 'and declared on the second, in the same run');
    }

    public function testReplacingMessagesTakesACopyAndReindexesIt(): void
    {
        // Upstream asserts the copy because a JS array would otherwise be shared with the caller;
        // PHP copies on write by itself, so what is worth pinning here is the other half —
        // `array_values()`, which turns whatever the caller had into a list. A message list with a
        // gap in its keys reaches `count()` and `$messages[count - 1]` in three places, and those
        // read the wrong thing.
        $agent = $this->agent([]);
        $agent->replaceMessages([3 => new UserMessage('one'), 7 => new UserMessage('two')]);

        $this->assertSame([0, 1], array_keys($agent->state->messages));
    }

    public function testSteeringCutsInBeforeTheNextTurn(): void
    {
        $steered = false;

        $agent = $this->agent(
            ['thinking about it', 'right, the other one'],
            hook: static function (Agent $agent) use (&$steered): void {
                if ($steered) {
                    return;
                }

                $steered = true;
                $agent->steer(new UserMessage('no, the other file'));
            },
        );

        Async::run(static fn () => $agent->prompt('read this file'));

        // prompt, first answer, the steer, second answer.
        $this->assertCount(4, $agent->state->messages);
        $this->assertSame('no, the other file', $agent->state->messages[2]->content[0]->text);
        $this->assertSame('right, the other one', $agent->state->messages[3]->content[0]->text);
    }

    public function testAQueueCanBeDeliveredAllAtOnce(): void
    {
        $agent = $this->agent(
            ['first', 'second'],
            hook: $this->queuesTwoFollowUpsOnce(),
            options: ['followUpMode' => QueueMode::All],
        );

        Async::run(static fn () => $agent->prompt('go'));

        // Both follow-ups arrived together, so one further turn covered them.
        $this->assertSame(
            ['go', 'first', 'and this', 'and this too', 'second'],
            $this->texts($agent),
        );
    }

    public function testOneAtATimeIsTheDefault(): void
    {
        $agent = $this->agent(['first', 'second', 'third'], hook: $this->queuesTwoFollowUpsOnce());

        Async::run(static fn () => $agent->prompt('go'));

        // Each follow-up got a turn of its own.
        $this->assertSame(
            ['go', 'first', 'and this', 'second', 'and this too', 'third'],
            $this->texts($agent),
        );
    }

    public function testTheThinkingLevelReachesTheProviderAsReasoning(): void
    {
        $agent = $this->agent(['ok']);
        $agent->setThinkingLevel(ThinkingLevel::Minimal);

        Async::run(static fn () => $agent->prompt('hi'));

        // `minimal` goes through as `minimal`, as upstream's agent passes it: Anthropic's budget for
        // it is 1,024 tokens against low's 2,048, and a model's map can rename it (Copilot's
        // `minimal: "low"`). This used to assert `low` — pig's agent turned minimal into low
        // before any provider saw it, so the minimal budget and those maps were never reached.
        $this->assertSame(ReasoningEffort::Minimal, $this->options[0]->reasoning);
    }

    public function testTheAgentsSessionIdReachesEveryRequest(): void
    {
        // Upstream's `Agent.sessionId` → `AgentLoopConfig.sessionId` → the request's `sessionId`,
        // which Anthropic's session affinity and the Responses API's `prompt_cache_key` key on.
        $agent = $this->agent(['ok']);
        $agent->sessionId = 'session-7';

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertSame('session-7', $this->options[0]->sessionId);
    }

    public function testThinkingOffSendsNoReasoningAtAll(): void
    {
        $agent = $this->agent(['ok']);

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertNull($this->options[0]->reasoning);
    }

    public function testAnAgentNobodyConfiguredStillHasAModel(): void
    {
        // Upstream's default, and it is the free one — an `Agent` built with nothing can answer,
        // which is what a library's first five lines should do. `bin/pig` never sees this: it
        // resolves its own model and calls `setModel()`, with `claude-sonnet-4-5` as *its* default.
        $model = (new Agent())->state->model;

        $this->assertNotNull($model);
        $this->assertSame(AgentState::DEFAULT_MODEL, $model->id);
        $this->assertSame(AgentState::DEFAULT_PROVIDER, $model->provider);
    }

    public function testTheDefaultCanBeReplacedAtConstruction(): void
    {
        $agent = new Agent(new AgentOptions(initialState: new AgentState(model: $this->model())));

        $this->assertSame('test-model', $agent->state->model?->id);
    }

    public function testAStateWithItsModelClearedIsStillAConfigurationError(): void
    {
        // Reachable two ways: a `Model` taken back out of a state that is mutable by design, and a
        // default that is not in the registry — `Models::find()` answers null then, and the message
        // has to be about configuration rather than a null somewhere downstream.
        $agent = $this->agent([]);
        $agent->state->model = null;

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $agent->prompt('hi')),
            'No model configured',
        );
    }

    public function testContinuingFromNothingIsRefusedAndLeavesTheConversationAlone(): void
    {
        // `AgentLoop::continue()` refuses both of these, but it throws *inside* `run()`'s try —
        // so the misuse was being turned into a fabricated failed assistant turn appended to
        // somebody's conversation, with nothing thrown for the caller to notice. Upstream checks
        // here, before the loop, and the conversation is untouched.
        $agent = $this->agent([]);

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $agent->continue()),
            'No messages to continue from',
        );

        $this->assertSame([], $agent->state->messages);
        $this->assertNull($agent->state->error);
    }

    public function testContinuingFromAnAnswerIsRefusedAndLeavesTheConversationAlone(): void
    {
        $agent = $this->agent([]);
        $agent->appendMessage(new UserMessage('hi'));
        $agent->appendMessage(new AssistantMessage(
            [new TextContent('there')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        ));

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $agent->continue()),
            'Cannot continue from an assistant message',
        );

        // Two, not three: the refusal is not a turn.
        $this->assertCount(2, $agent->state->messages);
        $this->assertNull($agent->state->error);
    }

    public function testTheInitialPromptAndToolsBecomeTheLeadingSystemMessage(): void
    {
        $tool = new class implements \Pig\Agent\AgentTool {
            public function definition(): \Pig\Ai\Tool
            {
                return new \Pig\Ai\Tool('read', 'Read', ['type' => 'object']);
            }

            public function label(): string
            {
                return 'Read';
            }

            public function execute(string $toolCallId, array $arguments, ?\Pig\Async\AbortSignal $signal = null, ?Closure $onUpdate = null): \Pig\Agent\AgentToolResult
            {
                return new \Pig\Agent\AgentToolResult([]);
            }
        };

        // Upstream's `createMutableAgentState()`: unless the messages already start with one.
        $state = new \Pig\Agent\AgentState('be brief', tools: [$tool]);

        $this->assertCount(1, $state->messages);
        $this->assertInstanceOf(SystemMessage::class, $state->messages[0]);
        $this->assertSame(0, $state->messages[0]->timestamp);
        $this->assertSame(['read'], array_map(static fn ($t) => $t->name, $state->messages[0]->toolsAdded ?? []));
        $this->assertSame('be brief', $state->systemPrompt());

        $kept = new SystemMessage('already');
        $this->assertSame([$kept], (new \Pig\Agent\AgentState('ignored', messages: [$kept]))->messages);
        $this->assertSame([], (new \Pig\Agent\AgentState())->messages, 'neither prompt nor tools is no message');
    }

    public function testATranscriptOfOnlySystemMessagesHasNothingToContinueFrom(): void
    {
        $agent = $this->agent([]);
        $agent->replaceMessages([new SystemMessage('be brief')]);

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $agent->continue()),
            'No messages to continue from',
        );
    }

    public function testTheDefaultConversionSendsTheSystemMessages(): void
    {
        $agent = $this->agent(['ok']);
        $agent->replaceMessages([new SystemMessage('be brief')]);

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertInstanceOf(SystemMessage::class, $this->contexts[0]->messages[0]);
        $this->assertSame('be brief', Transcript::getCurrentSystemPrompt($this->contexts[0]->messages));
    }

    public function testPrepareNextTurnWithContextIsAskedBetweenTurnsWithTheRunsSignal(): void
    {
        $asked = [];
        $agent = $this->agent(['first', 'second'], $this->queuesOneFollowUpOnce(), [
            'prepareNextTurnWithContext' => static function (\Pig\Agent\PrepareNextTurnContext $turn, ?\Pig\Async\AbortSignal $signal) use (&$asked): \Pig\Agent\AgentLoopTurnUpdate {
                $asked[] = [$turn->message->content[0]->text ?? null, $signal !== null];

                return new \Pig\Agent\AgentLoopTurnUpdate(messages: [new SystemMessage('', ['note' => 'between turns'])]);
            },
        ]);

        Async::run(static fn () => $agent->prompt('hi'));

        $this->assertSame([['first', true]], $asked);
        $this->assertSame(['note' => 'between turns'], Transcript::getCurrentSystemMessage($this->contexts[1]->messages)?->sections);
    }

    public function testResetForgetsTheConversationButKeepsTheSetup(): void
    {
        $agent = $this->agent(['ok']);
        $agent->replaceMessages([new SystemMessage('be brief')]);

        Async::run(static fn () => $agent->prompt('hi'));
        $agent->followUp(new UserMessage('later'));
        $agent->reset();

        // Upstream's `reset()` keeps "the replayed prompt/tool baseline": the one system message
        // `getCurrentSystemMessage()` replays from the transcript.
        $this->assertCount(1, $agent->state->messages);
        $this->assertInstanceOf(SystemMessage::class, $agent->state->messages[0]);
        $this->assertSame('be brief', $agent->state->systemPrompt());
        $this->assertNotNull($agent->state->model);
    }

    public function testWaitForIdleResolvesImmediatelyWhenNothingIsRunning(): void
    {
        $agent = $this->agent([]);

        $this->assertTrue(Async::run(static fn (): bool => $agent->waitForIdle()->isComplete()));
    }

    /**
     * An agent wired to a scripted provider.
     *
     * @param list<string>              $answers one per model call, in order
     * @param Closure(Agent): void|null $hook    run at the top of every model call
     * @param array<string, mixed>      $options extra AgentOptions
     */
    private function agent(array $answers, ?Closure $hook = null, array $options = []): Agent
    {
        $agent = new Agent(new AgentOptions(...[
            ...$options,
            'streamFn' => $this->provider($answers, $hook),
            'apiKey' => 'test-key',
        ]));

        $agent->setModel($this->model());
        $this->current = $agent;

        return $agent;
    }

    /**
     * A provider that answers from a script, running $hook first so a test can act mid-run.
     *
     * @param list<string>              $answers
     * @param Closure(Agent): void|null $hook
     */
    private function provider(array $answers, ?Closure $hook): Closure
    {
        $index = 0;

        return function (Model $model, TranscriptContext $context, SimpleStreamOptions $options) use (
            $answers,
            $hook,
            &$index,
        ): AssistantMessageEventStream {
            $this->contexts[] = $context;
            $this->options[] = $options;

            if ($hook !== null && $this->current !== null) {
                $hook($this->current);
            }

            return $this->replay($answers[$index++] ?? throw new RuntimeException('out of scripted answers'));
        };
    }

    /** @return Closure(Agent): void */
    private function queuesOneFollowUpOnce(): Closure
    {
        $queued = false;

        return static function (Agent $agent) use (&$queued): void {
            if ($queued) {
                return;
            }

            $queued = true;
            $agent->followUp(new UserMessage('and this'));
        };
    }

    /** @return Closure(Agent): void */
    private function queuesTwoFollowUpsOnce(): Closure
    {
        $queued = false;

        return static function (Agent $agent) use (&$queued): void {
            if ($queued) {
                return;
            }

            $queued = true;
            $agent->followUp(new UserMessage('and this'));
            $agent->followUp(new UserMessage('and this too'));
        };
    }

    /** @return list<string> */
    private function texts(Agent $agent): array
    {
        return array_map(static fn (mixed $message): string => $message->content[0]->text, $agent->state->messages);
    }

    /** A one-shot stream that replays a single text answer. */
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
