<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentState;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
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

    /** @var list<Context> every context the provider was called with */
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

        $agent->setSystemPrompt('be brief');
        $agent->setThinkingLevel(ThinkingLevel::High);
        $agent->setTools([$tool]);
        $agent->replaceMessages([new UserMessage('one')]);
        $agent->appendMessage(new UserMessage('two'));

        $this->assertSame('be brief', $agent->state->systemPrompt);
        $this->assertSame(ThinkingLevel::High, $agent->state->thinkingLevel);
        $this->assertSame([$tool], $agent->state->tools);
        $this->assertCount(2, $agent->state->messages);

        $agent->clearMessages();

        $this->assertSame([], $agent->state->messages);
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

        // "Barely think" is sent as low: no provider does anything useful with less.
        $this->assertSame(ReasoningEffort::Low, $this->options[0]->reasoning);
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

    public function testResetForgetsTheConversationButKeepsTheSetup(): void
    {
        $agent = $this->agent(['ok']);
        $agent->setSystemPrompt('be brief');

        Async::run(static fn () => $agent->prompt('hi'));
        $agent->followUp(new UserMessage('later'));
        $agent->reset();

        $this->assertSame([], $agent->state->messages);
        $this->assertSame('be brief', $agent->state->systemPrompt);
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

        return function (Model $model, Context $context, SimpleStreamOptions $options) use (
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
