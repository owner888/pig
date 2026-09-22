<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

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

/**
 * Messages to JSON and back.
 *
 * Upstream needs none of this: a message there is a plain object and `JSON.stringify`
 * is the whole of its persistence layer. PHP objects do not survive that trip, so the
 * shapes are written out by hand — which is more code, and also the only place that has
 * to change when a message type gains a field.
 *
 * The wire format is upstream's, so a session file from either can be read by the other:
 * `{"role": "user", "content": [{"type": "text", "text": "…"}], "timestamp": …}`.
 */
final class SessionCodec
{
    /**
     * @return array<string, mixed>|null null for a message type that is not persisted
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
            $message instanceof CompactionSummary => [
                'role' => 'compactionSummary',
                'summary' => $message->summary,
                'readFiles' => $message->readFiles,
                'modifiedFiles' => $message->modifiedFiles,
                'tokensBefore' => $message->tokensBefore,
                'replaced' => $message->replaced,
                'timestamp' => $message->timestamp,
            ],
            $message instanceof BashExecution => [
                'role' => 'bashExecution',
                'command' => $message->command,
                'output' => $message->output,
                'exitCode' => $message->exitCode,
                'cancelled' => $message->cancelled,
                'truncated' => $message->truncated,
                'spillPath' => $message->spillPath,
                'timestamp' => $message->timestamp,
            ],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @return mixed|null null for a role written by something newer than this
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
                self::decodeUsage($entry['usage'] ?? []),
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
            'compactionSummary' => new CompactionSummary(
                (string) ($entry['summary'] ?? ''),
                array_values(array_map(strval(...), (array) ($entry['readFiles'] ?? []))),
                array_values(array_map(strval(...), (array) ($entry['modifiedFiles'] ?? []))),
                (int) ($entry['tokensBefore'] ?? 0),
                (int) ($entry['replaced'] ?? 0),
                $timestamp,
            ),
            'bashExecution' => new BashExecution(
                (string) ($entry['command'] ?? ''),
                (string) ($entry['output'] ?? ''),
                isset($entry['exitCode']) ? (int) $entry['exitCode'] : null,
                (bool) ($entry['cancelled'] ?? false),
                (bool) ($entry['truncated'] ?? false),
                $entry['spillPath'] ?? null,
                $timestamp,
            ),
            default => null,
        };
    }

    /**
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function encodeContent(array $content): array
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
                    'arguments' => $block->arguments,
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
     * @param list<array<string, mixed>> $blocks
     * @return list<mixed>
     */
    private static function decodeContent(array $blocks): array
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
    private static function encodeUsage(Usage $usage): array
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
    private static function decodeUsage(array $usage): Usage
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
     * Tools put whatever they like in there — an array for `edit`, a `Truncation` object
     * for the ones that truncate. Objects come back as arrays after a resume, because a
     * file cannot hold a PHP object; the one thing anything reads out of `details` is
     * `edit`'s diff, which is strings and integers and survives the trip exactly.
     */
    private static function plain(mixed $details): mixed
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
