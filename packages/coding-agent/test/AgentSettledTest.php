<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\TranscriptContext;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Boundary\CompactionDraft;
use Pig\CodingAgent\Hooks\Boundary\ContextEditDraft;
use Pig\CodingAgent\Hooks\Boundary\CustomEntryDraft;
use Pig\CodingAgent\Hooks\Boundary\CustomMessageDraft;
use Pig\CodingAgent\Hooks\Events\AgentBeforeSettleEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\Results\BoundaryResult;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Settings;
use RuntimeException;

/**
 * `agent_settled`: once per prompt, and only when nothing more will run for it.
 *
 * Upstream's contract, from `_runAgentPrompt()`: the prompt is `agent.prompt()` followed by a
 * loop that retries, compacts, and continues while `agent.hasQueuedMessages()`, and
 * `_emitAgentSettled()` runs once, in the `finally` after that loop. A hook listening on it — the
 * system notification extension — says "task complete", so a settle that arrives while more of
 * the same request is about to run is a notification over a screen that still says Working.
 *
 * Each case records the hooks' `agent_start` / `agent_end` / `agent_settled` in order, plus what
 * the session said about itself at the moment it settled.
 */
final class AgentSettledTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    /** @var list<array{streaming: bool, idle: bool}> */
    private array $atSettle = [];

    /** @var list<string|array{error: string}|array{tool: string}> */
    private array $script = [];

    private int $calls = 0;

    private ?AgentSession $session = null;

    /** @var list<string> */
    private array $toolRuns = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->log = [];
        $this->atSettle = [];
        $this->calls = 0;
        $this->toolRuns = [];
        $this->session = null;
    }

    // ---- (a) a turn a hook or an extension starts from inside `agent_end` -----------------

    public function testATurnAHookTriggersFromAgentEndIsPartOfTheSamePrompt(): void
    {
        $sent = false;
        $session = $this->start(['first', 'second'], static function (HookApi $pi) use (&$sent): void {
            $pi->on('agent_end', static function () use ($pi, &$sent): void {
                if ($sent) {
                    return;
                }

                $sent = true;
                $pi->sendMessage('note', 'carry on', triggerTurn: true);
            });
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('second', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    public function testAUserMessageAnExtensionSendsFromAgentEndIsPartOfTheSamePrompt(): void
    {
        $sent = false;
        $session = $this->start(['first', 'second'], static function (HookApi $pi) use (&$sent): void {
            $pi->on('agent_end', static function () use ($pi, &$sent): void {
                if ($sent || !$pi instanceof ExtensionApi) {
                    return;
                }

                $sent = true;
                $pi->sendUserMessage('and another thing');
            });
        }, extension: true);

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('second', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    // ---- (b) queued while the run was ending ----------------------------------------------

    public function testAHookMessageQueuedFromTheLastTurnEndIsAnsweredBeforeSettling(): void
    {
        $sent = false;
        $session = $this->start(['first', 'second'], static function (HookApi $pi) use (&$sent): void {
            $pi->on('turn_end', static function () use ($pi, &$sent): void {
                if ($sent) {
                    return;
                }

                $sent = true;
                $pi->sendMessage('note', 'one more thing', triggerTurn: true);
            });
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('second', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    public function testSteeringTypedAsTheRunEndsIsAnsweredBeforeSettling(): void
    {
        $session = $this->start(['first', 'second']);
        $this->onFirstAnswer($session, static fn () => $session->steer('no, the other file'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('second', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSame([], $session->queued());
        $this->assertSettledOnceAtTheEnd();
    }

    public function testAFollowUpTypedAsTheRunEndsIsAnsweredBeforeSettling(): void
    {
        $session = $this->start(['first', 'second']);
        $this->onFirstAnswer($session, static fn () => $session->followUp('and then the tests'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('second', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSame([], $session->queued());
        $this->assertSettledOnceAtTheEnd();
    }

    // ---- (c) retries ------------------------------------------------------------------

    public function testAHookTurnTriggeredDuringARetrySleepDoesNotSettleThePromptEarly(): void
    {
        $session = $this->start([['error' => 'Anthropic returned 503: overloaded'], 'retried', 'third', 'fourth']);
        $sent = false;
        $session->subscribe(static function (AgentEvent $event) use ($session, &$sent): void {
            if ($event instanceof RetryStartEvent && !$sent) {
                $sent = true;
                $session->sendHookMessage(new HookMessage('note', [new TextContent('while you wait')]), true);
            }
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSettledOnceAtTheEnd();
    }

    public function testARetryBudgetSpentToTheLastAttemptSettlesOnce(): void
    {
        // Three retryable failures and then an answer: every retry the budget allows, each its own
        // run, and still one `agent_settled` for the prompt. (This used to go through pig's own
        // `before_retry` hook, which upstream has no counterpart of and pig no longer has.)
        $overloaded = ['error' => 'Anthropic returned 503: overloaded'];
        $session = $this->start([$overloaded, $overloaded, $overloaded, 'on the fourth attempt'], settings: Settings::inMemory([
            'retry' => ['baseDelayMs' => 1],
        ]));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('on the fourth attempt', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    public function testARetriedAttemptThatCallsToolsSettlesOnceAfterItsLastTurn(): void
    {
        $session = $this->start([['error' => 'Anthropic returned 503: overloaded'], ['tool' => 'noop'], 'done']);

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame(['noop'], $this->toolRuns);
        $this->assertSame('done', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    public function testEscapeDuringARetrySettlesOnceAndNothingRunsAfterIt(): void
    {
        $session = $this->start([['error' => 'Anthropic returned 503: overloaded'], 'never asked for'], settings: Settings::inMemory([
            'retry' => ['baseDelayMs' => 60_000],
        ]));
        $parked = false;
        $session->subscribe(static function (AgentEvent $event) use (&$parked): void {
            if ($event instanceof RetryStartEvent) {
                $parked = true;
            }
        });

        $returned = false;
        Async::run(static function () use ($session, &$returned): void {
            Async::spawn(static function () use ($session, &$returned): void {
                $session->prompt('hi');
                $returned = true;
            });
        });

        for ($tick = 0; $tick < 200 && !$parked; $tick++) {
            self::tickWithoutWaiting();
        }

        $this->assertTrue($parked);
        $session->abortRetry();
        self::settle();

        $this->assertTrue($returned, 'prompt() let go');
        $this->assertSame(1, $this->calls, 'the retry was not sent');
        $this->assertSettledOnceAtTheEnd();
    }

    public function testTheSessionIsBusyForTheWholeOfARetry(): void
    {
        // Upstream's `isStreaming` is `_isAgentRunActive`: the whole prompt, sleeps included. A
        // session that said "not streaming" during the sleep let the screen send what was typed
        // as a prompt of its own — which waited for this one to settle, and started Working the
        // moment the notification for this one went out.
        $session = $this->start([['error' => 'Anthropic returned 503: overloaded'], 'retried'], settings: Settings::inMemory([
            'retry' => ['baseDelayMs' => 60_000],
        ]));
        $parked = false;
        $session->subscribe(static function (AgentEvent $event) use (&$parked): void {
            if ($event instanceof RetryStartEvent) {
                $parked = true;
            }
        });

        Async::run(static function () use ($session): void {
            Async::spawn(static fn () => $session->prompt('hi'));
        });

        for ($tick = 0; $tick < 200 && !$parked; $tick++) {
            self::tickWithoutWaiting();
        }

        $this->assertTrue($parked);
        $this->assertTrue($session->isStreaming(), 'busy while the retry sleeps');
        $this->assertFalse($session->isIdle());

        // Typed during the sleep: queued for this prompt, which is upstream's `steer`.
        $session->steer('also this');
        $session->abortRetry();
        self::settle();
    }

    // ---- (d) overflow ------------------------------------------------------------------

    public function testAnOverflowSummarisedAndCarriedOnSettlesOnceForThatPrompt(): void
    {
        $session = $this->start(
            ['first answer', 'second answer', ['error' => 'prompt is too long: 213462 tokens > 200000 maximum'], 'the summary', 'answered after summarising'],
            settings: Settings::inMemory(['retry' => ['baseDelayMs' => 1], 'compaction' => ['keepRecentTokens' => 1]]),
        );

        Async::run(static function () use ($session): void {
            $session->prompt('one');
            $session->prompt('two');
        });
        $this->log = [];
        $this->atSettle = [];

        Async::run(static fn () => $session->prompt('three'));
        self::settle();

        $this->assertSame('answered after summarising', self::textOf($session->messages()[count($session->messages()) - 1]));
        $this->assertSettledOnceAtTheEnd();
    }

    // ---- (e) the end of a run reported twice --------------------------------------------

    public function testAListenerThatThrowsOnAgentEndDoesNotSettleThePromptTwice(): void
    {
        $session = $this->start(['first', 'never asked for']);
        $thrown = false;
        $session->subscribe(static function (AgentEvent $event) use (&$thrown): void {
            if ($event instanceof AgentEndEvent && !$thrown) {
                $thrown = true;

                throw new RuntimeException('a renderer fell over');
            }
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSettledOnceAtTheEnd();
    }

    // ---- (h) a run that ends early while its producer keeps going -------------------------

    public function testAListenerThatThrowsMidRunLeavesNothingRunningAfterTheSettle(): void
    {
        $session = $this->start([['tool' => 'noop'], 'after the tool']);
        $thrown = false;
        $session->subscribe(static function (AgentEvent $event) use (&$thrown): void {
            if ($event instanceof MessageStartEvent && $event->message instanceof AssistantMessage && !$thrown) {
                $thrown = true;

                throw new RuntimeException('a renderer fell over');
            }
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        // Upstream's loop awaits each listener, so a throw stops the loop where it is. Here the
        // loop runs in a fiber of its own; ended or not, it must not go on running tools and
        // asking the model after the prompt has settled.
        $this->assertSame([], $this->toolRuns, 'no tool ran after the run was over');
        $this->assertSame(1, $this->calls, 'the model was not asked again');
        $this->assertSettledOnceAtTheEnd();
    }

    // ---- agent_before_settle -------------------------------------------------------------

    public function testABeforeSettleHandlerCanAppendAMessageAndAskForOneMoreRequest(): void
    {
        $seen = [];
        $session = $this->start(['first', 'second'], static function (HookApi $pi) use (&$seen): void {
            $pi->on('agent_before_settle', static function (AgentBeforeSettleEvent $event) use (&$seen): ?BoundaryResult {
                $seen[] = [$event->outcome, $event->continue, $event->context->canContinue, count($event->context->pendingMessages)];

                if (count($seen) > 1) {
                    return null;
                }

                return new BoundaryResult(
                    entries: [new CustomMessageDraft('nudge', 'one more thing'), new CustomEntryDraft('note', ['n' => 1])],
                    continue: true,
                );
            });
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $messages = $session->messages();
        $this->assertSame('second', self::textOf($messages[count($messages) - 1]), 'the request the handler asked for was made');
        $this->assertInstanceOf(HookMessage::class, $messages[count($messages) - 2], 'the drafted message is in the conversation, in front of it');
        $this->assertSame([['completed', false, false, 0], ['completed', false, false, 0]], $seen, 'asked once per boundary: before the first answer settled, and again after the second');
        $this->assertSettledOnceAtTheEnd();
    }

    public function testAContinueTheContextCannotCarryIsRefusedAndReported(): void
    {
        $errors = [];
        $session = $this->start(['first', 'second'], static function (HookApi $pi): void {
            $pi->on('agent_before_settle', static fn (): BoundaryResult => new BoundaryResult(continue: true));
        });
        $session->hooks()?->onError(static function (HookError $error) use (&$errors): void {
            $errors[] = $error->error;
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $messages = $session->messages();
        $this->assertSame('first', self::textOf($messages[count($messages) - 1]), 'nothing was appended, so a second request would end on the assistant\'s turn');
        $this->assertSame(['agent_before_settle requested continuation without runnable model context'], $errors);
        $this->assertSettledOnceAtTheEnd();
    }

    public function testAHandlerSeesWhatTheOneBeforeItDraftedAndAnEditOfNothingIsRefused(): void
    {
        $second = null;
        $session = $this->start(['first'], static function (HookApi $pi) use (&$second): void {
            $pi->on('agent_before_settle', static fn (): BoundaryResult => new BoundaryResult(entries: [new CustomMessageDraft('a', 'from a')]));
            $pi->on('agent_before_settle', static function (AgentBeforeSettleEvent $event) use (&$second): BoundaryResult {
                $second = $event;

                return new BoundaryResult(entries: [...$event->entries, new ContextEditDraft('no-such-entry')]);
            });
        });
        $errors = [];
        $session->hooks()?->onError(static function (HookError $error) use (&$errors): void {
            $errors[] = $error->error;
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertCount(1, $second->entries);
        $this->assertTrue($second->context->canContinue, 'the first handler\'s message would let a request go');
        $this->assertStringStartsWith('Invalid boundary entries:', $errors[0] ?? '');
        $this->assertNotInstanceOf(HookMessage::class, $session->messages()[count($session->messages()) - 1], 'an invalid list appends nothing, not even its good half');
        $this->assertSettledOnceAtTheEnd();
    }

    public function testADraftedCompactionReachesTheFileAndTheAgentsOwnState(): void
    {
        $dir = sys_get_temp_dir() . '/pig-settle-' . bin2hex(random_bytes(4));
        $store = SessionManager::create($dir);
        $session = $this->start(['first', 'second'], static function (HookApi $pi): void {
            $once = false;
            $pi->on('agent_before_settle', static function (AgentBeforeSettleEvent $event) use (&$once): ?BoundaryResult {
                if ($once) {
                    return null;
                }

                $once = true;
                // Keep nothing but the summary, then ask the model what it was about.
                return new BoundaryResult(
                    entries: [new CompactionDraft('we said hi'), new CustomMessageDraft('ask', 'what did we say?')],
                    continue: true,
                );
            });
        }, store: $store);

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $messages = $session->messages();
        $this->assertInstanceOf(CompactionSummary::class, $messages[1] ?? null, 'the agent now holds the compacted conversation');
        $this->assertTrue($messages[1]->fromHook);
        $this->assertSame('second', self::textOf($messages[count($messages) - 1]));

        $reopened = SessionManager::open($store->path)->messages();
        $this->assertSame(array_map(get_class(...), $messages), array_map(get_class(...), $reopened), 'and the file projects the same');
        $this->assertSettledOnceAtTheEnd();
        exec('rm -rf ' . escapeshellarg($dir));
    }

    // ---- helpers ------------------------------------------------------------------------

    private function assertSettledOnceAtTheEnd(): void
    {
        $order = implode(' ', $this->log);
        $settles = array_keys($this->log, 'agent_settled', true);

        $this->assertCount(1, $settles, "one agent_settled per prompt; order was: {$order}");
        $this->assertSame(count($this->log) - 1, $settles[0], "nothing ran after agent_settled; order was: {$order}");
        $this->assertFalse($this->atSettle[0]['streaming'], "not streaming at settle; order was: {$order}");
    }

    /** Run $then once, from inside the first assistant answer's `message_end` — the moment a key pressed as the run ends lands. */
    private function onFirstAnswer(AgentSession $session, Closure $then): void
    {
        $done = false;
        $session->subscribe(static function (AgentEvent $event) use (&$done, $then): void {
            if ($done || !$event instanceof MessageEndEvent || !$event->message instanceof AssistantMessage) {
                return;
            }

            $done = true;
            $then();
        });
    }

    /**
     * @param list<string|array{error: string}|array{tool: string}> $script
     * @param (Closure(HookApi): void)|null $register
     */
    private function start(array $script, ?Closure $register = null, ?Settings $settings = null, bool $extension = false, ?SessionManager $store = null): AgentSession
    {
        $this->script = $script;
        $api = $extension ? new ExtensionApi('.', 'test.php', 'test') : new HookApi('.', 'test.php');

        $api->on('agent_start', function (): void {
            $this->log[] = 'agent_start';
        });
        $api->on('agent_end', function (): void {
            $this->log[] = 'agent_end';
        });
        $api->on('agent_settled', function (): void {
            $this->log[] = 'agent_settled';
            $this->atSettle[] = [
                'streaming' => $this->session?->isStreaming() ?? false,
                'idle' => $this->session?->isIdle() ?? true,
            ];
        });

        if ($register !== null) {
            $register($api);
        }

        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], sys_get_temp_dir());

        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'test-key'));
        $agent->setModel(new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000));
        $agent->setTools([$this->tool()]);

        $session = new AgentSession($agent, sys_get_temp_dir(), $store, $settings ?? Settings::inMemory(['retry' => ['baseDelayMs' => 1]]), $hooks);
        $this->session = $session;

        $hooks->initialize(
            getModel: static fn () => $session->model(),
            isIdle: static fn (): bool => !$session->isStreaming(),
            send: static function (HookMessage $message, bool $triggerTurn) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn);
            },
        );
        $hooks->setSession($session);

        return $session;
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $turn = $this->script[$this->calls++] ?? throw new RuntimeException('out of scripted turns');
        $stream = new AssistantMessageEventStream();

        if (is_string($turn)) {
            $message = self::assistant([new TextContent($turn)], StopReason::Stop);
        } elseif (isset($turn['tool'])) {
            $message = self::assistant([new ToolCall('call-' . $this->calls, $turn['tool'], [])], StopReason::ToolUse);
        } else {
            $message = self::assistant([new TextContent('')], StopReason::Error, $turn['error']);
        }

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push($message->stopReason === StopReason::Error
                ? new ErrorEvent(StopReason::Error, $message)
                : new DoneEvent($message->stopReason, $message));
            $stream->end();
        });

        return $stream;
    }

    /** @param list<mixed> $content */
    private static function assistant(array $content, StopReason $reason, ?string $error = null): AssistantMessage
    {
        return new AssistantMessage($content, Api::AnthropicMessages, 'anthropic', 'test-model', new Usage(), $reason, $error);
    }

    private function tool(): AgentTool
    {
        $runs = &$this->toolRuns;

        return new class ($runs) implements AgentTool {
            /** @param list<string> $runs */
            public function __construct(private array &$runs)
            {
            }

            public function definition(): Tool
            {
                return new Tool('noop', 'does nothing', ['type' => 'object', 'properties' => []]);
            }

            public function label(): string
            {
                return 'Noop';
            }

            public function execute(string $toolCallId, array $arguments, ?AbortSignal $signal = null, ?Closure $onUpdate = null): AgentToolResult
            {
                $this->runs[] = 'noop';

                return new AgentToolResult([new TextContent('ok')]);
            }
        };
    }

    private static function settle(int $ticks = 400): void
    {
        for ($tick = 0; $tick < $ticks && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    private static function tickWithoutWaiting(): void
    {
        Loop::get()->delay(0.0, static fn () => null);
        Loop::get()->tick();
    }

    private static function textOf(mixed $message): string
    {
        $text = '';

        foreach ($message->content ?? [] as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
