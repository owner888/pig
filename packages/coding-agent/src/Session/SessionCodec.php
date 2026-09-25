<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Utils\MessageJson;

/**
 * Session entries to JSON and back.
 *
 * The three message types of `Pig\Ai` are `Ai\Utils\MessageJson`'s, and this delegates them: the
 * same shape goes to a session file, to an RPC host and to `Agent\StreamProxy`'s gateway, and
 * `agent-core` cannot reach into this package to get it. What is left here is the three roles only
 * pig has — a compaction summary, a branch summary, a bash execution — plus the `encodeContent()`,
 * `decodeContent()` and `plain()` names that the rest of `coding-agent` already calls.
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
        $shared = MessageJson::encode($message);

        if ($shared !== null) {
            return $shared;
        }

        return match (true) {
            $message instanceof CompactionSummary => [
                'role' => 'compactionSummary',
                'summary' => $message->summary,
                'readFiles' => $message->readFiles,
                'modifiedFiles' => $message->modifiedFiles,
                'tokensBefore' => $message->tokensBefore,
                'firstKeptEntryId' => $message->firstKeptEntryId,
                'fromHook' => $message->fromHook,
                'timestamp' => $message->timestamp,
            ],
            $message instanceof BranchSummary => [
                'role' => 'branchSummary',
                'summary' => $message->summary,
                'readFiles' => $message->readFiles,
                'modifiedFiles' => $message->modifiedFiles,
                'fromId' => $message->fromId,
                'fromHook' => $message->fromHook,
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
        $shared = MessageJson::decode($entry);

        if ($shared !== null) {
            return $shared;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : null;

        return match ($entry['role'] ?? null) {
            'compactionSummary' => new CompactionSummary(
                (string) ($entry['summary'] ?? ''),
                array_values(array_map(strval(...), (array) ($entry['readFiles'] ?? []))),
                array_values(array_map(strval(...), (array) ($entry['modifiedFiles'] ?? []))),
                (int) ($entry['tokensBefore'] ?? 0),
                isset($entry['firstKeptEntryId']) ? (string) $entry['firstKeptEntryId'] : null,
                (int) ($entry['replaced'] ?? 0),
                $timestamp,
                ($entry['fromHook'] ?? false) === true,
            ),
            'branchSummary' => new BranchSummary(
                (string) ($entry['summary'] ?? ''),
                array_values(array_map(strval(...), (array) ($entry['readFiles'] ?? []))),
                array_values(array_map(strval(...), (array) ($entry['modifiedFiles'] ?? []))),
                isset($entry['fromId']) ? (string) $entry['fromId'] : null,
                (bool) ($entry['fromHook'] ?? false),
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
     * Content blocks as JSON.
     *
     * Kept as a name here because `RpcMode` and `SessionEntries` call it: RPC mode hands tool
     * results to a host and has to encode them the same way the session file does.
     *
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    public static function encodeContent(array $content): array
    {
        return MessageJson::encodeContent($content);
    }

    /**
     * Content blocks back from JSON.
     *
     * @param list<mixed> $blocks
     * @return list<mixed>
     */
    public static function decodeContent(array $blocks): array
    {
        return MessageJson::decodeContent($blocks);
    }

    /**
     * A tool's `details`, flattened to something JSON holds.
     *
     * Public for the same reason as `encodeContent()`: RPC mode needs this shape too.
     */
    public static function plain(mixed $details): mixed
    {
        return MessageJson::plain($details);
    }
}
