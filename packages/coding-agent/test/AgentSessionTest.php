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
use Pig\Ai\Pricing;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\Test\AssertsThrows;
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
    private function session(array $answers, ?Closure $hook = null, ?Model $model = null): AgentSession
    {
        $agent = new Agent(new AgentOptions(
            streamFn: $this->provider($answers, $hook),
            apiKey: 'test-key',
        ));

        $agent->setModel($model ?? $this->model());
        $this->current = $agent;

        return new AgentSession($agent, sys_get_temp_dir());
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

    private function thinkingModel(string $id = 'test-thinker'): Model
    {
        return new Model($id, 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, true);
    }
}
