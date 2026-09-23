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
use Pig\Ai\ErrorEvent;
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
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\AutoCompactionStartEvent;
use Pig\CodingAgent\Session\RetryEndEvent;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
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
    // ---- picking a failed turn back up ------------------------------------------------

    public function testA503IsWaitedOutAndTheTurnIsSentAgain(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 503: overloaded'], 'here you go']),
            settings: self::quickRetries(),
        );

        $seen = [];
        $session->subscribe(static function (AgentEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        $starts = array_values(array_filter($seen, static fn ($e) => $e instanceof RetryStartEvent));
        $ends = array_values(array_filter($seen, static fn ($e) => $e instanceof RetryEndEvent));

        $this->assertCount(1, $starts);
        $this->assertSame(1, $starts[0]->attempt);
        $this->assertStringContainsString('503', $starts[0]->error);

        $this->assertCount(1, $ends);
        $this->assertTrue($ends[0]->succeeded);

        // The answer it was retrying for is the last thing in the conversation, and the
        // failure is not in front of it.
        $messages = $session->messages();
        $this->assertSame('here you go', self::textOf($messages[count($messages) - 1]));
    }

    public function testTheFailedTurnIsNotLeftInTheConversationForTheModelToRead(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 503: overloaded'], 'here you go']),
            settings: self::quickRetries(),
        );

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        // Two: the question and the answer. "Anthropic returned 503" is not part of the
        // conversation, and a model shown it would try to make sense of it.
        $this->assertCount(2, $session->messages());

        foreach ($session->messages() as $message) {
            $this->assertStringNotContainsString('503', self::textOf($message));
        }
    }

    public function testItGivesUpAfterTheAttemptsRunOut(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 6, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['maxAttempts' => 2]),
        );

        $ends = [];
        $session->subscribe(static function (AgentEvent $event) use (&$ends): void {
            if ($event instanceof RetryEndEvent) {
                $ends[] = $event;
            }
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        $this->assertCount(1, $ends);
        $this->assertFalse($ends[0]->succeeded);
        $this->assertSame(2, $ends[0]->attempts);
        $this->assertStringContainsString('503', $ends[0]->error ?? '');
        $this->assertFalse($session->isRetrying());
    }

    public function testTheWaitsDouble(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 6, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['maxAttempts' => 3, 'baseDelayMs' => 2]),
        );

        $delays = [];
        $session->subscribe(static function (AgentEvent $event) use (&$delays): void {
            if ($event instanceof RetryStartEvent) {
                $delays[] = $event->delaySeconds;
            }
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        // The point of backing off: a fixed delay brings the whole crowd back at once and
        // the provider that was overloaded is overloaded again.
        $this->assertSame([0.002, 0.004, 0.008], $delays);
    }

    public function testSomethingNotWorthRetryingIsNotRetried(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 401: invalid api key']]),
            settings: self::quickRetries(),
        );

        $seen = [];
        $session->subscribe(static function (AgentEvent $event) use (&$seen): void {
            $seen[] = $event::class;
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        // A bad key is a bad key in four seconds too.
        $this->assertNotContains(RetryStartEvent::class, $seen);
    }

    public function testRetryingCanBeTurnedOff(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 503: overloaded']]),
            settings: Settings::inMemory(['retry' => ['enabled' => false]]),
        );

        $seen = [];
        $session->subscribe(static function (AgentEvent $event) use (&$seen): void {
            $seen[] = $event::class;
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        $this->assertNotContains(RetryStartEvent::class, $seen);
    }

    public function testRetryingIsOnWhenNobodySaid(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 503: overloaded'], 'here you go']),
            settings: self::quickRetries(),
        );

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        // Nothing in the settings turned it on. Someone who never asked for auto-retry still
        // did not ask to lose a turn because the provider was busy for two seconds.
        $this->assertSame('here you go', self::textOf($session->messages()[1]));
    }

    public function testAbortingStopsTheWaiting(): void
    {
        $session = $this->session(
            [],
            // Half a minute, so the sleep cannot finish on its own while the test works.
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['baseDelayMs' => 30_000]),
        );

        $ends = [];
        $session->subscribe(static function (AgentEvent $event) use (&$ends): void {
            if ($event instanceof RetryEndEvent) {
                $ends[] = $event;
            }
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });

        // One tick to start the spawned retry and park it on its timer, without waiting the
        // timer out — see `tickWithoutWaiting()`.
        self::tickWithoutWaiting();

        $this->assertTrue($session->isRetrying());

        $session->abortRetry();
        self::settle();

        $this->assertCount(1, $ends);
        $this->assertFalse($ends[0]->succeeded);
        $this->assertStringContainsString('cancelled', $ends[0]->error ?? '');
        $this->assertFalse($session->isRetrying());
    }

    public function testAbortingTheSessionStopsTheWaitingToo(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['baseDelayMs' => 30_000]),
        );

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::tickWithoutWaiting();

        $this->assertTrue($session->isRetrying());

        // Escape means stop, and a retry that is sleeping has no agent to interrupt: the run
        // is already over and the next one has not started. `abort()` has to reach both.
        Async::run(static function () use ($session): void {
            $session->abort()->await();
        });
        self::settle();

        $this->assertFalse($session->isRetrying());
    }

    public function testAbortingWhenNothingIsBeingRetriedIsHarmless(): void
    {
        $session = $this->session(['hello']);

        $session->abortRetry();

        $this->assertFalse($session->isRetrying());
    }

    // ---- the overflow half --------------------------------------------------------------

    public function testAPromptTooLongIsSummarisedAndSentAgainRatherThanRetried(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([
                'first answer',
                'second answer',
                ['error' => 'prompt is too long: 213462 tokens > 200000 maximum'],
                'the summary',
                'answered after summarising',
            ]),
            // A cut has to be legal *and* worth making: `cutPoint()` works in tokens, and
            // four short messages are nowhere near the default budget, so nothing would be
            // cut and the summary would refuse as "too small".
            settings: self::quickRetries(['keepRecentTokens' => 1]),
        );

        $seen = [];
        $session->subscribe(static function (AgentEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        Async::run(static function () use ($session): void {
            $session->prompt('one');
            $session->prompt('two');
            $session->prompt('three');
        });
        self::settle();

        $starts = array_values(array_filter($seen, static fn ($e) => $e instanceof AutoCompactionStartEvent));
        $ends = array_values(array_filter($seen, static fn ($e) => $e instanceof AutoCompactionEndEvent));

        $this->assertCount(1, $starts);
        $this->assertStringContainsString('too long', $starts[0]->error);

        $this->assertCount(1, $ends);
        $this->assertTrue($ends[0]->succeeded);
        $this->assertTrue($ends[0]->willRetry, 'the summary is the fix, so the turn goes again');
        $this->assertInstanceOf(CompactionSummary::class, $ends[0]->summary);

        // Not retried: the request was too big, and it would be exactly as big in four
        // seconds. Nothing waited.
        $this->assertNotContains(
            RetryStartEvent::class,
            array_map(static fn ($e) => $e::class, $seen),
        );

        $messages = $session->messages();
        $this->assertSame('answered after summarising', self::textOf($messages[count($messages) - 1]));
    }

    public function testASummaryThatFailsEndsItRatherThanSendingTheSameThingAgain(): void
    {
        $session = $this->session(
            [],
            // The summariser's own turn fails too, which is what a provider that is still
            // refusing everything looks like.
            streamFn: $this->flaky([
                ['error' => 'prompt is too long: 213462 tokens > 200000 maximum'],
            ]),
            settings: self::quickRetries(),
        );

        $ends = [];
        $session->subscribe(static function (AgentEvent $event) use (&$ends): void {
            if ($event instanceof AutoCompactionEndEvent) {
                $ends[] = $event;
            }
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        $this->assertCount(1, $ends);
        $this->assertFalse($ends[0]->succeeded);
        $this->assertFalse($ends[0]->willRetry);
        $this->assertNotNull($ends[0]->error);
    }

    // ---- what the conversation was being had with -------------------------------------

    public function testSwitchingModelIsWrittenDown(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store);

        Async::run(static fn () => $session->prompt('hi'));
        $session->setModel(new Model('other-model', 'Other', Api::AnthropicMessages, 'openai', 'http://127.0.0.1:1', 1_000, 100));

        $this->assertSame('other-model', SessionManager::open($store->path)->settings()['model']?->modelId);
        $this->assertSame('openai', SessionManager::open($store->path)->settings()['model']?->provider);
    }

    public function testSettingTheSameModelAgainWritesNothing(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store);

        Async::run(static fn () => $session->prompt('hi'));
        $before = substr_count((string) file_get_contents($store->path), "\n");

        $session->setModel($this->model());
        $session->setModel($this->model());

        // `setModel()` is also how the thinking level gets clamped, so a line per call would
        // be a file full of a model changing to itself.
        $this->assertSame($before, substr_count((string) file_get_contents($store->path), "\n"));
    }

    public function testResumingComesBackToTheModelTheConversationWasOn(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $first = $this->session(['hello'], store: $store);

        Async::run(static fn () => $first->prompt('hi'));

        // A real model from the registry, not an invented one: restoring means looking the
        // recorded provider and id back up, and a made-up id is not in there to find. Which
        // is itself a thing to know — see the test below about a model this machine lacks.
        $recorded = Models::find('anthropic', 'claude-haiku-4-5');
        self::assertNotNull($recorded);

        $first->setModel($recorded);
        $first->setThinkingLevel(ThinkingLevel::High);

        // A second session, as `--continue` builds one: the default model, then the file.
        $reopened = SessionManager::open($store->path);
        $second = $this->session([], store: $reopened);
        $second->restore($reopened->messages());
        $second->restoreSettings();

        // The whole point of "carry on where I left off": before this, an afternoon on one
        // model came back on whatever the settings said.
        $this->assertSame('claude-haiku-4-5', $second->model()?->id);
        $this->assertSame(ThinkingLevel::High, $second->thinkingLevel());
    }

    public function testAModelNamedOnTheCommandLineBeatsTheFile(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $first = $this->session(['hello'], store: $store);

        Async::run(static fn () => $first->prompt('hi'));

        $recorded = Models::find('anthropic', 'claude-haiku-4-5');
        self::assertNotNull($recorded);
        $first->setModel($recorded);

        $reopened = SessionManager::open($store->path);
        $second = $this->session([], store: $reopened);
        $second->restore($reopened->messages());
        $second->restoreSettings(modelWasAskedFor: true);

        // `--model` is someone saying what they want now; the file says what was true last
        // time. The thinking level still comes from the file — nothing overrode that.
        $this->assertSame('test-model', $second->model()?->id);
    }

    public function testALevelTheRestoredModelCannotDoIsClampedRatherThanSent(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $first = $this->session(['hello'], store: $store, model: $this->thinkingModel());

        Async::run(static fn () => $first->prompt('hi'));
        $first->setThinkingLevel(ThinkingLevel::High);
        $first->setModel($this->model());

        $reopened = SessionManager::open($store->path);
        $second = $this->session([], store: $reopened, model: $this->model());
        $second->restore($reopened->messages());
        $second->restoreSettings();

        // `test-model` cannot reason. Asking it to think hard is a request the provider
        // rejects, and the person resuming did not ask for it — the file did.
        $this->assertSame(ThinkingLevel::Off, $second->thinkingLevel());
    }

    public function testAFileNamingAModelThisMachineDoesNotHaveStillOpens(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store);

        Async::run(static fn () => $session->prompt('hi'));
        $store->appendModelChange('some-vendor', 'a-model-nobody-here-has');

        $reopened = SessionManager::open($store->path);
        $second = $this->session([], store: $reopened);
        $second->restore($reopened->messages());
        $second->restoreSettings();

        // Not a reason to refuse to open the conversation: it falls back to what it had,
        // and the footer says which model is answering.
        $this->assertSame('test-model', $second->model()?->id);
        $this->assertCount(2, $second->messages());
    }

    private function session(
        array $answers,
        ?Closure $hook = null,
        ?Model $model = null,
        ?Closure $streamFn = null,
        ?SessionManager $store = null,
        ?Settings $settings = null,
    ): AgentSession {
        $agent = new Agent(new AgentOptions(
            streamFn: $streamFn ?? $this->provider($answers, $hook),
            apiKey: 'test-key',
        ));

        $agent->setModel($model ?? $this->model());
        $this->current = $agent;

        return new AgentSession($agent, sys_get_temp_dir(), $store, $settings);
    }

    /**
     * A provider that fails for a while, then answers.
     *
     * Each turn takes the next entry: a string is an answer, anything else is the error a
     * failed turn reports. Written as a list rather than a counter so a test reads as the
     * sequence the provider actually produces.
     *
     * @param list<string|array{error: string}> $turns
     */
    private function flaky(array $turns): Closure
    {
        $at = 0;

        return function () use ($turns, &$at): AssistantMessageEventStream {
            $turn = $turns[$at++] ?? throw new RuntimeException('out of scripted turns');

            return is_string($turn) ? $this->replay($turn) : $this->fails($turn['error']);
        };
    }

    /** A turn that comes back as an error, the way a provider's 503 does. */
    private function fails(string $error): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $message = new AssistantMessage(
            [new TextContent('')],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Error,
            $error,
        );

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push(new ErrorEvent(StopReason::Error, $message));
            $stream->end();
        });

        return $stream;
    }

    /** Settings with the waits short enough that a test is not mostly sleeping. */
    private static function quickRetries(array $extra = []): Settings
    {
        $compaction = [];

        foreach (['keepRecentTokens', 'reserveTokens'] as $key) {
            if (isset($extra[$key])) {
                $compaction[$key] = $extra[$key];
                unset($extra[$key]);
            }
        }

        return Settings::inMemory([
            'retry' => ['baseDelayMs' => 1, ...$extra],
            'compaction' => $compaction,
        ]);
    }

    /** Turn the loop until nothing is left, so a spawned retry gets to happen. */
    private static function settle(int $ticks = 200): void
    {
        for ($tick = 0; $tick < $ticks && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    /**
     * One tick that does not wait out whatever timer is pending.
     *
     * A plain `tick()` with a retry's timer armed and no streams to watch calls `usleep()`
     * for the whole delay — so two ticks really are two seconds, the sleep is over before
     * the test gets control back, and there is nothing left to abort. An expired timer of
     * our own makes `pollTimeout()` ~0, so the tick returns with the retry still parked.
     */
    private static function tickWithoutWaiting(): void
    {
        Loop::get()->delay(0.0, static fn () => null);
        Loop::get()->tick();
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
