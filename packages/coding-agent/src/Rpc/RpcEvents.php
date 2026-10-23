<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Rpc;

use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\ToolExecutionUpdateEvent;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageEvent;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\StartEvent;
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
use Pig\Ai\Utils\MessageJson;
use Pig\CodingAgent\Hooks\Events\SessionInfoChangedEvent;
use Pig\CodingAgent\Session\AgentSettledEvent;
use Pig\CodingAgent\Session\BashExecutionUpdateEvent;
use Pig\CodingAgent\Session\CompactionEndEvent;
use Pig\CodingAgent\Session\CompactionResult;
use Pig\CodingAgent\Session\CompactionStartEvent;
use Pig\CodingAgent\Session\EntryAppendedEvent;
use Pig\CodingAgent\Session\ModelFallbackEvent;
use Pig\CodingAgent\Session\QueueUpdateEvent;
use Pig\CodingAgent\Session\RetryEndEvent;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Session\SessionCodec;
use Pig\CodingAgent\Session\SessionEntries;
use Pig\CodingAgent\Session\SummarizationRetryEvent;
use Pig\CodingAgent\Session\ThinkingLevelChangedEvent;
use RuntimeException;
use stdClass;

/**
 * What the agent is doing, as JSON a host can render — upstream's `JsonAgentSessionEvent`, the shape
 * `--mode json` and `--mode rpc` both write (`modes/json-event.ts`).
 *
 * Upstream calls `JSON.stringify(toJsonEvent(event))` and is done: its events are plain objects, so the
 * wire shape is whatever the object holds. PHP has classes, so the mapping is written out — more work,
 * and the one place the protocol's event shape is stated, which cannot drift from a field rename in
 * silence. **Every name here is upstream's**, so a client written against pi reads pig unchanged; the
 * few that are pig's alone (`model_fallback`, `agent_end.error`) are additions a pi client ignores.
 *
 * Messages go through `SessionCodec`, the same encoder the session file uses: one shape for a message,
 * whether it is being written to disk or handed to an editor.
 *
 * **`message_update` carries the delta and not the message.** Upstream's `toJsonEvent()` strips the
 * cumulative `partial` from every streaming event — `message_start` gives the message, the deltas build
 * it, `message_end` is the final word — and keeps only what is constant in size: the usage so far, and a
 * `toolcall_start`'s id and name. pig used to send the whole message so far on every update, which is
 * quadratic in the length of an answer.
 */
final class RpcEvents
{
    /**
     * One agent event as an array, or null for one with nothing a host could use.
     *
     * @return array<string, mixed>|null
     */
    public static function encode(AgentEvent $event): ?array
    {
        return match (true) {
            $event instanceof AgentStartEvent => ['type' => 'agent_start'],
            $event instanceof TurnStartEvent => ['type' => 'turn_start'],

            // Upstream's session `agent_end`: the messages and `willRetry`. `error` is pig's, so a
            // host need not walk the messages to learn why a turn produced no answer.
            $event instanceof AgentEndEvent => array_filter([
                'type' => 'agent_end',
                'messages' => self::messages($event->messages),
                'willRetry' => $event->willRetry,
                'error' => self::lastError($event->messages),
            ], static fn (mixed $v): bool => $v !== null),

            $event instanceof AgentSettledEvent => ['type' => 'agent_settled', 'aborted' => $event->aborted],

            $event instanceof TurnEndEvent => [
                'type' => 'turn_end',
                'message' => SessionCodec::encode($event->message),
                'toolResults' => self::messages($event->toolResults),
            ],

            $event instanceof MessageStartEvent => [
                'type' => 'message_start',
                'message' => SessionCodec::encode($event->message),
            ],

            $event instanceof MessageUpdateEvent => self::messageUpdate($event),

            $event instanceof MessageEndEvent => [
                'type' => 'message_end',
                'message' => SessionCodec::encode($event->message),
            ],

            $event instanceof ToolExecutionStartEvent => [
                'type' => 'tool_execution_start',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'args' => self::object($event->arguments),
                ...self::parent($event->parentToolCallId),
            ],

            $event instanceof ToolExecutionUpdateEvent => [
                'type' => 'tool_execution_update',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'args' => self::object($event->arguments),
                'partialResult' => self::result($event->partialResult),
                ...self::parent($event->parentToolCallId),
            ],

            $event instanceof ToolExecutionEndEvent => [
                'type' => 'tool_execution_end',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'result' => self::result($event->result),
                'isError' => $event->isError,
                ...($event->durationMs === null ? [] : ['durationMs' => $event->durationMs]),
                ...self::parent($event->parentToolCallId),
            ],

            $event instanceof QueueUpdateEvent => [
                'type' => 'queue_update',
                'steering' => $event->steering,
                'followUp' => $event->followUp,
            ],

            $event instanceof EntryAppendedEvent => [
                'type' => 'entry_appended',
                'entry' => SessionEntries::encode($event->entry->message, $event->entry->id, $event->entry->parentId),
            ],

            $event instanceof ThinkingLevelChangedEvent => [
                'type' => 'thinking_level_changed',
                'level' => $event->level->value,
            ],

            // Upstream's `auto_retry_start` / `auto_retry_end`: the session's own, from between one
            // run and the next. See `AgentEvent`'s docblock about why they share a stream.
            $event instanceof RetryStartEvent => [
                'type' => 'auto_retry_start',
                'attempt' => $event->attempt,
                'maxAttempts' => $event->maxAttempts,
                'delayMs' => (int) round($event->delaySeconds * 1000),
                'errorMessage' => $event->error,
            ],

            $event instanceof RetryEndEvent => [
                'type' => 'auto_retry_end',
                'success' => $event->succeeded,
                'attempt' => $event->attempts,
                ...($event->error === null ? [] : ['finalError' => $event->error]),
            ],

            // pig's own, no upstream counterpart: the session moved to a `fallbackModels` entry.
            $event instanceof ModelFallbackEvent => [
                'type' => 'model_fallback',
                'from' => ['provider' => $event->from->provider, 'id' => $event->from->id],
                'to' => ['provider' => $event->to->provider, 'id' => $event->to->id],
                'error' => $event->error,
            ],

            // Upstream's three summarization retry events, under its names and fields.
            $event instanceof SummarizationRetryEvent => match ($event->phase) {
                SummarizationRetryEvent::SCHEDULED => [
                    'type' => 'summarization_retry_scheduled',
                    'attempt' => $event->attempt,
                    'maxAttempts' => $event->maxAttempts,
                    'delayMs' => (int) round($event->delaySeconds * 1000),
                    'errorMessage' => $event->error,
                ],
                SummarizationRetryEvent::ATTEMPT_START => [
                    'type' => 'summarization_retry_attempt_start',
                    'source' => $event->source,
                    ...($event->reason !== null ? ['reason' => $event->reason] : []),
                ],
                default => ['type' => 'summarization_retry_finished'],
            },

            $event instanceof CompactionStartEvent => [
                'type' => 'compaction_start',
                'reason' => $event->reason,
            ],

            // `result` is upstream's `CompactionResult | undefined`, which `JSON.stringify` leaves
            // out when the compaction did not finish; `errorMessage?` likewise when there is none.
            $event instanceof CompactionEndEvent => [
                'type' => 'compaction_end',
                'reason' => $event->reason,
                ...($event->result === null ? [] : ['result' => self::compactionResult($event->result)]),
                'aborted' => $event->aborted,
                'willRetry' => $event->willRetry,
                ...($event->errorMessage === null ? [] : ['errorMessage' => $event->errorMessage]),
            ],

            $event instanceof BashExecutionUpdateEvent => [
                'type' => 'bash_execution_update',
                ...($event->id === null ? [] : ['id' => $event->id]),
                'delta' => $event->delta,
            ],

            // `name: string | undefined` upstream: a cleared name is the key left out.
            $event instanceof SessionInfoChangedEvent => [
                'type' => 'session_info_changed',
                ...($event->name === '' ? [] : ['name' => $event->name]),
            ],

            default => null,
        };
    }

    /**
     * Upstream's `CompactionResult`, as `compact` answers it and `compaction_end` carries it.
     *
     * The optional fields are left out when they are not known, as `JSON.stringify` drops an
     * undefined one. `details` is upstream's "extension-specific data", which for pig's own summary
     * is the file lists.
     *
     * @return array<string, mixed>
     */
    public static function compactionResult(CompactionResult $result): array
    {
        return [
            'summary' => $result->summary,
            'firstKeptEntryId' => $result->firstKeptEntryId,
            'tokensBefore' => $result->tokensBefore,
            ...($result->estimatedTokensAfter === null ? [] : ['estimatedTokensAfter' => $result->estimatedTokensAfter]),
            ...($result->usage === null ? [] : ['usage' => MessageJson::encodeUsage($result->usage)]),
            ...($result->details === null ? [] : ['details' => self::object($result->details)]),
        ];
    }

    /**
     * Upstream's `toJsonEvent()` for a `message_update`: the usage so far and the streaming event
     * without its `partial`.
     *
     * @return array<string, mixed>
     */
    private static function messageUpdate(MessageUpdateEvent $event): array
    {
        if (!$event->message instanceof AssistantMessage) {
            throw new RuntimeException('message_update message is not an assistant message');
        }

        return [
            'type' => 'message_update',
            'usage' => MessageJson::encodeUsage($event->message->usage),
            'assistantMessageEvent' => self::delta($event->assistantMessageEvent),
        ];
    }

    /**
     * One streaming event from the provider, as upstream's `toJsonAssistantMessageEvent()` writes it:
     * the event's own fields, `partial` taken out, and a `toolcall_start` given the call's `id` and
     * `toolName` from that partial — the one thing a host could not otherwise know until the end.
     *
     * @return array<string, mixed>
     */
    public static function delta(AssistantMessageEvent $event): array
    {
        return match (true) {
            $event instanceof StartEvent => ['type' => 'start'],

            $event instanceof TextStartEvent => ['type' => 'text_start', 'contentIndex' => $event->contentIndex],
            $event instanceof TextDeltaEvent => ['type' => 'text_delta', 'contentIndex' => $event->contentIndex, 'delta' => $event->delta],
            $event instanceof TextEndEvent => ['type' => 'text_end', 'contentIndex' => $event->contentIndex, 'content' => $event->content],

            $event instanceof ThinkingStartEvent => ['type' => 'thinking_start', 'contentIndex' => $event->contentIndex],
            $event instanceof ThinkingDeltaEvent => ['type' => 'thinking_delta', 'contentIndex' => $event->contentIndex, 'delta' => $event->delta],
            $event instanceof ThinkingEndEvent => ['type' => 'thinking_end', 'contentIndex' => $event->contentIndex, 'content' => $event->content],

            $event instanceof ToolCallStartEvent => self::toolCallStart($event),
            $event instanceof ToolCallDeltaEvent => ['type' => 'toolcall_delta', 'contentIndex' => $event->contentIndex, 'delta' => $event->delta],
            $event instanceof ToolCallEndEvent => [
                'type' => 'toolcall_end',
                'contentIndex' => $event->contentIndex,
                'toolCall' => MessageJson::encodeContent([$event->toolCall])[0],
            ],

            $event instanceof DoneEvent => ['type' => 'done', 'reason' => $event->reason->value, 'message' => SessionCodec::encode($event->message)],
            $event instanceof ErrorEvent => ['type' => 'error', 'reason' => $event->reason->value, 'error' => SessionCodec::encode($event->error)],

            default => throw new RuntimeException('No wire shape for ' . $event::class),
        };
    }

    /** @return array<string, mixed> */
    private static function toolCallStart(ToolCallStartEvent $event): array
    {
        $call = $event->partial->content[$event->contentIndex] ?? null;

        if (!$call instanceof ToolCall) {
            throw new RuntimeException("toolcall_start content at index {$event->contentIndex} is not a tool call");
        }

        return ['type' => 'toolcall_start', 'contentIndex' => $event->contentIndex, 'id' => $call->id, 'toolName' => $call->name];
    }

    /**
     * What a tool produced, for a host.
     *
     * `details` is whatever a tool put there — a diff, a row count, an object a custom tool
     * invented — and goes out as JSON if it can. `SessionCodec::plain()` is what decides,
     * the same as for the session file.
     *
     * @return array<string, mixed>
     */
    private static function result(AgentToolResult $result): array
    {
        return [
            'content' => SessionCodec::encodeContent($result->content),
            'details' => SessionCodec::plain($result->details),
            ...($result->usage === null ? [] : ['usage' => MessageJson::encodeUsage($result->usage)]),
        ];
    }

    /**
     * An object on the wire even when it is empty: PHP's `[]` would be a JSON list, and upstream's
     * `args` and `details` are objects.
     *
     * @param array<array-key, mixed> $value
     */
    private static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass() : $value;
    }

    /**
     * `parentToolCallId` on a nested call's events, left out otherwise — upstream spreads it in
     * the same way.
     *
     * @return array<string, string>
     */
    private static function parent(?string $parentToolCallId): array
    {
        return $parentToolCallId === null ? [] : ['parentToolCallId' => $parentToolCallId];
    }

    /**
     * @param list<mixed> $messages
     * @return list<array<string, mixed>>
     */
    private static function messages(array $messages): array
    {
        $encoded = [];

        foreach ($messages as $message) {
            $one = SessionCodec::encode($message);

            if ($one !== null) {
                $encoded[] = $one;
            }
        }

        return $encoded;
    }

    /**
     * The error message from the failing turn's assistant message, if this run ended in an
     * error.
     *
     * Placed on `agent_end`'s top level so hosts and web UI clients don't have to walk the
     * messages array to learn why a turn produced no answer.
     *
     * @param list<mixed> $messages
     */
    private static function lastError(array $messages): ?string
    {
        if ($messages === []) {
            return null;
        }

        $last = $messages[array_key_last($messages)];

        if ($last instanceof AssistantMessage && $last->stopReason === \Pig\Ai\StopReason::Error) {
            return $last->errorMessage ?? 'Agent turn failed with an error.';
        }

        return null;
    }
}
