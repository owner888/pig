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

    public function __construct(private readonly AgentOptions $options = new AgentOptions())
    {
        $this->state = $options->initialState ?? new AgentState();
        $this->convertToLlm = $options->convertToLlm ?? self::defaultConvertToLlm(...);
        $this->transformContext = $options->transformContext;
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

    /** Stop the current run. Does nothing when idle. */
    public function abort(): void
    {
        $this->controller?->abort('Aborted');
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

    /** @param list<mixed>|null $prompts null continues from the existing context */
    private function run(?array $prompts): void
    {
        $model = $this->state->model;

        if ($model === null) {
            throw new AgentError('No model configured');
        }

        $this->running = new Deferred();
        $this->controller = new AbortController();
        $this->state->isStreaming = true;
        $this->state->streamMessage = null;
        $this->state->error = null;

        $context = new AgentContext($this->state->messages, $this->state->systemPrompt, $this->state->tools);
        $config = $this->config($model);
        $partial = null;

        try {
            $stream = $prompts !== null
                ? AgentLoop::start($prompts, $context, $config, $this->controller->signal, $this->options->streamFn)
                : AgentLoop::continue($context, $config, $this->controller->signal, $this->options->streamFn);

            foreach ($stream as $event) {
                $partial = $this->apply($event, $partial);
                $this->emit($event);
            }

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

    private function config(Model $model): AgentLoopConfig
    {
        return new AgentLoopConfig(
            model: $model,
            convertToLlm: $this->convertToLlm,
            reasoning: $this->state->thinkingLevel->toReasoning(),
            transformContext: $this->transformContext,
            getSteeringMessages: fn (): array => $this->take($this->steeringQueue, $this->options->steeringMode),
            getFollowUpMessages: fn (): array => $this->take($this->followUpQueue, $this->options->followUpMode),
            getApiKey: $this->options->getApiKey,
            apiKey: $this->options->apiKey,
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

    private function finish(mixed $partial): mixed
    {
        $this->state->isStreaming = false;
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
