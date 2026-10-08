<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\MessageEndEvent;
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
use Pig\CodingAgent\Auth;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Hooks\Events\BeforeRetryEvent;
use Pig\CodingAgent\Hooks\Results\BeforeRetryResult;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;
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
    private string $tempHome = '';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->current = null;
        $this->tempHome = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));
        putenv("PIG_HOME={$this->tempHome}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        if (is_dir($this->tempHome)) {
            self::removeDir($this->tempHome);
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }

    // ---- the turn's signal -------------------------------------------------------

    public function testThereIsASignalDuringATurnAndNoneBetweenThem(): void
    {
        $session = $this->session(['hello']);
        $during = null;

        $this->assertNull($session->signal(), 'idle');

        $session->subscribe(static function (AgentEvent $event) use ($session, &$during): void {
            if ($event instanceof MessageStartEvent) {
                $during = $session->signal();
            }
        });

        Async::run(static fn () => $session->prompt('hi'));

        // What a hook's own waiting parks on, so escape reaches a command it started. Null
        // afterwards, and that half matters as much: handing back the last turn's signal would
        // mean an already-aborted one for everything asking after an interrupted turn.
        $this->assertNotNull($during);
        $this->assertFalse($during->aborted());
        $this->assertNull($session->signal(), 'between turns');
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

        // Cleared rather than never set: an `AgentState` fills in a default model, so the only way
        // to have none is to have lost one — the registry no longer carrying the default, which is
        // what `Models::find()` answering null means.
        $agent->state->model = null;
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
        $steered = false;

        // Once, by a flag: the agent's listeners now run before its loop goes on, so the queue is
        // already empty again when the model is asked for the second answer — and steering
        // whenever it is empty steered on every turn, for ever.
        $session = $this->session(['one', 'two'], static function (Agent $agent) use (&$session, &$steered): void {
            if (!$steered) {
                $steered = true;
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

    public function testWhatACommandPrintedIsCleanedAtTheSourceAndNotOnlyOnScreen(): void
    {
        $session = $this->session([]);

        // `npm`, `cargo` and `docker` colour their output whether or not anybody is looking, and
        // anything with a progress line writes `\r` to redraw it. Upstream cleans this in
        // `bash-executor.ts` — "sanitize once at the source" — so what reaches the model, the
        // session file and the screen is the same clean text. pig cleaned it at the display
        // boundary only, so the model and the file got the escapes.
        $execution = Async::run(
            static fn () => $session->executeBash("printf 'a\\033[32mb\\033[0m\\rc\\n'"),
        );

        $this->assertSame("abc\n", $execution->output);
        // And it is the text the model reads, which is the half a display-layer fix cannot reach.
        $this->assertStringContainsString('abc', $execution->toText());
        $this->assertStringNotContainsString("\033", $execution->toText());
        $this->assertStringNotContainsString("\r", $execution->toText());
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

        $session->setModel($this->plainModel());

        // Left at `high`, the next turn asks a model without reasoning to reason, and the
        // person who changed model would have no idea why the request failed.
        $this->assertSame(ThinkingLevel::Off, $session->thinkingLevel());
    }

    public function testSwitchingBetweenThinkingModelsKeepsTheLevel(): void
    {
        $session = $this->session([], null, $this->thinkingModel());
        $session->setThinkingLevel(ThinkingLevel::Medium);

        $session->setModel($this->thinkingModel('test-thinker-2'));

        $this->assertSame(ThinkingLevel::Medium, $session->thinkingLevel());
        $this->assertSame('test-thinker-2', $session->model()?->id);
    }

    public function testAskingForALevelAModelLacksLandsOnTheNearestItHas(): void
    {
        $session = $this->session([]);

        $session->setModel($this->thinkingModel(), ThinkingLevel::Xhigh);

        // **This reverses a decision this file used to record.** The old rule was `off`, on the
        // grounds that sending `high` for `xhigh` answers a different question from the one
        // asked. That reads well for xhigh and falls apart everywhere else: once a
        // `thinkingLevelMap` can take *any* level away, the same rule turns "medium, please" on a
        // model that goes low-or-high into thinking switched off entirely — which is a further
        // answer from the question than `high` ever was. Upstream searches up from what was
        // asked for and then down, so the most this can do is move one step.
        $this->assertSame(ThinkingLevel::High, $session->thinkingLevel());
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

        // The map says so, as upstream requires; the id alone used to (`gpt-5.2` was on an id list
        // pig kept because its OpenAI rows had no maps — they carry upstream's now).
        $capable = $this->session(['one'], null, $this->thinkingModel('gpt-5.2', ['xhigh' => 'xhigh']));
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

    public function testACompactionDoesNotRefundWhatTheSessionSpent(): void
    {
        // Summed over the messages in memory, a compaction *erased* the bill: what it replaces
        // them with is one summary carrying no usage, so the footer and `/session` both dropped
        // back towards zero at exactly the point a session has been long enough to be expensive.
        // Upstream's footer sums the session file for this reason; its own `/session` does not,
        // which is two answers to one question in one tool.
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['the summary'], store: $store, settings: self::quickRetries(['keepRecentTokens' => 1]));

        foreach (range(1, 3) as $turn) {
            $session->agent->appendMessage(new UserMessage(str_repeat('x', 20_000)));
            $session->agent->appendMessage(new AssistantMessage(
                [new TextContent("answer {$turn}")],
                Api::AnthropicMessages,
                'anthropic',
                'test-model',
                new Usage(1_000, 200, 0, 0, 1_200, new Cost(total: 0.25)),
                StopReason::Stop,
            ));
            $store->append($session->messages()[count($session->messages()) - 2]);
            $store->append($session->messages()[count($session->messages()) - 1]);
        }

        $before = $session->stats();
        $this->assertSame(0.75, $before->cost);
        $this->assertSame(3_000, $before->input);

        Async::run(static fn () => $session->compact());

        $after = $session->stats();

        $this->assertSame(0.75, $after->cost, 'the money was spent whatever the conversation now looks like');
        $this->assertSame(3_000, $after->input);

        // The counts are the other question and stay on the conversation: after a compaction it
        // *is* one summary and whatever was kept, and saying "you said 3 things" about a
        // transcript showing one is the answer to a question nobody asked.
        $this->assertLessThan($before->userMessages, $after->userMessages);
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

    public function testAFailedTurnTakenOffTheStateStaysOffItWhenTheSessionIsResumed(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'Anthropic returned 503: overloaded'], 'here you go']),
            store: $store,
            settings: self::quickRetries(),
        );

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        // The file is the record: the failed turn is still in it, and so is the line saying the
        // model is not to be shown it — upstream's `_omitRecoveryAttempt()`. Before this the message
        // was taken off the agent's state and nothing was written, so a `--resume` put it back.
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($store->path))));
        $this->assertStringContainsString('"type":"context_edit"', implode("\n", $lines));
        $this->assertStringContainsString('503', implode("\n", $lines), 'the failure is still in the file');

        $reopened = SessionManager::open($store->path)->messages();
        $this->assertCount(2, $reopened);

        foreach ($reopened as $message) {
            $this->assertStringNotContainsString('503', self::textOf($message));
        }

        $this->assertSame('here you go', self::textOf($reopened[1]));
    }

    public function testItGivesUpAfterTheAttemptsRunOut(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 6, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['maxRetries' => 2]),
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
            settings: self::quickRetries(['maxRetries' => 3, 'baseDelayMs' => 2]),
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

    public function testAQuotaTenMinutesAwayEndsTheTurnInPisWordsInsteadOfPretendingToRetry(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'google returned 429: Your quota will reset after 10m15s']]),
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

        // No countdown, because a ten-minute countdown is a screen that looks like a hang.
        $this->assertNotContains(RetryStartEvent::class, array_map('get_class', $seen));

        $expected = 'Quota reached. Please wait 10m15s. Next: switch models or try again after reset.';
        $messages = $session->messages();
        $last = $messages[count($messages) - 1];

        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame($expected, $last->errorMessage);

        $messageEnd = array_values(array_filter(
            $seen,
            static fn (AgentEvent $event): bool => $event instanceof MessageEndEvent
                && $event->message instanceof AssistantMessage,
        ))[0] ?? null;
        $agentEnd = array_values(array_filter($seen, static fn (AgentEvent $event): bool => $event instanceof AgentEndEvent))[0] ?? null;

        $this->assertInstanceOf(MessageEndEvent::class, $messageEnd);
        $this->assertInstanceOf(AgentEndEvent::class, $agentEnd);
        $ended = array_values(array_filter(
            $agentEnd->messages,
            static fn (mixed $message): bool => $message instanceof AssistantMessage,
        ))[0] ?? null;
        $this->assertInstanceOf(AssistantMessage::class, $ended);
        $this->assertSame($expected, $messageEnd->message->errorMessage);
        $this->assertSame($expected, $ended->errorMessage);
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

    public function testAgentSettledFiresOnceAPromptAndNotOncePerAttempt(): void
    {
        // Upstream's `agent_settled` is "final and notification-only": emitted once, after the
        // retry loop, when pig will not continue on its own. pig emitted it beside `agent_end`,
        // which is the end of a *run* — so a turn that retried twice settled three times, and the
        // system notification hook said "task complete" twice while the task was still going.
        $settled = [];
        $ended = 0;
        $hooks = $this->hooks([
            'agent_end' => static function () use (&$ended): void {
                $ended++;
            },
            'agent_settled' => static function ($event) use (&$settled): void {
                $settled[] = count($event->messages);
            },
        ]);
        $session = $this->session(
            [],
            streamFn: $this->flaky([
                ['error' => 'Anthropic returned 503: overloaded'],
                ['error' => 'Anthropic returned 503: overloaded'],
                'here you go',
            ]),
            settings: self::quickRetries(),
            hooks: $hooks,
        );

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });
        self::settle();

        $this->assertSame('here you go', self::textOf($session->messages()[1]));
        $this->assertSame(3, $ended, 'three runs: two failed attempts and the one that worked');
        $this->assertCount(1, $settled, 'but the agent settled once, at the end of the last one');
        $this->assertSame(2, $settled[0], 'with the conversation as it stands: the prompt and the answer');
    }

    public function testAgentSettledStillFiresWhenNothingWasRetried(): void
    {
        $settled = 0;
        $session = $this->session(['hello'], hooks: $this->hooks([
            'agent_settled' => static function () use (&$settled): void {
                $settled++;
            },
        ]));

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });

        $this->assertSame(1, $settled);
    }

    public function testAQuotaThatSaysWhenItResetsIsWaitedOutForThatLongAndNotTwoSeconds(): void
    {
        // Code Assist's free tier answers a 429 with the moment its quota comes back. The
        // doubling would send three more requests inside the window it just named.
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, [
                'error' => 'google returned 429: Your quota will reset after 30s',
            ])),
            settings: self::quickRetries(),
        );

        $starts = [];
        $session->subscribe(static function (AgentEvent $event) use (&$starts): void {
            if ($event instanceof RetryStartEvent) {
                $starts[] = $event;
            }
        });

        // Parked on the timer rather than waiting it out — the delay is the subject, so the
        // test must not sit through it.
        self::startTurnAndParkOnTheRetry($session);

        $this->assertCount(1, $starts);
        $this->assertSame(31.0, $starts[0]->delaySeconds, 'thirty seconds as asked, plus the clock-skew second');

        $session->abortRetry();
        self::settle();
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

        self::startTurnAndParkOnTheRetry($session);

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

        self::startTurnAndParkOnTheRetry($session);

        $this->assertTrue($session->isRetrying());

        // Escape means stop, and a retry that is sleeping has no agent to interrupt: the run
        // is already over and the next one has not started. `abort()` has to reach both.
        Async::run(static function () use ($session): void {
            $session->abort()->await();
        });
        self::settle();

        $this->assertFalse($session->isRetrying());
    }

    public function testStartingANewSessionStopsARetryThatWasStillWaiting(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['baseDelayMs' => 30_000]),
        );

        self::startTurnAndParkOnTheRetry($session);

        $this->assertTrue($session->isRetrying());

        // No settling afterwards, deliberately: the sleep is half a minute, so a `settle()`
        // here would wait it out and watch the retry end *by itself* — green whatever
        // `startNew()` did, which is how the first version of this test passed.
        Async::run(static function () use ($session): void {
            $session->startNew();
        });

        // A sleeping retry is not "streaming", so a guard on that alone leaves it running —
        // and what it is rescuing is a turn from the conversation just walked away from.
        $this->assertFalse($session->isRetrying());
    }

    public function testTheAgentCarriesTheSessionFilesIdAsItsSessionId(): void
    {
        // Upstream's agent is handed the session manager's id as `sessionId`, and every request
        // carries it: Anthropic's session affinity and the Responses API's `prompt_cache_key` key on
        // it. pig had no session id on a request at all. A switch of file switches it; a session
        // that is not written down has none.
        $store = SessionManager::create(sys_get_temp_dir());
        $other = SessionManager::create(sys_get_temp_dir());
        $session = $this->session([], store: $store);

        $this->assertSame($store->id, $session->agent->sessionId);

        $session->writeTo($other);
        $this->assertSame($other->id, $session->agent->sessionId);

        $session->writeTo(null);
        $this->assertNull($session->agent->sessionId);
    }

    public function testSwitchingSessionStopsARetryThatWasStillWaiting(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $other = SessionManager::create(sys_get_temp_dir());
        $other->append(new UserMessage('something else entirely'));
        $other->append(new AssistantMessage(
            [new TextContent('and its answer')],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Stop,
        ));

        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            store: $store,
            settings: self::quickRetries(['baseDelayMs' => 30_000]),
        );

        self::startTurnAndParkOnTheRetry($session);

        $this->assertTrue($session->isRetrying());

        Async::run(static function () use ($session, $other): void {
            $session->switchTo($other->path);
        });

        $this->assertFalse($session->isRetrying());
    }

    public function testARetryDoesNotCarryOnIntoTheSessionThatReplacedIt(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['baseDelayMs' => 2]),
        );

        self::startTurnAndParkOnTheRetry($session);

        Async::run(static function () use ($session): void {
            $session->startNew();
        });

        // Everything after the switch belongs to the new conversation, so nothing the old
        // one's retry has to say may arrive here: left running, it wakes from its sleep and
        // asks the *emptied* agent to carry on, which comes back as "Retry failed: cannot
        // continue, no messages in context" in a conversation nobody has said anything in.
        $after = [];
        $session->subscribe(static function (AgentEvent $event) use (&$after): void {
            $after[] = $event::class;
        });
        self::settle();

        $this->assertSame([], $after);
        $this->assertSame([], $session->messages());
    }

    public function testATurnAbortedByANewSessionLeavesNothingBehindInIt(): void
    {
        // Upstream unsubscribes from the agent for the length of the abort in all three places
        // that throw a conversation away (`_disconnectFromAgent`), so none of the aborted
        // turn's events reach its session object at all. pig has no such shield, and this is
        // what says it does not need one: the events *are* delivered, and every one of them is
        // filed against the conversation being left, which is where the aborted half of a turn
        // belongs. If this ever goes red, the shield is the thing to port.
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session([], null, null, self::parksUntilAborted(), $store);

        $switched = false;
        $seen = [];
        $session->subscribe(static function (AgentEvent $event) use (&$seen, &$switched): void {
            $seen[] = ($switched ? 'after:' : 'before:') . $event::class;
        });

        Async::run(function () use ($session, &$switched): void {
            Async::spawn(static fn () => $session->prompt('hi'));

            // Long enough for the turn to be in flight and short enough that it still is.
            Async::delay(0.01);
            $this->assertTrue($session->isStreaming());

            $session->startNew();
            $switched = true;
        });
        self::settle();

        $after = array_values(array_filter($seen, static fn (string $s): bool => str_starts_with($s, 'after:')));

        // The other half of the claim, and what stops `$after` being empty for the trivial
        // reason: the events of the aborted turn were delivered, all of them before the reset.
        $this->assertContains('before:' . AgentEndEvent::class, $seen);

        // No second turn — the scripted provider would throw if it were asked again — and no
        // retry, no auto-compaction and no held-back command arriving in the new conversation.
        $this->assertSame([], $after);
        $this->assertSame([], $session->messages());
        $this->assertFalse($session->isRetrying());

        // And the half a turn that did happen is in the file it happened in, not in the new
        // one, which is the half upstream drops on the floor.
        $this->assertNotSame($store->path, $session->store()?->path);
        $this->assertNotSame([], $store->messages());

        // Nothing at all in the new one — not even a file, since nothing is written until the
        // first assistant message, and the new conversation has had none.
        $this->assertFileDoesNotExist((string) $session->store()?->path);
    }

    public function testARetryCalledOffAsItIsAnnouncedNeverSleeps(): void
    {
        $session = $this->session(
            [],
            streamFn: $this->flaky(array_fill(0, 4, ['error' => 'Anthropic returned 503: overloaded'])),
            settings: self::quickRetries(['baseDelayMs' => 30_000]),
        );

        $starts = $ends = [];
        $session->subscribe(static function (AgentEvent $event) use ($session, &$starts, &$ends): void {
            if ($event instanceof RetryStartEvent) {
                $starts[] = $event;

                // The earliest escape can land: the retry is announced and its sleep not yet
                // armed. This used to be a tick of its own — the retry was decided in the run's
                // fan-out and slept in a fiber spawned from there — and is now the announcement
                // itself, since the sleep follows it in the prompt's own fiber.
                $session->abortRetry();
            }

            if ($event instanceof RetryEndEvent) {
                $ends[] = $event;
            }
        });

        Async::run(static function () use ($session): void {
            Async::spawn(static fn () => $session->prompt('hi'));
        });

        for ($tick = 0; $tick < 50; $tick++) {
            self::tickWithoutWaiting();
        }

        // Never slept: the controller the announcement was made under is the one escape reached,
        // so the half-minute timer was not armed against a controller nobody holds.
        $this->assertCount(1, $starts);
        $this->assertCount(1, $ends);
        $this->assertStringContainsString('cancelled', $ends[0]->error ?? '');
        $this->assertFalse($session->isRetrying());
        $this->assertTrue($session->isIdle());
    }

    public function testAbortingWhenNothingIsBeingRetriedIsHarmless(): void
    {
        $session = $this->session(['hello']);

        $session->abortRetry();

        $this->assertFalse($session->isRetrying());
    }

    // ---- the overflow half --------------------------------------------------------------

    public function testAHookCanChangeTheTermsOfARetryBeforeItWaits(): void
    {
        // `before_retry`: what the Antigravity extension's 429 failover goes through. It used to
        // be an `if provider === 'antigravity'` inside this class; now any extension holding
        // several accounts for a provider can switch and ask to go again at once, count reset.
        $seen = [];
        $hooks = $this->hooks([
            'before_retry' => static function (BeforeRetryEvent $event) use (&$seen): BeforeRetryResult {
                $seen[] = [$event->attempt, $event->delaySeconds];

                return new BeforeRetryResult(delaySeconds: 0.01, resetAttempts: true, reason: 'Switched to account 2.');
            },
        ]);

        $session = $this->session(
            [],
            streamFn: $this->flaky([
                ['error' => 'zzp returned 429: Resource has been exhausted (quota exceeded)'],
                'Recovered with account 2!',
            ]),
            settings: self::quickRetries(['baseDelayMs' => 5_000]),
            hooks: $hooks,
        );

        $started = [];
        $session->subscribe(static function (object $event) use (&$started): void {
            if ($event instanceof RetryStartEvent) {
                $started[] = [$event->attempt, $event->delaySeconds, $event->error];
            }
        });

        $began = microtime(true);
        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });

        // The hook saw the session's own terms and replaced them: the screen shows the hook's
        // reason and the hook's wait, not five seconds of backoff.
        $this->assertSame([[1, 5.0]], array_map(static fn (array $one): array => [$one[0], round($one[1], 1)], $seen));
        $this->assertCount(1, $started);
        $this->assertSame([1, 0.01, 'Switched to account 2.'], $started[0]);
        $this->assertLessThan(2.0, microtime(true) - $began, 'the hook\'s wait, not the backoff');

        $messages = $session->messages();
        $this->assertCount(2, $messages);
        $this->assertSame('Recovered with account 2!', $messages[1]->content[0]->text);
    }

    public function testAHookCanCallARetryOff(): void
    {
        $hooks = $this->hooks([
            'before_retry' => static fn (): BeforeRetryResult => new BeforeRetryResult(cancel: true, reason: 'Not worth it.'),
        ]);

        $session = $this->session(
            [],
            streamFn: $this->flaky([['error' => 'zzp returned 503: overloaded'], 'never sent']),
            settings: self::quickRetries(['baseDelayMs' => 10]),
            hooks: $hooks,
        );

        $ended = [];
        $session->subscribe(static function (object $event) use (&$ended): void {
            if ($event instanceof RetryEndEvent) {
                $ended[] = [$event->succeeded, $event->error];
            }
        });

        Async::run(static function () use ($session): void {
            $session->prompt('hi');
        });

        $this->assertSame([[false, 'Not worth it.']], $ended);
        // The failed turn stays, as it does when the count runs out: the turn fails as it stands.
        $messages = $session->messages();
        $this->assertCount(2, $messages);
        $this->assertSame(StopReason::Error, $messages[1]->stopReason);
    }

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

    public function testEscapeReachesASummarisationNobodyAskedFor(): void
    {
        // The screen says "esc to cancel" while this runs, and for the *auto* compaction there was
        // nothing behind the label: `compactAndCarryOn()` called `compact()` with no signal at all,
        // so the summariser's own turn — a whole conversation, at high reasoning — could not be
        // stopped. Which is the failure `Retry`'s abortable sleep exists to prevent, one method
        // over: a wait that escape cannot reach looks exactly like a hang.
        $session = $this->overflowingIntoASummariserThatParks();

        $starts = $ends = [];
        $session->subscribe(static function (AgentEvent $event) use (&$starts, &$ends): void {
            if ($event instanceof AutoCompactionStartEvent) {
                $starts[] = $event;
            }

            if ($event instanceof AutoCompactionEndEvent) {
                $ends[] = $event;
            }
        });

        self::runUntilTheSummariserIsGoing($session);

        $this->assertCount(1, $starts);
        $this->assertTrue($session->isCompacting());

        // And not a retry: the waiting handle `prompt()` parks on is pending for both, so keying
        // this off it would have the session claiming to retry every time it summarises.
        $this->assertFalse($session->isRetrying());

        $session->abortCompaction();

        for ($tick = 0; $tick < 200 && $ends === []; $tick++) {
            self::tickWithoutWaiting();
        }

        $this->assertCount(1, $ends);
        $this->assertFalse($ends[0]->succeeded);
        $this->assertStringContainsString('cancelled', $ends[0]->error ?? '');
        $this->assertFalse($session->isCompacting());
    }

    public function testStoppingTheRunStopsASummarisationWithIt(): void
    {
        // One call reaches all three things escape has to stop — the run, a sleeping retry and a
        // summarisation — so a caller cannot reach two of them and believe it has finished.
        $session = $this->overflowingIntoASummariserThatParks();

        $ends = $retries = [];
        $session->subscribe(static function (AgentEvent $event) use (&$ends, &$retries): void {
            if ($event instanceof AutoCompactionEndEvent) {
                $ends[] = $event;
            }

            if ($event instanceof RetryEndEvent) {
                $retries[] = $event;
            }
        });

        self::runUntilTheSummariserIsGoing($session);

        Async::run(static function () use ($session): void {
            $session->abort()->await();
        });

        for ($tick = 0; $tick < 200 && $ends === []; $tick++) {
            self::tickWithoutWaiting();
        }

        $this->assertCount(1, $ends);
        $this->assertFalse($ends[0]->succeeded);

        // `abort()` calls `abortRetry()` first, and there is no retry here — so it has to say
        // nothing rather than announce one ending. Its guard is the controller for that reason.
        $this->assertSame([], $retries);
    }

    public function testATypedCompactionCanBeStoppedFromEitherEnd(): void
    {
        // `/compact` brings a signal of its own, and `abortCompaction()` has to reach that
        // summariser too — otherwise there are two facts about one summarisation and only the
        // caller holds the one that works. The forwarding in `compact()` is what makes it one.
        foreach (['the caller', 'the session'] as $who) {
            $session = $this->session(
                [],
                null,
                null,
                self::parksUntilAborted(),
                settings: self::quickRetries(['keepRecentTokens' => 1]),
            );

            foreach (range(1, 6) as $ignored) {
                $session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
            }

            $mine = new AbortController();
            $answer = 'not yet';

            Async::run(static function () use ($session, $mine, &$answer): void {
                Async::spawn(static function () use ($session, $mine, &$answer): void {
                    $answer = $session->compact(null, $mine->signal);
                });
            });

            for ($tick = 0; $tick < 200 && !$session->isCompacting(); $tick++) {
                self::tickWithoutWaiting();
            }

            $this->assertTrue($session->isCompacting(), $who);

            if ($who === 'the caller') {
                $mine->abort('Cancelled');
            } else {
                $session->abortCompaction();
            }

            for ($tick = 0; $tick < 200 && $session->isCompacting(); $tick++) {
                self::tickWithoutWaiting();
            }

            $this->assertNull($answer, "stopped by {$who}");
            $this->assertFalse($session->isCompacting(), $who);
        }
    }

    /**
     * Two answered turns, a third that overflows, and a summariser that never finishes.
     *
     * Two real turns first because a compaction has to be *worth making*: `cutPoint()` works in
     * tokens, and a conversation of one failed message is refused as "too small" before the
     * summariser is ever reached — which is how the first version of these two tests came back
     * green about something that had not happened.
     */
    private function overflowingIntoASummariserThatParks(): AgentSession
    {
        $at = 0;

        return $this->session(
            [],
            streamFn: function (
                Model $model,
                Context $context,
                SimpleStreamOptions $options,
            ) use (&$at): AssistantMessageEventStream {
                $at++;

                return match (true) {
                    $at === 1 => $this->replay('first answer'),
                    $at === 2 => $this->replay('second answer'),
                    $at === 3 => $this->fails('prompt is too long: 213462 tokens > 200000 maximum'),
                    default => (self::parksUntilAborted())($model, $context, $options),
                };
            },
            settings: self::quickRetries(['keepRecentTokens' => 1]),
        );
    }

    /** Three turns, spawned, and the loop turned until the summariser is actually running. */
    private static function runUntilTheSummariserIsGoing(AgentSession $session): void
    {
        Async::run(static function () use ($session): void {
            Async::spawn(static function () use ($session): void {
                $session->prompt('one');
                $session->prompt('two');
                $session->prompt('three');
            });
        });

        // `isCompacting()` and not the start event: that is announced before `compact()` is
        // called, so it says the overflow was noticed rather than that a summariser exists.
        for ($tick = 0; $tick < 400 && !$session->isCompacting(); $tick++) {
            self::tickWithoutWaiting();
        }
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

    // ---- which model -------------------------------------------------------------------

    public function testAModelWithNoKeyIsRefusedAndNotWrittenDown(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store, getApiKey: static fn (string $provider): ?string
            => $provider === 'anthropic' ? 'a-key' : null);

        Async::run(static fn () => $session->prompt('hi'));
        $before = (string) file_get_contents($store->path);

        $openai = new Model('other-model', 'Other', Api::AnthropicMessages, 'openai', 'http://127.0.0.1:1', 1_000, 100);
        $error = $this->assertThrows(AgentError::class, static fn () => $session->setModel($openai));

        // Upstream's message, and upstream's order: the key is checked before anything is applied.
        // It used to switch, write a `model_change` into the file, and fail on the next turn from
        // inside `Stream` — where a conversation recorded as being on a model nobody can talk to
        // is left in the file for `--continue` to find.
        $this->assertStringContainsString('No API key for openai/other-model', $error->getMessage());
        $this->assertSame('test-model', $session->model()?->id);
        $this->assertSame($before, (string) file_get_contents($store->path), 'and the file says nothing about it');
    }

    public function testAModelWithAKeyIsSetAsBefore(): void
    {
        $session = $this->session([], getApiKey: static fn (string $provider): ?string
            => $provider === 'anthropic' ? 'a-key' : null);

        $session->setModel($this->thinkingModel());

        // The other half, so the test above cannot pass by `setModel()` refusing everything.
        $this->assertSame('test-thinker', $session->model()?->id);
    }

    // ---- going back ---------------------------------------------------------------------

    /** @return array{0: AgentSession, 1: SessionManager, 2: list<array{id: string, message: mixed, branches: int, label: string|null}>} */
    private function twoExchanges(): array
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['first answer', 'second answer'], store: $store);

        Async::run(static fn () => $session->prompt('the first question'));
        Async::run(static fn () => $session->prompt('the second question'));

        return [$session, $store, $store->branch()];
    }

    public function testGoingBackToSomethingYouSaidTakesItBackAndHandsItToTheEditor(): void
    {
        [$session, $store, $points] = $this->twoExchanges();

        $jump = Async::run(static fn () => $session->goTo($points[2]['id']));

        // The whole point of going back to a question: it leaves the conversation and comes back
        // in the prompt to be asked differently. pig used to land the leaf *on* the message, so
        // the old wording stayed and re-asking meant retyping it.
        $this->assertTrue($jump->moved);
        $this->assertSame('the second question', $jump->editorText);
        $this->assertCount(2, $session->messages(), 'back to the first exchange, question included');
        $this->assertCount(2, $store->branch());
    }

    public function testGoingBackToAnAnswerKeepsIt(): void
    {
        [$session, , $points] = $this->twoExchanges();

        $jump = Async::run(static fn () => $session->goTo($points[1]['id']));

        // The other half, so the case above cannot pass by every jump moving to a parent: an
        // answer is a place to carry on from, not something to take back.
        $this->assertTrue($jump->moved);
        $this->assertNull($jump->editorText);
        $this->assertCount(2, $session->messages());
    }

    public function testGoingBackToTheFirstThingSaidLeavesAnEmptyConversation(): void
    {
        [$session, , $points] = $this->twoExchanges();

        $jump = Async::run(static fn () => $session->goTo($points[0]['id']));

        // A root's parent is the root's own id in the file; the point before a root is nothing.
        $this->assertSame([], $session->messages());
        $this->assertSame('the first question', $jump->editorText);
    }

    public function testGoingToWhereYouAlreadyAreIsNotACancellation(): void
    {
        [$session, $store, ] = $this->twoExchanges();

        $jump = Async::run(static fn () => $session->goTo($store->leaf()));

        // `moved: false, aborted: false` — the fourth answer. Read as a cancellation it would say
        // "branch summary cancelled" to somebody who asked for no summary.
        $this->assertFalse($jump->moved);
        $this->assertFalse($jump->aborted);
        $this->assertCount(4, $session->messages());
    }

    public function testASummaryAskedForWithNoModelIsRefusedRatherThanSkipped(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $store->append(new UserMessage('a question'));
        $store->append(new AssistantMessage(
            [new TextContent('an answer')],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Stop,
        ));

        $agent = new Agent(new AgentOptions(streamFn: $this->provider([], null), apiKey: 'test-key'));
        // See `testPromptingWithNoModelSaysSo`: a state fills in a default, so "no model" is a
        // model that was lost rather than one never chosen.
        $agent->state->model = null;
        $session = new AgentSession($agent, sys_get_temp_dir(), $store);
        $session->restore($store->messages());

        $error = $this->assertThrows(
            AgentError::class,
            static fn () => $session->goTo($store->branch()[0]['id'], summarise: true),
        );

        // It used to move and quietly write no summary: somebody who asked for the branch to be
        // written down and got the move without it has lost the branch.
        $this->assertStringContainsString('No model', $error->getMessage());
        $this->assertCount(2, $session->messages(), 'and it did not move');
    }

    // ---- leaving this conversation ------------------------------------------------------

    public function testANewSessionIsANewFileAndTheOldOneStopsWhereItStopped(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello', 'hello again'], store: $store);

        Async::run(static fn () => $session->prompt('the first conversation'));
        $first = $store->path;
        $lines = substr_count((string) file_get_contents($first), "\n");

        $switch = $session->startNew();

        $this->assertTrue($switch->switched);
        $this->assertSame($first, $switch->previous);
        $this->assertNotSame($first, $session->store()?->path);
        $this->assertSame([], $session->messages());

        Async::run(static fn () => $session->prompt('and the second'));

        // The two halves of the bug `writeTo()` was added for, asserted from the one place
        // that now decides it: the old file did not grow, and the new one is not a
        // continuation of it.
        $this->assertSame($lines, substr_count((string) file_get_contents($first), "\n"));

        $written = (string) file_get_contents((string) $session->store()?->path);
        $this->assertStringContainsString('and the second', $written);
        $this->assertStringNotContainsString('the first conversation', $written);
    }

    public function testANewSessionOnOneThatIsNotBeingSavedStartsNoFile(): void
    {
        $session = $this->session(['hello']);

        Async::run(static fn () => $session->prompt('hi'));
        $switch = $session->startNew();

        // `--no-save` means no file, and `/new` is not a reason to start keeping one.
        $this->assertTrue($switch->switched);
        $this->assertNull($switch->previous);
        $this->assertNull($session->store());
        $this->assertSame([], $session->messages());
    }

    public function testLeavingAConversationLeavesWhatWasTypedIntoIt(): void
    {
        $session = $this->session([], store: SessionManager::create(sys_get_temp_dir()));
        $session->followUp('meant for the conversation being thrown away');

        $session->startNew();

        // Sending it into the next conversation is the same crossing as writing to the
        // wrong file, and neither mode remembered to stop it.
        $this->assertSame([], $session->queued());
    }

    public function testSwitchingCarriesOnInTheFileThatWasOpened(): void
    {
        $mine = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello', 'hello again'], store: $mine);
        Async::run(static fn () => $session->prompt('the conversation I am in'));

        $other = $this->session(['hi there'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $other->prompt('the conversation I am switching to'));
        $elsewhere = (string) $other->store()?->path;

        $switch = $session->switchTo($elsewhere);

        $this->assertTrue($switch->switched);
        $this->assertSame($mine->path, $switch->previous);
        $this->assertSame(2, $switch->messages);
        $this->assertSame($elsewhere, $session->store()?->path);
        $this->assertCount(2, $session->messages());

        Async::run(static fn () => $session->prompt('carrying on'));

        // What is on screen and what is being written are the same conversation — before
        // `writeTo()` they were two files, neither of them what happened.
        $this->assertStringContainsString('carrying on', (string) file_get_contents($elsewhere));
        $this->assertStringNotContainsString('carrying on', (string) file_get_contents($mine->path));
    }

    public function testWhatWasTypedWithAnExclamationMarkIsInTheFileToo(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store);

        // The file has to exist before a `!command` can be written to it, which is what the
        // first turn is for: nothing is written until something has been answered.
        Async::run(static fn () => $session->prompt('hi'));
        Async::run(static fn () => $session->executeBash('echo from-the-shell'));

        // Appended directly rather than through a turn, so there is no `message_end` to carry
        // it to the file — and a conversation that reopens without what was run in it is a
        // conversation missing the half that explains the rest.
        $this->assertStringContainsString('from-the-shell', (string) file_get_contents($store->path));
    }

    public function testACommandRunDuringATurnReachesTheFileWhenTheTurnEnds(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello', 'hello again'], store: $store);
        Async::run(static fn () => $session->prompt('hi'));

        $ran = null;
        $session->subscribe(static function (AgentEvent $event) use ($session, &$ran): void {
            if ($event instanceof MessageStartEvent && $ran === null) {
                // Held back, because a message between a tool call and its result is a request
                // every provider rejects. Held back and then dropped is the failure.
                $ran = Async::run(static fn () => $session->executeBash('echo held-back'));
            }
        });

        Async::run(static fn () => $session->prompt('and again'));

        $this->assertStringContainsString('held-back', (string) file_get_contents($store->path));
    }

    // ---- leaving a conversation, which is a recipe and not a line ---------------------------

    public function testAHookCanRefuseToLeaveAConversationEitherWay(): void
    {
        // The cancellable hook is the whole reason `startNew()` and `switchTo()` are one method
        // each rather than a few lines in every mode: a hook that refuses worked in the terminal
        // and was ignored over RPC until the recipe moved in here. Both doors, because a guard
        // on one of two is the shape that got it wrong the first time.
        $asked = [];
        $hooks = $this->hooks([
            'session_before_switch' => function (mixed $event) use (&$asked): SessionBeforeSwitchResult {
                $asked[] = $event->reason;

                return new SessionBeforeSwitchResult(cancel: true);
            },
        ]);

        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store, hooks: $hooks);
        Async::run(static fn () => $session->prompt('the conversation I am in'));

        $new = $session->startNew();

        $this->assertFalse($new->switched);
        $this->assertSame($store->path, $session->store()?->path, 'still writing where it was');
        $this->assertCount(2, $session->messages(), 'and still holding what was said');

        $other = $this->session(['hi'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $other->prompt('somewhere else'));

        $switch = $session->switchTo((string) $other->store()?->path);

        $this->assertFalse($switch->switched);
        $this->assertSame($store->path, $session->store()?->path);
        $this->assertSame(['new', 'resume'], $asked, 'and each was told which it was');
    }

    public function testWhatWasQueuedForTheOldConversationDoesNotCrossOver(): void
    {
        $session = $this->session(['hello'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $session->prompt('the conversation I am in'));
        $session->followUp('and one more thing');

        $this->assertSame(['and one more thing'], $session->queued());

        $session->startNew();

        // It was typed into the conversation being thrown away. Sending it into its replacement
        // is the same crossing as appending to the wrong file.
        $this->assertSame([], $session->queued());

        // And the other door, which had its own copy of the recipe until these became one
        // method each — a guard on one of two is how the first version of this went wrong.
        $other = $this->session(['hi'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $other->prompt('somewhere else'));

        Async::run(static fn () => $session->prompt('a fresh start'));
        $session->followUp('queued here');

        $session->switchTo((string) $other->store()?->path);

        $this->assertSame([], $session->queued());
    }

    public function testAHookThatLooksAndSaysNothingDoesNotStopTheSwitch(): void
    {
        // A hook that only wants to *watch* must not stop the thing it is watching. Worth knowing
        // that no mutation of the `&& $refusal->cancel` here can fail this: `emitBeforeSwitch()`
        // goes through `ask()`, whose decisiveness predicate is `$r->cancel`, so a result that
        // does not cancel never comes back at all. The two checks are one rule in two places —
        // harmless, and the reason this case passes either way is measured rather than assumed.
        $hooks = $this->hooks([
            'session_before_switch' => static fn (): SessionBeforeSwitchResult
                => new SessionBeforeSwitchResult(cancel: false),
        ]);

        $session = $this->session(['hello'], store: SessionManager::create(sys_get_temp_dir()), hooks: $hooks);
        Async::run(static fn () => $session->prompt('hi'));

        $this->assertTrue($session->startNew()->switched);

        $other = $this->session(['hi'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $other->prompt('somewhere else'));

        $this->assertTrue($session->switchTo((string) $other->store()?->path)->switched);
    }

    public function testTheHooksHearAboutASwitchOnceItHasHappened(): void
    {
        $seen = [];
        $hooks = $this->hooks([
            'session_switch' => function (mixed $event) use (&$seen): null {
                $seen[] = [$event->reason, $event->previousSessionFile];

                return null;
            },
        ]);

        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store, hooks: $hooks);
        Async::run(static fn () => $session->prompt('hi'));

        $session->startNew();

        // A custom tool watching for this is how it knows the conversation under it changed —
        // and the file it names is the one being *left*, which is the only moment anything can
        // still say what it was.
        $this->assertSame([['new', $store->path]], $seen);

        $fresh = (string) $session->store()?->path;
        $other = $this->session(['hi'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $other->prompt('somewhere else'));

        $session->switchTo((string) $other->store()?->path);

        $this->assertSame(['resume', $fresh], $seen[1] ?? null, 'and the other door says which it was');
    }

    public function testResumingComesBackOnWhatThatConversationWasHadWith(): void
    {
        // A real registry model, for the reason the `--continue` case below gives: restoring
        // means looking the recorded provider and id back up, and this one can reason, so the
        // level is not clamped away before it is ever written down.
        $reasoning = Models::find('anthropic', 'claude-haiku-4-5');
        self::assertNotNull($reasoning);

        $other = SessionManager::create(sys_get_temp_dir());
        $elsewhere = $this->session(['hi there'], store: $other);
        Async::run(static fn () => $elsewhere->prompt('a conversation on another model'));
        $elsewhere->setModel($reasoning);
        $elsewhere->setThinkingLevel(ThinkingLevel::High);

        $session = $this->session(['hello'], store: SessionManager::create(sys_get_temp_dir()));
        Async::run(static fn () => $session->prompt('here'));

        $this->assertSame(ThinkingLevel::Off, $session->thinkingLevel());

        $session->switchTo((string) $other->path);

        // The `--continue` case calls `restoreSettings()` itself; this is the other door, and
        // resuming takes no model and no level, so the file wins outright — which is what the
        // word means. Without it the conversation comes back on whatever the last one used.
        $this->assertSame('claude-haiku-4-5', $session->model()?->id);
        $this->assertSame(ThinkingLevel::High, $session->thinkingLevel());
    }

    public function testAPathThatIsNotASessionCostsTheConversationNothing(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session(['hello'], store: $store);

        Async::run(static fn () => $session->prompt('hi'));
        $session->followUp('still waiting to be sent');

        $this->assertThrows(
            AgentError::class,
            static fn () => $session->switchTo(sys_get_temp_dir() . '/not-a-session-' . bin2hex(random_bytes(4)) . '.jsonl'),
            'Could not read the session',
        );

        // The file is opened before anything is thrown away, so a bad path is a message
        // rather than a session left aborted, emptied, and writing to the file it was
        // about to leave.
        $this->assertSame($store->path, $session->store()?->path);
        $this->assertCount(2, $session->messages());
        $this->assertSame(['still waiting to be sent'], $session->queued());
    }

    /** @param array<string, callable> $handlers */
    private function hooks(array $handlers): HookRunner
    {
        $api = new HookApi('.', 'test.php');

        foreach ($handlers as $event => $handler) {
            $api->on($event, $handler);
        }

        return new HookRunner([new LoadedHook('test.php', 'test.php', $api)], sys_get_temp_dir());
    }

    private function session(
        array $answers,
        ?Closure $hook = null,
        ?Model $model = null,
        ?Closure $streamFn = null,
        ?SessionManager $store = null,
        ?Settings $settings = null,
        ?Closure $getApiKey = null,
        ?HookRunner $hooks = null,
        ?Auth $auth = null,
    ): AgentSession {
        $agent = new Agent(new AgentOptions(
            streamFn: $streamFn ?? $this->provider($answers, $hook),
            // One key for every provider unless a test says otherwise, which is what the cases
            // about a model there is no key for hand in instead.
            apiKey: $getApiKey === null ? 'test-key' : null,
            getApiKey: $getApiKey,
        ));

        $agent->setModel($model ?? $this->model());
        $this->current = $agent;

        return new AgentSession($agent, sys_get_temp_dir(), $store, $settings, $hooks, auth: $auth);
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

    /**
     * Send something that will fail, and come back with the retry parked on its timer.
     *
     * `Async::run()` will not do: `prompt()` does not return until the retries behind its turn
     * are finished too — which is the whole point of it, and which leaves a test that ran it
     * that way looking at a retry that is already over, or sitting through the delay it was
     * written to measure. So the turn is spawned and the loop is turned until the retry
     * exists, each tick with an expired timer of its own so the retry's own timer is never
     * waited out.
     */
    private static function startTurnAndParkOnTheRetry(AgentSession $session, string $text = 'hi'): void
    {
        $parked = false;
        $stop = $session->subscribe(static function (AgentEvent $event) use (&$parked): void {
            if ($event instanceof RetryStartEvent) {
                $parked = true;
            }
        });

        Async::run(static function () use ($session, $text): void {
            Async::spawn(static fn () => $session->prompt($text));
        });

        // The `RetryStartEvent` and not `isRetrying()`: the retry is decided synchronously, one
        // tick before the fiber that announces it and parks on the timer exists, so waiting for
        // the flag would come back too early to see either.
        for ($tick = 0; $tick < 200 && !$parked; $tick++) {
            self::tickWithoutWaiting();
        }

        $stop();
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

    /**
     * A turn that goes on until escape reaches it, which is what a provider's socket does.
     *
     * The seam for anything that has to happen *while* the agent is working: the scripted
     * providers above finish within the tick they are asked in, so there is no window.
     */
    private static function parksUntilAborted(): Closure
    {
        return static function (Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream {
            $stream = new AssistantMessageEventStream();
            $partial = new AssistantMessage(
                [new TextContent('half of an ans')],
                Api::AnthropicMessages,
                'anthropic',
                'test-model',
                new Usage(),
                StopReason::Aborted,
            );

            Async::spawn(static function () use ($stream, $partial, $options): void {
                $stream->push(new StartEvent($partial));

                // Parked on the signal rather than polling it: a poll only notices between ticks,
                // and a test that drives the loop by hand can get through fifty of them inside the
                // millisecond the poll was waiting for — which reads as an abort that did not
                // arrive.
                $stopped = new Deferred();
                $options->signal?->onAbort(static function () use ($stopped): void {
                    if (!$stopped->isComplete()) {
                        $stopped->complete(null);
                    }
                });

                if ($options->signal?->aborted() === true && !$stopped->isComplete()) {
                    $stopped->complete(null);
                }

                $stopped->future->await();

                $stream->push(new DoneEvent(StopReason::Aborted, $partial));
                $stream->end();
            });

            return $stream;
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

    /** @param array<string, string|null> $levels */
    private function thinkingModel(string $id = 'test-thinker', array $levels = []): Model
    {
        return new Model($id, 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, true, thinkingLevelMap: $levels);
    }

    /**
     * One that cannot reason, built here rather than looked up.
     *
     * It used to be `Models::get('claude-3-haiku-20240307')`, and the first regeneration of the
     * registry from models.dev retired that model — along with every other Anthropic model that
     * cannot reason, so there was nothing in that table left to look up. What the clamping rules
     * need is a model without reasoning, which is one line to build and no longer anybody else's
     * to discontinue.
     */
    private function plainModel(string $id = 'test-plain'): Model
    {
        return new Model($id, 'Test Plain', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 8_192, false);
    }
}
