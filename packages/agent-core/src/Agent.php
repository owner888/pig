<?php

declare(strict_types=1);

namespace Pig\Agent;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Throwable;

/**
 * A conversation that can be talked to.
 *
 * `AgentLoop` runs one exchange and forgets it; this holds the conversation across many,
 * keeps the state a UI draws from, and owns the two queues that let someone interrupt —
 * `steer()` cuts in mid-run, `followUp()` waits until the agent would have stopped.
 *
 * Ported from upstream's agent.ts. No transport layer: it calls the loop, which calls
 * the provider.
 */
final class Agent
{
    public AgentState $state;

    /**
     * Upstream's `Agent.sessionId`: handed to every request as `SimpleStreamOptions::$sessionId`, so
     * a provider that routes or caches by session sees one per conversation. `AgentSession` sets it
     * to the session file's id; null sends none.
     */
    public ?string $sessionId = null;

    /** @var array<int, Closure(AgentEvent): void> */
    private array $listeners = [];

    private int $nextListenerId = 0;

    private ?AbortController $controller = null;

    private readonly Closure $convertToLlm;

    private readonly ?Closure $transformContext;

    /** @var list<mixed> */
    private array $steeringQueue = [];

    /** @var list<mixed> */
    private array $followUpQueue = [];

    private ?Deferred $running = null;

    /**
     * How each queue is handed over, copied out of the options because it can change.
     *
     * `AgentOptions` is readonly and stays that way: it is what this agent was *set up* with,
     * and a settings screen changing a field on it would make the record of the setup a record
     * of the present instead.
     */
    private QueueMode $steeringMode;

    private QueueMode $followUpMode;

    public function __construct(private readonly AgentOptions $options = new AgentOptions())
    {
        $this->state = $options->initialState ?? new AgentState();
        $this->convertToLlm = $options->convertToLlm ?? self::defaultConvertToLlm(...);
        $this->transformContext = $options->transformContext;
        $this->steeringMode = $options->steeringMode;
        $this->followUpMode = $options->followUpMode;
    }

    /**
     * The setup this agent was given.
     *
     * For the one thing that needs a request made outside the loop: compaction asks the
     * model to summarise the conversation, and it has to be the same model, the same key
     * and — in a test — the same stand-in provider, or it is not summarising this session.
     */
    public function options(): AgentOptions
    {
        return $this->options;
    }

    /**
     * Listen for events.
     *
     * @param Closure(AgentEvent): void $listener
     * @return Closure(): void call it to stop listening
     */
    public function subscribe(Closure $listener): Closure
    {
        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->state->systemPrompt = $prompt;
    }

    public function setModel(Model $model): void
    {
        $this->state->model = $model;
    }

    public function setThinkingLevel(ThinkingLevel $level): void
    {
        $this->state->thinkingLevel = $level;
    }

    /** @param list<AgentTool> $tools */
    public function setTools(array $tools): void
    {
        $this->state->tools = $tools;
    }

    /** @return list<AgentTool> */
    public function tools(): array
    {
        return $this->state->tools;
    }

    /** @param list<mixed> $messages */
    public function replaceMessages(array $messages): void
    {
        $this->state->messages = array_values($messages);
    }

    public function appendMessage(mixed $message): void
    {
        $this->state->messages[] = $message;
    }

    public function clearMessages(): void
    {
        $this->state->messages = [];
    }

    /**
     * Cut in while the agent is working.
     *
     * Delivered after the tool that is running now; whatever tools were queued behind it
     * are skipped. This is "no, not that file — the other one".
     */
    public function steer(mixed $message): void
    {
        $this->steeringQueue[] = $message;
    }

    /**
     * Queue something for after the agent finishes.
     *
     * Delivered only once there are no tool calls and nothing steering, so it reads as a
     * second request rather than an interruption.
     */
    public function followUp(mixed $message): void
    {
        $this->followUpQueue[] = $message;
    }

    public function clearSteeringQueue(): void
    {
        $this->steeringQueue = [];
    }

    public function clearFollowUpQueue(): void
    {
        $this->followUpQueue = [];
    }

    public function clearAllQueues(): void
    {
        $this->steeringQueue = [];
        $this->followUpQueue = [];
    }

    /**
     * Whether either queue still holds something. Upstream's `hasQueuedMessages()`.
     *
     * What the session asks after a run: the loop drains both queues before `agent_end`, but a
     * message queued after its last look — by an `agent_end` handler, or a key pressed as the run
     * was ending — is still here, and it belongs to the same prompt.
     */
    public function hasQueuedMessages(): bool
    {
        return $this->steeringQueue !== [] || $this->followUpQueue !== [];
    }

    /** Stop the current run. Does nothing when idle. */
    public function abort(): void
    {
        $this->controller?->abort('Operation aborted');
    }

    /**
     * The signal for the run in progress, or null between runs.
     *
     * So that something waiting on its own behalf during a turn — a hook running a command,
     * say — can be cut short by the same escape that stops the turn, rather than having to be
     * waited out. Null when idle, because the controller is cleared in `run()`'s `finally`:
     * handing back the last run's signal would mean an already-aborted signal for anything
     * asking after an interrupted turn, and everything it touched would stop instantly.
     *
     * Upstream has no counterpart — its equivalents take an `AbortSignal` from the caller and
     * there is nowhere for that caller to get one.
     */
    public function signal(): ?AbortSignal
    {
        return $this->controller?->signal;
    }

    /** Resolves when no run is in flight — immediately, if none is. */
    public function waitForIdle(): Future
    {
        return $this->running?->future ?? Future::complete(null);
    }

    /** Forget the conversation and everything queued. The configuration stays. */
    public function reset(): void
    {
        $this->state->messages = [];
        $this->state->isStreaming = false;
        $this->state->streamMessage = null;
        $this->state->pendingToolCalls = [];
        $this->state->error = null;
        $this->clearAllQueues();
    }

    /**
     * Say something and run until the agent is done.
     *
     * @param string|object|list<mixed> $input  text, one message, or several
     * @param list<ImageContent>        $images attached to $input when it is text
     */
    public function prompt(string|object|array $input, array $images = []): void
    {
        if ($this->state->isStreaming) {
            throw new AgentError(
                'Agent is already working. Use steer() or followUp() to queue a message, or waitForIdle().',
            );
        }

        $this->run($this->asMessages($input, $images));
    }

    /**
     * Run again from the conversation as it stands, adding nothing.
     *
     * For retrying after a failure — an overflow, a provider hiccup — where the last
     * message is already the right one to answer.
     */
    public function continue(): void
    {
        if ($this->state->isStreaming) {
            throw new AgentError('Agent is already working. Wait for it to finish before continuing.');
        }

        // **Checked here as well as in the loop, and here is the half that matters.**
        // `AgentLoop::continue()` refuses both of these too, but it throws *inside* `run()`'s try —
        // so the caller saw nothing thrown and the conversation got a fabricated failed assistant
        // turn instead, which is a misuse of this method written into somebody's session file.
        // Upstream checks before the loop for the same reason.
        if ($this->state->messages === []) {
            throw new AgentError('No messages to continue from');
        }

        // Upstream's `continue()`: from an assistant message there is nothing to answer, unless
        // something is queued — then the queue is the next prompt, steering first. This is how a
        // session carries a prompt on when a message was queued after the loop's last look.
        if ($this->state->messages[count($this->state->messages) - 1] instanceof AssistantMessage) {
            $steering = $this->take($this->steeringQueue, $this->steeringMode);

            if ($steering !== []) {
                $this->run($steering, skipInitialSteeringPoll: true);

                return;
            }

            $followUps = $this->take($this->followUpQueue, $this->followUpMode);

            if ($followUps !== []) {
                $this->run($followUps);

                return;
            }

            throw new AgentError('Cannot continue from an assistant message');
        }

        $this->run(null);
    }

    /**
     * @param string|object|list<mixed> $input
     * @param list<ImageContent>        $images
     * @return list<mixed>
     */
    private function asMessages(string|object|array $input, array $images): array
    {
        if (is_array($input)) {
            return array_values($input);
        }

        if (!is_string($input)) {
            return [$input];
        }

        return [new UserMessage([new TextContent($input), ...$images])];
    }

    /**
     * Set for a run that starts from steering messages, so the loop's first look at the steering
     * queue does not take the next one into the same turn. Upstream's `skipInitialSteeringPoll`.
     */
    private bool $skipInitialSteeringPoll = false;

    /** @param list<mixed>|null $prompts null continues from the existing context */
    private function run(?array $prompts, bool $skipInitialSteeringPoll = false): void
    {
        $model = $this->state->model;

        if ($model === null) {
            throw new AgentError('No model configured');
        }

        $this->running = new Deferred();
        $this->controller = new AbortController();
        $this->skipInitialSteeringPoll = $skipInitialSteeringPoll;
        $this->state->isStreaming = true;
        $this->state->streamMessage = null;
        $this->state->error = null;

        $context = new AgentContext($this->state->messages, $this->state->systemPrompt, $this->state->tools);
        $config = $this->config($model);
        $partial = null;

        // Each event is applied and handed to the listeners *in the loop's fiber*, before the loop
        // goes on — upstream's `runAgentLoop(..., emit)` with its awaited listeners. This used to
        // iterate the loop's stream from here instead, and the loop does not wait for its reader:
        // it read the queues before the `turn_end` listeners had run, and when a listener threw it
        // went on running tools for a run that this side had already ended.
        $emit = function (AgentEvent $event) use (&$partial): void {
            $partial = $this->apply($event, $partial);
            $this->emit($event);
        };

        try {
            $stream = $prompts !== null
                ? AgentLoop::start($prompts, $context, $config, $this->controller->signal, $this->options->streamFn, $emit)
                : AgentLoop::continue($context, $config, $this->controller->signal, $this->options->streamFn, $emit);

            $stream->result()->await();

            $this->keepUnfinished($partial);
        } catch (Throwable $error) {
            $this->recordFailure($model, $error);
        } finally {
            $this->state->isStreaming = false;
            $this->state->streamMessage = null;
            $this->state->pendingToolCalls = [];
            $this->controller = null;
            $this->running?->complete(null);
            $this->running = null;
        }
    }

    /**
     * How the follow-up queue is handed over, which is what "queue mode" means to a person.
     *
     * **Upstream has one `setQueueMode()` and pig has two queues**, which is the anchor commit's
     * own break: `d0a4c37` split the single queue into `steer()` and `followUp()` and changed
     * `packages/agent` only, so `agent-session.ts` at that same commit still calls a method that
     * no longer exists. There is no working original to copy, so this picks the queue the setting
     * is about: a message typed while the agent is working is a **follow-up** — that is where
     * `InteractiveMode`'s submit handler puts it — and steering is a different act with a
     * different key. `setSteeringMode()` arrives if something ever wants it separately.
     */
    public function setQueueMode(QueueMode $mode): void
    {
        $this->followUpMode = $mode;
    }

    public function queueMode(): QueueMode
    {
        return $this->followUpMode;
    }

    private function config(Model $model): AgentLoopConfig
    {
        return new AgentLoopConfig(
            model: $model,
            convertToLlm: $this->convertToLlm,
            reasoning: $this->state->thinkingLevel->toReasoning(),
            transformContext: $this->transformContext,
            getSteeringMessages: function (): array {
                if ($this->skipInitialSteeringPoll) {
                    $this->skipInitialSteeringPoll = false;

                    return [];
                }

                return $this->take($this->steeringQueue, $this->steeringMode);
            },
            getFollowUpMessages: fn (): array => $this->take($this->followUpQueue, $this->followUpMode),
            getApiKey: $this->options->getApiKey,
            apiKey: $this->options->apiKey,
            getTools: fn (): array => $this->state->tools,
            sessionId: $this->sessionId,
        );
    }

    /**
     * @param list<mixed> $queue
     * @return list<mixed>
     */
    private function take(array &$queue, QueueMode $mode): array
    {
        if ($queue === []) {
            return [];
        }

        if ($mode === QueueMode::OneAtATime) {
            return [array_shift($queue)];
        }

        $all = $queue;
        $queue = [];

        return $all;
    }

    /** Mirror the event into the state a UI reads. */
    private function apply(AgentEvent $event, mixed $partial): mixed
    {
        return match (true) {
            $event instanceof MessageStartEvent, $event instanceof MessageUpdateEvent => $this->startOrUpdate($event),
            $event instanceof MessageEndEvent => $this->finishMessage($event),
            $event instanceof ToolExecutionStartEvent => $this->markRunning($event->toolCallId, $partial),
            $event instanceof ToolExecutionEndEvent => $this->markDone($event->toolCallId, $partial),
            $event instanceof TurnEndEvent => $this->recordTurnError($event, $partial),
            $event instanceof AgentEndEvent => $this->finish($partial),
            default => $partial,
        };
    }

    private function startOrUpdate(MessageStartEvent|MessageUpdateEvent $event): mixed
    {
        $this->state->streamMessage = $event->message;

        return $event->message;
    }

    private function finishMessage(MessageEndEvent $event): mixed
    {
        $this->state->streamMessage = null;
        $this->appendMessage($event->message);

        return null;
    }

    private function markRunning(string $toolCallId, mixed $partial): mixed
    {
        $this->state->pendingToolCalls[] = $toolCallId;

        return $partial;
    }

    private function markDone(string $toolCallId, mixed $partial): mixed
    {
        $this->state->pendingToolCalls = array_values(
            array_filter($this->state->pendingToolCalls, static fn (string $id): bool => $id !== $toolCallId),
        );

        return $partial;
    }

    private function recordTurnError(TurnEndEvent $event, mixed $partial): mixed
    {
        if ($event->message instanceof AssistantMessage && $event->message->errorMessage !== null) {
            $this->state->error = $event->message->errorMessage;
        }

        return $partial;
    }

    /**
     * The loop has nothing more to say. **Not** idle yet: upstream's `agent_end` reduction clears
     * the streaming message only, and the run stays active until `run()`'s `finally`, after every
     * listener of this event has returned. Clearing `isStreaming` here let an `agent_end` listener
     * start a second run inside the first one's fan-out, whose state the first run's `finally`
     * then cleared from under it.
     */
    private function finish(mixed $partial): mixed
    {
        $this->state->streamMessage = null;

        return $partial;
    }

    /**
     * Keep a half-finished assistant message when the run ended mid-stream.
     *
     * Worth keeping only if it actually said something: an abort that landed between the
     * first event and the first token leaves an empty shell, and that is an error, not a
     * message the user should see in their history.
     */
    private function keepUnfinished(mixed $partial): void
    {
        if (!$partial instanceof AssistantMessage || $partial->content === []) {
            return;
        }

        $saidSomething = false;

        foreach ($partial->content as $block) {
            $saidSomething = $saidSomething || match (true) {
                $block instanceof TextContent => trim($block->text) !== '',
                $block instanceof ThinkingContent => trim($block->thinking) !== '',
                $block instanceof ToolCall => trim($block->name) !== '',
                default => false,
            };
        }

        if ($saidSomething) {
            $this->appendMessage($partial);

            return;
        }

        if ($this->controller?->signal->aborted() ?? false) {
            throw new AgentError('Request was aborted');
        }
    }

    private function recordFailure(Model $model, Throwable $error): void
    {
        $aborted = $this->controller?->signal->aborted() ?? false;

        $message = new AssistantMessage(
            [new TextContent('')],
            $model->api,
            $model->provider,
            $model->id,
            new Usage(),
            $aborted ? StopReason::Aborted : StopReason::Error,
            $error->getMessage(),
        );

        $this->appendMessage($message);
        $this->state->error = $error->getMessage();
        $this->emit(new AgentEndEvent([$message]));
    }

    private function emit(AgentEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    /**
     * Keep what the model understands and drop the rest.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    private static function defaultConvertToLlm(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (mixed $message): bool => $message instanceof UserMessage
                || $message instanceof AssistantMessage
                || $message instanceof ToolResultMessage,
        ));
    }
}
