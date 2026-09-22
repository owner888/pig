<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Cost;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Test\AssertsThrows;
use Throwable;
use RuntimeException;

/**
 * The layer the interactive mode talks to instead of the agent.
 *
 * Nothing here reaches a provider: the agent is wired to a scripted one, the same way
 * `AgentTest` does it, so what is under test is the session's own bookkeeping.
 */
final class AgentSessionTest extends TestCase
{
    use AssertsThrows;

    private ?Agent $current = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->current = null;
    }

    // ---- events ------------------------------------------------------------------

    public function testListenersSeeWhatTheAgentEmits(): void
    {
        $session = $this->session(['hello']);
        $seen = [];

        $session->subscribe(static function (AgentEvent $event) use (&$seen): void {
            $seen[] = $event::class;
        });

        Async::run(static fn () => $session->prompt('hi'));

        $this->assertContains(MessageStartEvent::class, $seen);
    }

    public function testUnsubscribingStopsOneListenerAndNotTheOthers(): void
    {
        $session = $this->session(['hello']);
        $kept = $dropped = 0;

        $session->subscribe(static function () use (&$kept): void {
            $kept++;
        });

        $stop = $session->subscribe(static function () use (&$dropped): void {
            $dropped++;
        });

        $stop();
        Async::run(static fn () => $session->prompt('hi'));

        $this->assertGreaterThan(0, $kept);
        $this->assertSame(0, $dropped);
    }

    public function testDisposeLetsGoOfTheAgentToo(): void
    {
        $session = $this->session(['hello']);
        $seen = 0;

        $session->subscribe(static function () use (&$seen): void {
            $seen++;
        });

        $session->dispose();
        Async::run(static fn () => $session->agent->prompt('hi'));

        $this->assertSame(0, $seen);
    }

    // ---- prompting ---------------------------------------------------------------

    public function testPromptingWhileWorkingIsAnError(): void
    {
        // Mid-run is exactly when someone types, so this has to name the way out.
        $session = $this->session(['one'], function (Agent $agent) use (&$session): void {
            $this->assertThrows(
                AgentError::class,
                static fn () => $session->prompt('again'),
                'steer() or followUp()',
            );
        });

        Async::run(static fn () => $session->prompt('hi'));
    }

    public function testPromptingWithNoModelSaysSo(): void
    {
        $agent = new Agent(new AgentOptions(streamFn: $this->provider([], null), apiKey: 'test-key'));
        $session = new AgentSession($agent);

        $this->assertThrows(
            AgentError::class,
            static fn () => $session->prompt('hi'),
            'No model selected',
        );
    }

    // ---- the queue ---------------------------------------------------------------

    public function testQueuedMessagesAreListedSteeringFirst(): void
    {
        $session = $this->session(['one']);
        $session->followUp('later');
        $session->steer('now');

        $this->assertSame(['now', 'later'], $session->queued());
    }

    public function testAQueuedMessageLeavesTheQueueBeforeItsEventGoesOut(): void
    {
        $seen = null;
        $session = $this->session(['one', 'two'], static function (Agent $agent) use (&$session): void {
            if ($session->queued() === []) {
                $session->steer('and also this');
            }
        });

        $session->subscribe(static function (AgentEvent $event) use (&$seen, &$session): void {
            if ($event instanceof MessageStartEvent
                && $event->message instanceof UserMessage
                && $event->message->content[0]->text === 'and also this'
            ) {
                $seen = $session->queued();
            }
        });

        Async::run(static fn () => $session->prompt('hi'));

        // A footer redrawing on this event must not still be showing the message that
        // is being delivered as it fires.
        $this->assertSame([], $seen);
    }

    public function testClearingTheQueueHandsTheTextBack(): void
    {
        $session = $this->session(['one']);
        $session->steer('first');
        $session->followUp('second');

        // Handed back rather than dropped: it goes into the editor, so nobody loses
        // what they typed by pressing Escape.
        $this->assertSame(['first', 'second'], $session->clearQueue());
        $this->assertSame([], $session->queued());
    }

    // ---- running a command yourself ---------------------------------------------------

    public function testABangCommandJoinsTheConversationAndReachesTheModel(): void
    {
        $session = $this->session(['ok']);

        $execution = Async::run(static fn () => $session->executeBash('echo hi'));

        $this->assertSame('hi', trim($execution->output));
        $this->assertSame(0, $execution->exitCode);
        $this->assertSame([$execution], $session->messages());

        // The whole point of typing `!` is that the output reaches the model.
        $this->assertStringContainsString('Ran `echo hi`', $execution->toText());
        $this->assertStringContainsString('hi', $execution->toText());
    }

    public function testTwoBangsRunItWithoutJoiningTheConversation(): void
    {
        $session = $this->session([]);

        $execution = Async::run(static fn () => $session->executeBash('echo hi', remember: false));

        $this->assertSame('hi', trim($execution->output));
        $this->assertSame([], $session->messages());
    }

    public function testAFailingCommandCarriesItsExitCode(): void
    {
        $session = $this->session([]);

        $execution = Async::run(static fn () => $session->executeBash('echo bad >&2; exit 7'));

        $this->assertSame(7, $execution->exitCode);
        $this->assertStringContainsString('bad', $execution->output);
        $this->assertStringContainsString('Command exited with code 7', $execution->toText());
    }

    public function testOutputArrivesWhileItIsStillRunning(): void
    {
        $session = $this->session([]);
        $seen = [];

        Async::run(static function () use ($session, &$seen): void {
            $session->executeBash('echo one; sleep 0.1; echo two', onOutput: static function (string $output) use (&$seen): void {
                $seen[] = $output;
            });
        });

        // Not one lump at the end: a command that takes a while has to show something
        // while it takes it.
        $this->assertNotSame([], $seen);
        $this->assertStringContainsString('one', $seen[0]);
    }

    public function testASecondCommandIsRefusedWhileOneIsRunning(): void
    {
        $session = $this->session([]);

        Async::run(function () use ($session): void {
            Async::spawn(static fn () => $session->executeBash('sleep 0.3'));
            Async::delay(0.05);

            $this->assertTrue($session->isBashRunning());
            $this->assertThrows(
                AgentError::class,
                static fn () => $session->executeBash('echo second'),
                'already running',
            );

            $session->abortBash();
        });
    }

    public function testACommandRunDuringATurnWaitsForTheTurnToEnd(): void
    {
        // A message added between a tool call and its result is a request the provider
        // rejects outright, so anything that arrives mid-run waits for the run to end.
        $during = null;
        $session = null;
        $session = $this->session(['answer'], static function (Agent $agent) use (&$session, &$during): void {
            if ($during !== null) {
                return;
            }

            $session->executeBash('echo mid-run');
            $during = count($session->messages());
        });

        Async::run(static fn () => $session->prompt('hi'));

        $this->assertSame(1, $during, 'held back while the agent was working');
        $this->assertCount(3, $session->messages());
        $this->assertInstanceOf(BashExecution::class, $session->messages()[2]);
    }

    // ---- making room -------------------------------------------------------------

    public function testAShortConversationSaysThereIsNothingToCompact(): void
    {
        $session = $this->session(['answer']);
        Async::run(static fn () => $session->prompt('hi'));

        $before = $session->messages();

        // Nothing is old enough to be worth dropping. Said rather than silently skipped:
        // someone who typed `/compact` and saw nothing happen goes looking for a bug.
        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $session->compact()),
            'Nothing to compact (session too small)',
        );
        $this->assertSame($before, $session->messages());
    }

    public function testCompactingDoesNotLeaveTheSessionAskingToCompactAgain(): void
    {
        $session = $this->session(['the summary']);

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        // The last turn reported 190k of a 200k window, which is what asks for a
        // compaction in the first place.
        $session->agent->appendMessage(new AssistantMessage(
            [new TextContent('so far so good')],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(0, 0, 0, 0, 190_000),
            StopReason::Stop,
            null,
            Timestamp::nowMs() - 1_000,
        ));

        $this->assertTrue($session->shouldCompact());

        Async::run(static fn () => $session->compact());

        // That reading described a request that no longer exists. Trusting it after the
        // compaction means compacting again before every turn, for ever.
        $this->assertFalse($session->shouldCompact());
    }

    public function testCompactingWhatIsAlreadyASummarySaysSo(): void
    {
        $session = $this->session([]);
        $session->agent->appendMessage(new CompactionSummary('already summarised'));

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $session->compact()),
            'Already compacted',
        );
    }

    public function testTheOlderHalfIsReplacedByASummaryAndTheRecentHalfIsKept(): void
    {
        $session = $this->session(['the summary']);
        $long = str_repeat('x', 40_000);

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage($long));
        }

        $session->agent->appendMessage(new UserMessage('the last thing I said'));

        $summary = Async::run(static fn () => $session->compact());
        $messages = $session->messages();

        $this->assertInstanceOf(CompactionSummary::class, $summary);
        $this->assertSame('the summary', $summary->summary);
        $this->assertSame($summary, $messages[0]);
        $this->assertSame('the last thing I said', self::textOf($messages[count($messages) - 1]));
        $this->assertGreaterThan(0, $summary->replaced);
    }

    public function testASummaryReachesTheModelAsSomethingItCanRead(): void
    {
        $session = $this->session(['answer']);
        $session->agent->appendMessage(new CompactionSummary('what happened earlier', ['a.php']));

        // The agent's own converter keeps the three LLM message types and drops the rest;
        // a summary dropped there would compact the conversation into nothing at all.
        $converted = CodingAgent::toLlm($session->messages());

        $this->assertInstanceOf(UserMessage::class, $converted[0]);
        $this->assertStringContainsString('what happened earlier', self::textOf($converted[0]));
        $this->assertStringContainsString('a.php', self::textOf($converted[0]));
    }

    public function testASavedSessionResumesTheWayCompactionLeftIt(): void
    {
        $path = sys_get_temp_dir() . '/pig-compaction-' . bin2hex(random_bytes(4)) . '.jsonl';
        $store = SessionManager::create(sys_get_temp_dir(), $path);
        $session = $this->session(['an answer', 'the summary'], null, null, null, $store);

        Async::run(static fn () => $session->prompt('hi'));

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
            $store->append($session->messages()[count($session->messages()) - 1]);
        }

        Async::run(static fn () => $session->compact());

        try {
            $reopened = SessionManager::open($path)->messages();

            // The point of writing the summary down: the conversation that comes back is
            // the compacted one, not the one that was compacted away.
            $this->assertSame(count($session->messages()), count($reopened));
            $this->assertInstanceOf(CompactionSummary::class, $reopened[0]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCompactingWhileTheAgentIsWorkingIsRefusedRatherThanQueued(): void
    {
        // Replacing the conversation from under a running turn is how a tool result ends
        // up with no call in front of it.
        $thrown = null;
        $session = null;
        $session = $this->session(['answer'], static function (Agent $agent) use (&$session, &$thrown): void {
            try {
                $session->compact();
            } catch (Throwable $error) {
                $thrown = $error;
            }
        });

        Async::run(static fn () => $session->prompt('hi'));

        $this->assertInstanceOf(AgentError::class, $thrown);
    }

    public function testCancellingTheSummariserLeavesTheConversationAlone(): void
    {
        $session = $this->session([], null, null, static function (Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream {
            $stream = new AssistantMessageEventStream();
            $cancelled = new AssistantMessage(
                [],
                Api::AnthropicMessages,
                'anthropic',
                'test-model',
                new Usage(),
                StopReason::Aborted,
            );

            Async::spawn(static function () use ($stream, $cancelled): void {
                $stream->push(new DoneEvent(StopReason::Aborted, $cancelled));
                $stream->end();
            });

            return $stream;
        });

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $before = $session->messages();

        // Pressing escape halfway through is not a reason to lose the conversation.
        $this->assertNull(Async::run(static fn () => $session->compact()));
        $this->assertSame($before, $session->messages());
    }

    public function testASummariserThatFailsSaysSoRatherThanCompactingIntoNothing(): void
    {
        $session = $this->session([], null, null, static function (Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream {
            $stream = new AssistantMessageEventStream();
            $failed = new AssistantMessage(
                [],
                Api::AnthropicMessages,
                'anthropic',
                'test-model',
                new Usage(),
                StopReason::Error,
                'overloaded_error',
            );

            Async::spawn(static function () use ($stream, $failed): void {
                $stream->push(new DoneEvent(StopReason::Error, $failed));
                $stream->end();
            });

            return $stream;
        });

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $this->assertThrows(
            AgentError::class,
            static fn () => Async::run(static fn () => $session->compact()),
            'overloaded_error',
        );
    }

    public function testWhatTheSummariserIsAskedIsTheConversationAndNotTheTools(): void
    {
        $asked = null;
        $session = $this->session([], null, null, function (Model $model, Context $context, SimpleStreamOptions $options) use (&$asked): AssistantMessageEventStream {
            $asked = $context;

            return $this->replay('the summary');
        });

        foreach (range(1, 6) as $ignored) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        Async::run(static fn () => $session->compact('the parser bug'));

        $this->assertInstanceOf(Context::class, $asked);
        $this->assertCount(1, $asked->messages);
        $this->assertSame([], $asked->tools, 'a summariser with tools is an agent, not a summariser');
        $this->assertStringContainsString('summarization assistant', (string) $asked->systemPrompt);
        $this->assertStringContainsString('Additional focus: the parser bug', self::textOf($asked->messages[0]));
    }

    // ---- switching models ------------------------------------------------------------

    public function testSwitchingToAModelThatCannotThinkDropsTheThinkingLevel(): void
    {
        $session = $this->session([], null, $this->thinkingModel());
        $session->setThinkingLevel(ThinkingLevel::High);

        $session->setModel(Models::get('claude-3-haiku-20240307') ?? throw new RuntimeException('no model'));

        // Left at `high`, the next turn asks a model without reasoning to reason, and the
        // person who changed model would have no idea why the request failed.
        $this->assertSame(ThinkingLevel::Off, $session->thinkingLevel());
    }

    public function testSwitchingBetweenThinkingModelsKeepsTheLevel(): void
    {
        $session = $this->session([], null, $this->thinkingModel());
        $session->setThinkingLevel(ThinkingLevel::Medium);

        $session->setModel(Models::get('claude-sonnet-4-5') ?? throw new RuntimeException('no model'));

        $this->assertSame(ThinkingLevel::Medium, $session->thinkingLevel());
        $this->assertSame('claude-sonnet-4-5', $session->model()?->id);
    }

    public function testAskingForXhighOnAModelWithoutItFallsToOffRatherThanPretending(): void
    {
        $session = $this->session([]);

        $session->setModel(
            Models::get('claude-sonnet-4-5') ?? throw new RuntimeException('no model'),
            ThinkingLevel::Xhigh,
        );

        // Anthropic has no xhigh. Silently sending `high` instead would be answering a
        // different question from the one asked.
        $this->assertSame(ThinkingLevel::Off, $session->thinkingLevel());
    }

    // ---- thinking ----------------------------------------------------------------

    public function testAModelThatCannotThinkOffersNoLevels(): void
    {
        $session = $this->session(['one']);

        $this->assertSame([], $session->availableThinkingLevels());
        $this->assertNull($session->cycleThinkingLevel());
    }

    public function testCyclingWrapsRoundAtTheTop(): void
    {
        $session = $this->session(['one'], null, $this->thinkingModel());

        $this->assertSame(ThinkingLevel::Minimal, $session->cycleThinkingLevel());
        $session->setThinkingLevel(ThinkingLevel::High);
        $this->assertSame(ThinkingLevel::Off, $session->cycleThinkingLevel());
    }

    public function testXhighIsOnlyOfferedByModelsThatTakeIt(): void
    {
        $ordinary = $this->session(['one'], null, $this->thinkingModel());
        $this->assertNotContains(ThinkingLevel::Xhigh, $ordinary->availableThinkingLevels());

        $capable = $this->session(['one'], null, $this->thinkingModel('gpt-5.2'));
        $this->assertContains(ThinkingLevel::Xhigh, $capable->availableThinkingLevels());
    }

    public function testALevelTheModelDoesNotOfferCyclesBackToTheStart(): void
    {
        $session = $this->session(['one'], null, $this->thinkingModel());
        $session->setThinkingLevel(ThinkingLevel::Xhigh);

        // Not a position in this model's list, so there is no "next" to guess at.
        $this->assertSame(ThinkingLevel::Off, $session->cycleThinkingLevel());
    }

    // ---- what it has cost ----------------------------------------------------------

    public function testStatsCountMessagesToolCallsAndTokens(): void
    {
        $session = $this->session(['one']);
        Async::run(static fn () => $session->prompt('hi'));

        $session->agent->appendMessage(new AssistantMessage(
            [new TextContent('and'), new ToolCall('call-1', 'read', [])],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(10, 20, 30, 40, 100, new Cost(total: 0.5)),
            StopReason::Stop,
        ));

        $stats = $session->stats();

        $this->assertSame(1, $stats->userMessages);
        $this->assertSame(2, $stats->assistantMessages);
        $this->assertSame(1, $stats->toolCalls);
        $this->assertSame(10, $stats->input);
        $this->assertSame(100, $stats->totalTokens());
        $this->assertSame(0.5, $stats->cost);
    }

    public function testTheLastAssistantTextIsWhatCopyWouldTake(): void
    {
        $session = $this->session(['the answer']);
        Async::run(static fn () => $session->prompt('hi'));

        $this->assertSame('the answer', $session->lastAssistantText());
    }

    public function testAnEmptyAbortedMessageIsSkippedInFavourOfTheRealOne(): void
    {
        $session = $this->session(['the answer']);
        Async::run(static fn () => $session->prompt('hi'));

        $session->agent->appendMessage(new AssistantMessage(
            [],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Aborted,
        ));

        // Interrupting and then copying means the answer before the interruption.
        $this->assertSame('the answer', $session->lastAssistantText());
    }

    public function testNoAssistantMessageAtAllIsNull(): void
    {
        $this->assertNull($this->session([])->lastAssistantText());
    }

    // ---- scaffolding ---------------------------------------------------------------

    /**
     * @param list<string>              $answers one per model call, in order
     * @param Closure(Agent): void|null $hook    run at the top of every model call
     */
    private function session(
        array $answers,
        ?Closure $hook = null,
        ?Model $model = null,
        ?Closure $streamFn = null,
        ?SessionManager $store = null,
    ): AgentSession {
        $agent = new Agent(new AgentOptions(
            streamFn: $streamFn ?? $this->provider($answers, $hook),
            apiKey: 'test-key',
        ));

        $agent->setModel($model ?? $this->model());
        $this->current = $agent;

        return new AgentSession($agent, sys_get_temp_dir(), $store);
    }

    /**
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
            if ($hook !== null && $this->current !== null) {
                $hook($this->current);
            }

            return $this->replay($answers[$index++] ?? throw new RuntimeException('out of scripted answers'));
        };
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

    private static function textOf(mixed $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    private function thinkingModel(string $id = 'test-thinker'): Model
    {
        return new Model($id, 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, true);
    }
}
