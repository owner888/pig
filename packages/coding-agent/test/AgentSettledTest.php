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
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Events\BeforeRetryEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\BeforeRetryResult;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
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

    public function testBeforeRetryResettingTheCountWithAShortDelaySettlesOnce(): void
    {
        $quota = ['error' => 'Antigravity returned 429: RESOURCE_EXHAUSTED quota'];
        $rotations = 0;
        $session = $this->start([$quota, $quota, $quota, 'on the third account'], static function (HookApi $pi) use (&$rotations): void {
            $pi->on('before_retry', static function (BeforeRetryEvent $event) use (&$rotations): ?BeforeRetryResult {
                return $rotations++ < 2 ? new BeforeRetryResult(delaySeconds: 0.001, resetAttempts: true) : null;
            });
        });

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame('on the third account', self::textOf($session->messages()[count($session->messages()) - 1]));
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
    private function start(array $script, ?Closure $register = null, ?Settings $settings = null, bool $extension = false): AgentSession
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

        $session = new AgentSession($agent, sys_get_temp_dir(), null, $settings ?? Settings::inMemory(['retry' => ['baseDelayMs' => 1]]), $hooks);
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

    private function provider(Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream
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
