<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
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
            ],
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
                ],
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
