<?php

declare(strict_types=1);

namespace Pig\Agent;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageEvent;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Utils\EventStream;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * The loop: ask the model, run what it asks for, ask again.
 *
 * Two loops, actually. The inner one keeps going while the model wants tools or the user
 * has steered; the outer one restarts it when a follow-up message arrives after the agent
 * would have stopped. Everything above works in whatever messages the app keeps, and the
 * only place that becomes LLM messages is `streamAssistantResponse()`.
 *
 * Ported from upstream's agent-loop.ts — the file behind the "418 lines" of pi.
 */
final class AgentLoop
{
    /**
     * Start a run with new prompts, which are added to the context and announced.
     *
     * @param list<mixed>                                     $prompts
     * @param Closure(mixed, Context, SimpleStreamOptions): mixed|null $streamFn a stand-in for
     *        the provider — a proxy backend, or a script in a test
     * @param (Closure(AgentEvent): void)|null $emit called with each event, in this loop's fiber,
     *        before the loop goes on — upstream's awaited `emit`. See `emitter()`.
     * @return EventStream<AgentEvent, list<mixed>>
     */
    public static function start(
        array $prompts,
        AgentContext $context,
        AgentLoopConfig $config,
        ?AbortSignal $signal = null,
        ?Closure $streamFn = null,
        ?Closure $emit = null,
    ): EventStream {
        $stream = self::newStream();
        $emit = self::emitter($stream, $emit);
        $newMessages = $prompts;
        $current = new AgentContext(
            [...$context->messages, ...$prompts],
            $context->systemPrompt,
            $context->tools,
        );

        // The try/catch is the whole reason `fail()` exists: this body runs in a fiber of its
        // own, so a throw here — a provider that cannot resolve a hostname, a missing key —
        // does not reach the `foreach` in `Agent`, and the stream would simply never close.
        Async::spawn(static function () use ($prompts, $current, &$newMessages, $config, $signal, $stream, $streamFn, $emit): void {
            try {
                $emit(new AgentStartEvent());
                $emit(new TurnStartEvent());

                foreach ($prompts as $prompt) {
                    $emit(new MessageStartEvent($prompt));
                    $emit(new MessageEndEvent($prompt));
                }

                self::run($current, $newMessages, $config, $signal, $emit, $streamFn);
                $stream->end($newMessages);
            } catch (Throwable $error) {
                $stream->fail($error);
            }
        });

        return $stream;
    }

    /**
     * Carry on from the context as it stands, adding nothing. Used to retry.
     *
     * The last message must become a user or toolResult message through `convertToLlm`,
     * or the provider rejects the request. Only the assistant case can be caught here,
     * since convertToLlm runs once per turn and not before this.
     *
     * @param (Closure(AgentEvent): void)|null $emit as for `start()`
     * @return EventStream<AgentEvent, list<mixed>>
     */
    public static function continue(
        AgentContext $context,
        AgentLoopConfig $config,
        ?AbortSignal $signal = null,
        ?Closure $streamFn = null,
        ?Closure $emit = null,
    ): EventStream {
        if ($context->messages === []) {
            throw new AgentError('Cannot continue: no messages in context');
        }

        if ($context->messages[count($context->messages) - 1] instanceof AssistantMessage) {
            throw new AgentError('Cannot continue from an assistant message');
        }

        $stream = self::newStream();
        $emit = self::emitter($stream, $emit);
        $newMessages = [];
        $current = new AgentContext($context->messages, $context->systemPrompt, $context->tools);

        Async::spawn(static function () use ($current, &$newMessages, $config, $signal, $stream, $streamFn, $emit): void {
            try {
                $emit(new AgentStartEvent());
                $emit(new TurnStartEvent());

                self::run($current, $newMessages, $config, $signal, $emit, $streamFn);
                $stream->end($newMessages);
            } catch (Throwable $error) {
                $stream->fail($error);
            }
        });

        return $stream;
    }

    /**
     * How the loop hands an event on.
     *
     * Without a listener: pushed onto the stream, for whoever iterates it — and the loop does not
     * wait for them, so it can be several events ahead of its reader.
     *
     * With one — `Agent` passes its own — the listener runs here, in the loop's fiber, and the
     * loop goes on only when it has returned. Upstream's `runAgentLoop(..., emit)`, which awaits
     * every listener. It matters in two places: the queues are read *after* the `turn_end`
     * listeners, so a message queued from one is in this run; and a listener that throws stops
     * the loop where it is, where a reader that had fallen behind left the loop running tools
     * for a run that had already ended. Only the run's end goes onto the stream then, for the
     * result.
     *
     * @param EventStream<AgentEvent, list<mixed>> $stream
     * @param (Closure(AgentEvent): void)|null     $listener
     * @return Closure(AgentEvent): void
     */
    private static function emitter(EventStream $stream, ?Closure $listener): Closure
    {
        if ($listener === null) {
            return static function (AgentEvent $event) use ($stream): void {
                $stream->push($event);
            };
        }

        return static function (AgentEvent $event) use ($stream, $listener): void {
            $listener($event);

            if ($event instanceof AgentEndEvent) {
                $stream->push($event);
            }
        };
    }

    /** @return EventStream<AgentEvent, list<mixed>> */
    private static function newStream(): EventStream
    {
        return new EventStream(
            static fn (AgentEvent $event): bool => $event instanceof AgentEndEvent,
            static fn (AgentEvent $event): array => $event instanceof AgentEndEvent ? $event->messages : [],
        );
    }

    /**
     * @param list<mixed>               $newMessages
     * @param Closure(AgentEvent): void $emit
     */
    private static function run(
        AgentContext $context,
        array &$newMessages,
        AgentLoopConfig $config,
        ?AbortSignal $signal,
        Closure $emit,
        ?Closure $streamFn,
    ): void {
        $firstTurn = true;
        // Checked before the first call too: the user may have typed while waiting.
        $pending = self::call($config->getSteeringMessages);

        // Outer: restarts when a follow-up arrives after the agent would have stopped.
        while (true) {
            $hasMoreToolCalls = true;
            $steeringAfterTools = null;

            // Inner: tool calls and steering.
            while ($hasMoreToolCalls || $pending !== []) {
                if ($firstTurn) {
                    $firstTurn = false;
                } else {
                    $emit(new TurnStartEvent());
                }

                foreach ($pending as $message) {
                    $emit(new MessageStartEvent($message));
                    $emit(new MessageEndEvent($message));
                    $context->append($message);
                    $newMessages[] = $message;
                }

                $pending = [];

                if ($config->getTools !== null) {
                    $context->tools = ($config->getTools)();
                }

                $message = self::streamAssistantResponse($context, $config, $signal, $emit, $streamFn);
                $newMessages[] = $message;

                if ($message->stopReason->isFailure()) {
                    $emit(new TurnEndEvent($message, []));
                    $emit(new AgentEndEvent($newMessages));

                    return;
                }

                $toolCalls = $message->toolCalls();
                $hasMoreToolCalls = $toolCalls !== [];
                $toolResults = [];

                if ($hasMoreToolCalls) {
                    [$toolResults, $steeringAfterTools] = self::executeToolCalls(
                        $context,
                        $message,
                        $signal,
                        $emit,
                        $config,
                    );

                    foreach ($toolResults as $result) {
                        $context->append($result);
                        $newMessages[] = $result;
                    }
                }

                $emit(new TurnEndEvent($message, $toolResults));

                if ($steeringAfterTools !== null && $steeringAfterTools !== []) {
                    $pending = $steeringAfterTools;
                    $steeringAfterTools = null;

                    continue;
                }

                $pending = self::call($config->getSteeringMessages);
            }

            $followUp = self::call($config->getFollowUpMessages);

            if ($followUp === []) {
                break;
            }

            $pending = $followUp;
        }

        $emit(new AgentEndEvent($newMessages));
    }

    /**
     * Ask the model, streaming the answer out as it arrives.
     *
     * The one place the app's messages become LLM messages.
     *
     * @param Closure(AgentEvent): void $emit
     */
    private static function streamAssistantResponse(
        AgentContext $context,
        AgentLoopConfig $config,
        ?AbortSignal $signal,
        Closure $emit,
        ?Closure $streamFn,
    ): AssistantMessage {
        $messages = $context->messages;

        if ($config->transformContext !== null) {
            $messages = ($config->transformContext)($messages, $signal);
        }

        $llmContext = new Context(
            ($config->convertToLlm)($messages),
            $context->systemPrompt,
            array_map(static fn (AgentTool $tool) => $tool->definition(), $context->tools),
        );

        // Resolved now rather than at setup: a token can expire during a long tool phase.
        $apiKey = $config->getApiKey !== null
            ? ($config->getApiKey)($config->model->provider) ?? $config->apiKey
            : $config->apiKey;

        $options = new SimpleStreamOptions(
            $config->temperature,
            $config->maxTokens,
            $signal,
            $apiKey,
            $config->reasoning,
            sessionId: $config->sessionId,
        );

        $response = $streamFn !== null
            ? $streamFn($config->model, $llmContext, $options)
            : Stream::simple($config->model, $llmContext, $options);

        $added = false;

        foreach ($response as $event) {
            if ($event instanceof StartEvent) {
                $context->append($event->partial);
                $added = true;
                $emit(new MessageStartEvent($event->partial));

                continue;
            }

            if ($event instanceof DoneEvent || $event instanceof ErrorEvent) {
                $final = $response->result()->await();

                if ($added) {
                    $context->messages[count($context->messages) - 1] = $final;
                } else {
                    $context->append($final);
                    $emit(new MessageStartEvent($final));
                }

                $emit(new MessageEndEvent($final));

                return $final;
            }

            $partial = self::partialOf($event);

            if ($partial !== null && $added) {
                $context->messages[count($context->messages) - 1] = $partial;
                $emit(new MessageUpdateEvent($partial, $event));
            }
        }

        return $response->result()->await();
    }

    /**
     * The message so far, for the events that carry one.
     *
     * Spelled out rather than inferred: pig/ai has no marker for "streaming event", and a
     * silent null here would drop updates instead of failing.
     */
    private static function partialOf(AssistantMessageEvent $event): ?AssistantMessage
    {
        return match (true) {
            $event instanceof TextStartEvent,
            $event instanceof TextDeltaEvent,
            $event instanceof TextEndEvent,
            $event instanceof ThinkingStartEvent,
            $event instanceof ThinkingDeltaEvent,
            $event instanceof ThinkingEndEvent,
            $event instanceof ToolCallStartEvent,
            $event instanceof ToolCallDeltaEvent,
            $event instanceof ToolCallEndEvent => $event->partial,
            default => null,
        };
    }

    /**
     * Run the tools the assistant asked for, in order.
     *
     * A steering message between tools stops the rest: the remaining calls still get a
     * result, saying they were skipped, because a tool_use with no tool_result is a
     * malformed conversation that the provider will reject on the next turn.
     *
     * @param Closure(AgentEvent): void $emit
     * @return array{0: list<ToolResultMessage>, 1: list<mixed>|null}
     */
    private static function executeToolCalls(
        AgentContext $context,
        AssistantMessage $message,
        ?AbortSignal $signal,
        Closure $emit,
        AgentLoopConfig $config,
    ): array {
        $toolCalls = $message->toolCalls();
        $results = [];
        $steering = null;

        foreach ($toolCalls as $index => $toolCall) {
            $emit(new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments));

            $isError = false;

            try {
                $tool = $context->toolNamed($toolCall->name);

                if ($tool === null) {
                    throw new AgentError("Tool {$toolCall->name} not found");
                }

                $arguments = ToolArguments::validate($tool->definition(), $toolCall);

                $result = $tool->execute(
                    $toolCall->id,
                    $arguments,
                    $signal,
                    static function (AgentToolResult $partial) use ($emit, $toolCall): void {
                        $emit(new ToolExecutionUpdateEvent(
                            $toolCall->id,
                            $toolCall->name,
                            $toolCall->arguments,
                            $partial,
                        ));
                    },
                );
            } catch (Throwable $error) {
                // A failing tool is something the model can read and work around, not
                // something that ends the run. An `AgentError` may carry what the UI should
                // still be shown of the failure.
                $result = new AgentToolResult([new TextContent($error->getMessage())], $error instanceof AgentError ? $error->details : null);
                $isError = true;
            }

            $emit(new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $result, $isError));

            $results[] = self::toolResult($toolCall, $result, $isError, $emit);

            if ($config->getSteeringMessages === null) {
                continue;
            }

            $queued = self::call($config->getSteeringMessages);

            if ($queued === []) {
                continue;
            }

            $steering = $queued;

            foreach (array_slice($toolCalls, $index + 1) as $skipped) {
                $results[] = self::toolResult(
                    $skipped,
                    new AgentToolResult([new TextContent('Skipped due to queued user message.')]),
                    true,
                    $emit,
                    announce: true,
                );
            }

            break;
        }

        return [$results, $steering];
    }

    /** @param Closure(AgentEvent): void $emit */
    private static function toolResult(
        ToolCall $toolCall,
        AgentToolResult $result,
        bool $isError,
        Closure $emit,
        bool $announce = false,
    ): ToolResultMessage {
        if ($announce) {
            // A skipped call never ran, so its start and end are reported here instead.
            $emit(new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments));
            $emit(new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $result, true));
        }

        $message = new ToolResultMessage(
            $toolCall->id,
            $toolCall->name,
            $result->content,
            $isError,
            $result->details,
        );

        $emit(new MessageStartEvent($message));
        $emit(new MessageEndEvent($message));

        return $message;
    }

    /** @return list<mixed> */
    private static function call(?Closure $source): array
    {
        if ($source === null) {
            return [];
        }

        $messages = $source();

        return is_array($messages) ? array_values($messages) : [];
    }
}
