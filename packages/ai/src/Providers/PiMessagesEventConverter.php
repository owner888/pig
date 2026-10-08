<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantContent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\AssistantMessageEvent;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\Timestamp;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\PartialJson;

/**
 * Upstream's `createEventConverter(model)` in `pi-messages.ts`: one message, built from the
 * backend's serialized events as they arrive, each event addressing its block by `contentIndex`.
 *
 * Upstream mutates one `partial` object and hands the same object on every event; pig's events
 * carry a snapshot each, which is the same message at that moment.
 *
 * What upstream would do with an event it has no case for — hand it on as `{...event, partial}` — has
 * no counterpart here, because pig's events are classes and a type nobody declared is not one: such
 * an event is applied to nothing and not handed on (`convert()` answers null). And upstream's
 * content array can have holes where a backend skipped an index; pig's has none, so the blocks are
 * kept in index order without them.
 */
final class PiMessagesEventConverter
{
    /** @var array<int, array<string, mixed>> the blocks, by `contentIndex`, in pi's JSON shape */
    private array $content = [];

    /** @var array<int, string> upstream's `toolJson`: a tool call's arguments so far, by content index */
    private array $toolJson = [];

    private StopReason $stopReason = StopReason::Pending;

    private Usage $usage;

    private ?string $errorMessage = null;

    private ?string $responseId = null;

    private ?string $providerThinkingLevel = null;

    /** @var list<AssistantMessageDiagnostic>|null */
    private ?array $diagnostics = null;

    private readonly int $timestamp;

    public function __construct(private readonly Model $model)
    {
        $this->usage = new Usage(0, 0, 0, 0, 0);
        $this->timestamp = Timestamp::nowMs();
    }

    /**
     * One backend event, applied, as the event pig hands on — null for a type there is no event for.
     *
     * @param array<string, mixed> $event
     */
    public function convert(array $event): ?AssistantMessageEvent
    {
        $index = is_int($event['contentIndex'] ?? null) ? $event['contentIndex'] : 0;

        switch ($event['type'] ?? null) {
            case 'done':
                $this->stopReason = self::stopReason($event['reason'] ?? null);
                $this->usage = MessageJson::decodeUsage(is_array($event['usage'] ?? null) ? $event['usage'] : []);
                $this->responseId = is_string($event['responseId'] ?? null) ? $event['responseId'] : null;
                $this->terminal($event);
                $message = $this->snapshot();

                return new DoneEvent($message->stopReason, $message);

            case 'error':
                $this->stopReason = self::stopReason($event['reason'] ?? null);
                $this->usage = MessageJson::decodeUsage(is_array($event['usage'] ?? null) ? $event['usage'] : []);
                $this->errorMessage = is_string($event['errorMessage'] ?? null) ? $event['errorMessage'] : null;
                $this->responseId = is_string($event['responseId'] ?? null) ? $event['responseId'] : null;
                $this->terminal($event);
                $message = $this->snapshot();

                return new ErrorEvent($message->stopReason, $message);

            case 'start':
                return new StartEvent($this->snapshot());

            case 'text_start':
                $this->content[$index] = ['type' => 'text', 'text' => ''];

                return new TextStartEvent($index, $this->snapshot());

            case 'text_delta':
                $delta = (string) ($event['delta'] ?? '');
                $this->append($index, 'text', $delta);

                return new TextDeltaEvent($index, $delta, $this->snapshot());

            case 'text_end':
                $this->assign($index, [
                    'text' => (string) ($event['content'] ?? ''),
                    'textSignature' => is_string($event['contentSignature'] ?? null) ? $event['contentSignature'] : null,
                ]);

                return new TextEndEvent($index, (string) ($event['content'] ?? ''), $this->snapshot());

            case 'thinking_start':
                $this->content[$index] = ['type' => 'thinking', 'thinking' => ''];

                return new ThinkingStartEvent($index, $this->snapshot());

            case 'thinking_delta':
                $delta = (string) ($event['delta'] ?? '');
                $this->append($index, 'thinking', $delta);

                return new ThinkingDeltaEvent($index, $delta, $this->snapshot());

            case 'thinking_end':
                $this->assign($index, [
                    'thinking' => (string) ($event['content'] ?? ''),
                    'thinkingSignature' => is_string($event['contentSignature'] ?? null) ? $event['contentSignature'] : null,
                    'redacted' => is_bool($event['redacted'] ?? null) ? $event['redacted'] : null,
                ]);

                return new ThinkingEndEvent($index, (string) ($event['content'] ?? ''), $this->snapshot());

            case 'toolcall_start':
                $this->content[$index] = [
                    'type' => 'toolCall',
                    'id' => (string) ($event['id'] ?? ''),
                    'name' => (string) ($event['toolName'] ?? ''),
                    'arguments' => [],
                ];
                $this->toolJson[$index] = '';

                return new ToolCallStartEvent($index, $this->snapshot());

            case 'toolcall_delta':
                $delta = (string) ($event['delta'] ?? '');
                $json = ($this->toolJson[$index] ?? '') . $delta;
                $this->toolJson[$index] = $json;
                $this->assign($index, ['arguments' => PartialJson::parse($json)]);

                return new ToolCallDeltaEvent($index, $delta, $this->snapshot());

            case 'toolcall_end':
                $this->assign($index, is_array($event['toolCall'] ?? null) ? $event['toolCall'] : []);
                unset($this->toolJson[$index]);
                $message = $this->snapshot();
                $call = self::block($this->content[$index]);

                if (!$call instanceof ToolCall) {
                    throw new ProviderError("pi-messages toolcall_end at {$index} is not a tool call");
                }

                return new ToolCallEndEvent($index, $call, $message);
        }

        return null;
    }

    /**
     * The terminal event's `providerThinkingLevel` (only when it has one) and its `rewrite`, as a
     * `pi_messages_rewrite` diagnostic — upstream's `appendRewriteDiagnostic()`.
     *
     * @param array<string, mixed> $event
     */
    private function terminal(array $event): void
    {
        if (array_key_exists('providerThinkingLevel', $event) && is_string($event['providerThinkingLevel'])) {
            $this->providerThinkingLevel = $event['providerThinkingLevel'];
        }

        if (is_array($event['rewrite'] ?? null)) {
            $this->diagnostics = [
                ...($this->diagnostics ?? []),
                new AssistantMessageDiagnostic('pi_messages_rewrite', Timestamp::nowMs(), details: $event['rewrite']),
            ];
        }
    }

    /** `(partial.content[i] as {text: string}).text += delta`: a missing block is V8's TypeError. */
    private function append(int $index, string $field, string $delta): void
    {
        if (!isset($this->content[$index])) {
            throw new ProviderError("Cannot read properties of undefined (reading '{$field}')");
        }

        $this->content[$index][$field] = (string) ($this->content[$index][$field] ?? '') . $delta;
    }

    /**
     * `Object.assign(partial.content[i], fields)`: a missing block is V8's TypeError.
     *
     * @param array<string, mixed> $fields
     */
    private function assign(int $index, array $fields): void
    {
        if (!isset($this->content[$index])) {
            throw new ProviderError('Cannot convert undefined or null to object');
        }

        $this->content[$index] = [...$this->content[$index], ...$fields];
    }

    private static function stopReason(mixed $reason): StopReason
    {
        return (is_string($reason) ? StopReason::tryFrom($reason) : null)
            ?? throw new ProviderError('pi-messages terminal event has an unknown reason: ' . (is_string($reason) ? $reason : json_encode($reason)));
    }

    /** @param array<string, mixed> $block */
    private static function block(array $block): ?AssistantContent
    {
        return match ($block['type'] ?? null) {
            'text' => new TextContent((string) ($block['text'] ?? ''), is_string($block['textSignature'] ?? null) ? $block['textSignature'] : null),
            'thinking' => new ThinkingContent(
                (string) ($block['thinking'] ?? ''),
                is_string($block['thinkingSignature'] ?? null) ? $block['thinkingSignature'] : null,
                is_bool($block['redacted'] ?? null) ? $block['redacted'] : null,
            ),
            'toolCall' => new ToolCall(
                (string) ($block['id'] ?? ''),
                (string) ($block['name'] ?? ''),
                is_array($block['arguments'] ?? null) ? $block['arguments'] : [],
                is_string($block['thoughtSignature'] ?? null) ? $block['thoughtSignature'] : null,
                is_string($block['namespace'] ?? null) ? $block['namespace'] : null,
            ),
            default => null,
        };
    }

    private function snapshot(): AssistantMessage
    {
        $content = $this->content;
        ksort($content);

        return new AssistantMessage(
            array_values(array_filter(array_map(self::block(...), $content))),
            $this->model->api,
            $this->model->provider,
            $this->model->id,
            $this->usage,
            $this->stopReason,
            $this->errorMessage,
            $this->timestamp,
            responseId: $this->responseId,
            diagnostics: $this->diagnostics,
            providerThinkingLevel: $this->providerThinkingLevel,
        );
    }
}
