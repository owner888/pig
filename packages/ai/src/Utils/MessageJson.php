<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\Cost;
use Pig\Ai\DiagnosticErrorInfo;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use stdClass;

/**
 * The three message types of `Pig\Ai`, to JSON and back.
 *
 * Upstream needs none of this: a message there is a plain object and `JSON.stringify` is the whole
 * of its persistence layer. PHP objects do not survive that trip, so the shapes are written out by
 * hand — which is more code, and also the only place that has to change when a message type gains
 * a field.
 *
 * The wire format is upstream's, so a session file from either project can be read by the other:
 * `{"role": "user", "content": [{"type": "text", "text": "…"}], "timestamp": …}`.
 *
 * **It lives in `pig/ai`, next to the types it encodes, because three different things send this
 * exact shape**: the session file, RPC mode's tool results, and `Agent\StreamProxy`'s request to a
 * gateway. Upstream has them share it by accident — they are the same objects through the same
 * `JSON.stringify` — and the first version of this had a copy in `coding-agent` that
 * `agent-core` could not reach, which would have meant a second hand-written notion of what a
 * message looks like. That is the mistake `Agent\ToolArguments` had already made once about tool
 * schemas; twice is a pattern, so it moved here instead.
 *
 * `CodingAgent\Session\SessionCodec` still owns the roles that only pig has — a compaction
 * summary, a branch summary, a bash execution — and delegates these three.
 */
final class MessageJson
{
    /**
     * @return array<string, mixed>|null null for anything that is not one of the three
     */
    public static function encode(mixed $message): ?array
    {
        return match (true) {
            $message instanceof UserMessage => [
                'role' => 'user',
                'content' => self::encodeContent($message->content),
                'timestamp' => $message->timestamp,
            ],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'content' => self::encodeContent($message->content),
                'api' => $message->api->value,
                'provider' => $message->provider,
                'model' => $message->model,
                'usage' => self::encodeUsage($message->usage),
                'stopReason' => $message->stopReason->value,
                'errorMessage' => $message->errorMessage,
                'timestamp' => $message->timestamp,
            ] + self::optional($message),
            $message instanceof ToolResultMessage => [
                'role' => 'toolResult',
                'toolCallId' => $message->toolCallId,
                'toolName' => $message->toolName,
                'content' => self::encodeContent($message->content),
                'isError' => $message->isError,
                'details' => self::plain($message->details),
                'timestamp' => $message->timestamp,
            ],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @return UserMessage|AssistantMessage|ToolResultMessage|null null for any other role
     */
    public static function decode(array $entry): mixed
    {
        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;

        return match ($entry['role'] ?? null) {
            'user' => new UserMessage(self::decodeContent($entry['content'] ?? []), $timestamp),
            'assistant' => new AssistantMessage(
                self::decodeContent($entry['content'] ?? []),
                Api::tryFrom((string) ($entry['api'] ?? '')) ?? Api::AnthropicMessages,
                (string) ($entry['provider'] ?? ''),
                (string) ($entry['model'] ?? ''),
                self::decodeUsage((array) ($entry['usage'] ?? [])),
                StopReason::tryFrom((string) ($entry['stopReason'] ?? '')) ?? StopReason::Stop,
                $entry['errorMessage'] ?? null,
                $timestamp,
                isset($entry['rawStopReason']) ? (string) $entry['rawStopReason'] : null,
                isset($entry['responseId']) ? (string) $entry['responseId'] : null,
                isset($entry['responseModel']) ? (string) $entry['responseModel'] : null,
                isset($entry['endTurn']) ? (bool) $entry['endTurn'] : null,
                is_array($entry['diagnostics'] ?? null) ? self::decodeDiagnostics($entry['diagnostics']) : null,
                isset($entry['providerThinkingLevel']) ? (string) $entry['providerThinkingLevel'] : null,
            ),
            'toolResult' => new ToolResultMessage(
                (string) ($entry['toolCallId'] ?? ''),
                (string) ($entry['toolName'] ?? ''),
                self::decodeContent($entry['content'] ?? []),
                (bool) ($entry['isError'] ?? false),
                $entry['details'] ?? null,
                $timestamp,
            ),
            default => null,
        };
    }

    /**
     * The optional fields — `rawStopReason`, `responseId`, `responseModel`, `endTurn`,
     * `diagnostics`, `providerThinkingLevel` — each one present or not there at all.
     *
     * Upstream's fields are optional and `JSON.stringify` drops an undefined one, so a message
     * without them is written without the keys rather than with nulls — the shape a session file
     * from before the fields existed already has.
     *
     * @return array<string, mixed>
     */
    private static function optional(AssistantMessage $message): array
    {
        return array_filter([
            'rawStopReason' => $message->rawStopReason,
            'responseId' => $message->responseId,
            'responseModel' => $message->responseModel,
            'endTurn' => $message->endTurn,
            'diagnostics' => $message->diagnostics === null
                ? null
                : array_map(self::encodeDiagnostic(...), $message->diagnostics),
            'providerThinkingLevel' => $message->providerThinkingLevel,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> upstream's `AssistantMessageDiagnostic`, absent fields left out */
    private static function encodeDiagnostic(AssistantMessageDiagnostic $diagnostic): array
    {
        $error = $diagnostic->error === null ? null : array_filter([
            'name' => $diagnostic->error->name,
            'message' => $diagnostic->error->message,
            'stack' => $diagnostic->error->stack,
            'code' => $diagnostic->error->code,
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'type' => $diagnostic->type,
            'timestamp' => $diagnostic->timestamp,
            'error' => $error,
            // `{}` and not `[]`, for the same reason as a tool call's arguments: it is an object.
            'details' => $diagnostic->details === null
                ? null
                : ($diagnostic->details === [] ? new stdClass() : $diagnostic->details),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<mixed> $diagnostics
     * @return list<AssistantMessageDiagnostic>
     */
    private static function decodeDiagnostics(array $diagnostics): array
    {
        $out = [];

        foreach ($diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            $error = is_array($diagnostic['error'] ?? null) ? $diagnostic['error'] : null;
            $code = $error['code'] ?? null;

            $out[] = new AssistantMessageDiagnostic(
                (string) ($diagnostic['type'] ?? ''),
                (int) ($diagnostic['timestamp'] ?? 0),
                $error === null ? null : new DiagnosticErrorInfo(
                    (string) ($error['message'] ?? ''),
                    isset($error['name']) ? (string) $error['name'] : null,
                    isset($error['stack']) ? (string) $error['stack'] : null,
                    is_string($code) || is_int($code) ? $code : null,
                ),
                is_array($diagnostic['details'] ?? null) ? $diagnostic['details'] : null,
            );
        }

        return $out;
    }

    /**
     * Content blocks as JSON.
     *
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    public static function encodeContent(array $content): array
    {
        $blocks = [];

        foreach ($content as $block) {
            $encoded = match (true) {
                $block instanceof TextContent => ['type' => 'text', 'text' => $block->text],
                $block instanceof ThinkingContent => [
                    'type' => 'thinking',
                    'thinking' => $block->thinking,
                    'thinkingSignature' => $block->thinkingSignature,
                ] + ($block->redacted === null ? [] : ['redacted' => $block->redacted]),
                $block instanceof ImageContent => [
                    'type' => 'image',
                    'data' => $block->data,
                    'mimeType' => $block->mimeType,
                ],
                $block instanceof ToolCall => [
                    'type' => 'toolCall',
                    'id' => $block->id,
                    'name' => $block->name,
                    // `{}` and not `[]`: this file is pi's, and pi hands the history straight
                    // back to a provider — Anthropic refuses an `input` that is a list.
                    'arguments' => $block->arguments === [] ? new stdClass() : $block->arguments,
                    'thoughtSignature' => $block->thoughtSignature,
                ],
                default => null,
            };

            if ($encoded !== null) {
                $blocks[] = $encoded;
            }
        }

        return $blocks;
    }

    /**
     * Content blocks back from JSON.
     *
     * @param list<mixed> $blocks
     * @return list<mixed>
     */
    public static function decodeContent(array $blocks): array
    {
        $content = [];

        foreach ($blocks as $block) {
            $decoded = match ($block['type'] ?? null) {
                'text' => new TextContent((string) ($block['text'] ?? '')),
                'thinking' => new ThinkingContent(
                    (string) ($block['thinking'] ?? ''),
                    $block['thinkingSignature'] ?? null,
                    isset($block['redacted']) ? (bool) $block['redacted'] : null,
                ),
                'image' => new ImageContent(
                    (string) ($block['data'] ?? ''),
                    (string) ($block['mimeType'] ?? ''),
                ),
                'toolCall' => new ToolCall(
                    (string) ($block['id'] ?? ''),
                    (string) ($block['name'] ?? ''),
                    (array) ($block['arguments'] ?? []),
                    $block['thoughtSignature'] ?? null,
                ),
                default => null,
            };

            if ($decoded !== null) {
                $content[] = $decoded;
            }
        }

        return $content;
    }

    /** @return array<string, mixed> */
    public static function encodeUsage(Usage $usage): array
    {
        return [
            'input' => $usage->input,
            'output' => $usage->output,
            'cacheRead' => $usage->cacheRead,
            'cacheWrite' => $usage->cacheWrite,
        ] + array_filter([
            // Upstream's optional splits, left out when the provider reported none.
            'cacheWrite1h' => $usage->cacheWrite1h,
            'reasoning' => $usage->reasoning,
        ], static fn (?int $value): bool => $value !== null) + [
            'totalTokens' => $usage->totalTokens,
            'cost' => [
                'input' => $usage->cost->input,
                'output' => $usage->cost->output,
                'cacheRead' => $usage->cost->cacheRead,
                'cacheWrite' => $usage->cost->cacheWrite,
                'total' => $usage->cost->total,
            ],
        ];
    }

    /** @param array<string, mixed> $usage */
    public static function decodeUsage(array $usage): Usage
    {
        $cost = (array) ($usage['cost'] ?? []);

        return new Usage(
            (int) ($usage['input'] ?? 0),
            (int) ($usage['output'] ?? 0),
            (int) ($usage['cacheRead'] ?? 0),
            (int) ($usage['cacheWrite'] ?? 0),
            (int) ($usage['totalTokens'] ?? 0),
            new Cost(
                (float) ($cost['input'] ?? 0),
                (float) ($cost['output'] ?? 0),
                (float) ($cost['cacheRead'] ?? 0),
                (float) ($cost['cacheWrite'] ?? 0),
                (float) ($cost['total'] ?? 0),
            ),
            isset($usage['reasoning']) ? (int) $usage['reasoning'] : null,
            isset($usage['cacheWrite1h']) ? (int) $usage['cacheWrite1h'] : null,
        );
    }

    /**
     * A tool's `details`, flattened to something JSON holds.
     *
     * Tools put whatever they like in there — an array for `edit`, a `Truncation` object for the
     * ones that truncate. Objects come back as arrays after a resume, because a file cannot hold a
     * PHP object; the one thing anything reads out of `details` is `edit`'s diff, which is strings
     * and integers and survives the trip exactly.
     */
    public static function plain(mixed $details): mixed
    {
        if (is_object($details)) {
            return self::plain(get_object_vars($details));
        }

        if (!is_array($details)) {
            return $details;
        }

        return array_map(self::plain(...), $details);
    }
}
