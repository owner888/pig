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
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\CodingAgent\Session\SessionCodec;

/**
 * What the agent is doing, as JSON a host can render.
 *
 * Upstream calls `JSON.stringify(event)` and is done: its events are plain objects, so the
 * wire shape is whatever the class happens to hold. PHP has classes, so the mapping is
 * written out — which is more work and a better deal, because it is the only place the
 * protocol's event shape is stated and it cannot drift from a field rename in silence.
 *
 * Messages go through `SessionCodec`, the same encoder the session file uses. One shape for
 * a message, whether it is being written to disk or handed to an editor, and one place to
 * fix when a message type gains a field.
 *
 * `type` is the class's short name in `snake_case`, so `MessageStartEvent` is
 * `message_start`. Upstream's are already that, being string literals.
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

            $event instanceof AgentEndEvent => [
                'type' => 'agent_end',
                'messages' => self::messages($event->messages),
            ],

            $event instanceof TurnEndEvent => [
                'type' => 'turn_end',
                'message' => SessionCodec::encode($event->message),
                'toolResults' => self::messages($event->toolResults),
            ],

            $event instanceof MessageStartEvent => [
                'type' => 'message_start',
                'message' => SessionCodec::encode($event->message),
            ],

            // The delta is what makes streaming renderable, and the partial message beside
            // it is what makes a host that missed one able to catch up: every update carries
            // the whole message so far, so a dropped frame costs nothing.
            $event instanceof MessageUpdateEvent => [
                'type' => 'message_update',
                'message' => SessionCodec::encode($event->message),
                'delta' => self::delta($event->assistantMessageEvent),
            ],

            $event instanceof MessageEndEvent => [
                'type' => 'message_end',
                'message' => SessionCodec::encode($event->message),
            ],

            $event instanceof ToolExecutionStartEvent => [
                'type' => 'tool_execution_start',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'arguments' => $event->arguments,
            ],

            $event instanceof ToolExecutionUpdateEvent => [
                'type' => 'tool_execution_update',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'arguments' => $event->arguments,
                'partial' => self::result($event->partialResult),
            ],

            $event instanceof ToolExecutionEndEvent => [
                'type' => 'tool_execution_end',
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'result' => self::result($event->result),
                'isError' => $event->isError,
            ],

            default => null,
        };
    }

    /**
     * One streaming event from the provider.
     *
     * The delta events share a shape — an index into the content, and something new for it —
     * so they share an encoder. `contentIndex` says which block is growing, which is what a
     * host needs to put the text in the right place rather than at the end.
     *
     * @return array<string, mixed>
     */
    public static function delta(AssistantMessageEvent $event): array
    {
        $encoded = ['type' => self::name($event)];

        if (property_exists($event, 'contentIndex')) {
            $encoded['contentIndex'] = $event->contentIndex;
        }

        return match (true) {
            $event instanceof TextDeltaEvent,
            $event instanceof ThinkingDeltaEvent,
            $event instanceof ToolCallDeltaEvent => [...$encoded, 'delta' => $event->delta],

            $event instanceof TextEndEvent => [...$encoded, 'content' => $event->content],

            $event instanceof ToolCallEndEvent => [
                ...$encoded,
                'toolCall' => [
                    'id' => $event->toolCall->id,
                    'name' => $event->toolCall->name,
                    'arguments' => $event->toolCall->arguments,
                ],
            ],

            $event instanceof DoneEvent, $event instanceof ErrorEvent => [
                ...$encoded,
                'stopReason' => $event->reason->value,
            ],

            // The ones that carry nothing of their own: text_start, thinking_start,
            // thinking_end, tool_call_start, start. The index is the whole message.
            default => $encoded,
        };
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
        ];
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

    /** `TextDeltaEvent` becomes `text_delta`; the `Event` suffix carries no information. */
    private static function name(object $event): string
    {
        $short = substr($event::class, (int) strrpos($event::class, '\\') + 1);
        $short = preg_replace('/Event$/', '', $short) ?? $short;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}
