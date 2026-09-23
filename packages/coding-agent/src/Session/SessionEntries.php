<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * A line of a session file, in pi's shape.
 *
 * **`SessionCodec` encodes a message; this encodes the line it lives on.** Upstream keeps
 * them apart for a reason that only shows up here: not every line is a message. A
 * compaction, a branch summary, a hook's message and a hook's private note are each a line
 * of their own with its own `type`, and two of them are not part of the conversation at all.
 *
 * ```
 *   {"type":"session","version":2,"id":"…","timestamp":"2026-01-02T21:29:30.123Z","cwd":"…"}
 *   {"type":"message","id":"a1b2c3d4","parentId":null,"timestamp":"…","message":{"role":"user",…}}
 *   {"type":"compaction","id":"…","parentId":"…","timestamp":"…","summary":"…","firstKeptEntryId":"…"}
 * ```
 *
 * pig used to write the message flattened onto the line with `entryId` and `parent`, and an
 * integer millisecond timestamp. Same `version: 2` as pi, different shape — so pi opening a
 * pig file would agree about the version and then find no `type` on any line. That is fixed
 * here; `readOld()` still reads what pig wrote before, because a session file is a record of
 * something that happened and old ones have to keep opening.
 */
final class SessionEntries
{
    /**
     * One entry as the line it is written on, or null for something with no line.
     *
     * @param string|null $parent the entry this one hangs off
     * @return array<string, mixed>|null
     */
    public static function encode(mixed $item, string $id, ?string $parent): ?array
    {
        $base = [
            'id' => $id,
            'parentId' => $parent,
            'timestamp' => self::iso(self::timestampOf($item)),
        ];

        if ($item instanceof CustomEntry) {
            return [
                'type' => 'custom',
                ...$base,
                'customType' => $item->customType,
                'data' => SessionCodec::plain($item->data),
            ];
        }

        if ($item instanceof HookMessage) {
            return [
                'type' => 'custom_message',
                ...$base,
                'customType' => $item->customType,
                'content' => SessionCodec::encodeContent($item->content),
                'display' => $item->display,
                'details' => SessionCodec::plain($item->details),
            ];
        }

        if ($item instanceof CompactionSummary) {
            return [
                'type' => 'compaction',
                ...$base,
                'summary' => $item->summary,
                'firstKeptEntryId' => $item->firstKeptEntryId,
                'tokensBefore' => $item->tokensBefore,
                // pi's `details` is "hook-specific data"; pig puts the file lists there,
                // because they are the part a model needs exactly right and pi has nowhere
                // else for them. A pi that does not know about them carries them along
                // untouched, which is the most a foreign field can ask for.
                'details' => [
                    'readFiles' => $item->readFiles,
                    'modifiedFiles' => $item->modifiedFiles,
                ],
            ];
        }

        if ($item instanceof BranchSummary) {
            return [
                'type' => 'branch_summary',
                ...$base,
                'fromId' => $item->fromId,
                'summary' => $item->summary,
                'fromHook' => $item->fromHook,
                'details' => [
                    'readFiles' => $item->readFiles,
                    'modifiedFiles' => $item->modifiedFiles,
                ],
            ];
        }

        $message = SessionCodec::encode($item);

        return $message === null ? null : ['type' => 'message', ...$base, 'message' => $message];
    }

    /**
     * What a line describes, or null for a line nothing here understands.
     *
     * `thinking_level_change`, `model_change` and `label` are pi's and are read as null:
     * they are not messages and pig has nothing that uses them, so they are skipped on the
     * way in and — because nothing rewrites a session file — left exactly where they are.
     *
     * @param array<string, mixed> $line
     */
    public static function decode(array $line): mixed
    {
        $at = self::millis($line['timestamp'] ?? null);
        $details = is_array($line['details'] ?? null) ? $line['details'] : [];

        return match ($line['type'] ?? null) {
            'message' => is_array($line['message'] ?? null) ? SessionCodec::decode($line['message']) : null,

            'custom' => new CustomEntry(
                (string) ($line['customType'] ?? ''),
                $line['data'] ?? null,
                $at,
            ),

            'custom_message' => new HookMessage(
                (string) ($line['customType'] ?? ''),
                SessionCodec::decodeContent(self::contentOf($line['content'] ?? [])),
                (bool) ($line['display'] ?? true),
                $line['details'] ?? null,
                $at,
            ),

            'compaction' => new CompactionSummary(
                (string) ($line['summary'] ?? ''),
                self::files($details, 'readFiles'),
                self::files($details, 'modifiedFiles'),
                (int) ($line['tokensBefore'] ?? 0),
                isset($line['firstKeptEntryId']) ? (string) $line['firstKeptEntryId'] : null,
                0,
                $at,
            ),

            'branch_summary' => new BranchSummary(
                (string) ($line['summary'] ?? ''),
                self::files($details, 'readFiles'),
                self::files($details, 'modifiedFiles'),
                isset($line['fromId']) ? (string) $line['fromId'] : null,
                (bool) ($line['fromHook'] ?? false),
                $at,
            ),

            default => null,
        };
    }

    /**
     * A line pig wrote before it spoke pi's format.
     *
     * Told apart by what it has rather than by a version number, because both formats say
     * `version: 2` — which is the whole reason this was worth fixing. A flat line carries
     * `role` at the top level; pi's carries `type`.
     *
     * @param array<string, mixed> $line
     * @return array{0: mixed, 1: string|null, 2: bool}|null the item, its parent, and
     *         whether the line named one at all
     */
    public static function readOld(array $line): ?array
    {
        if (!isset($line['role'])) {
            return null;
        }

        $item = SessionCodec::decode($line);

        if ($item === null) {
            return null;
        }

        return [$item, is_string($line['parent'] ?? null) ? $line['parent'] : null, array_key_exists('parent', $line)];
    }

    /** `2026-01-02T21:29:30.123Z`, which is what pi writes and what its file names are made of. */
    public static function iso(int $millis): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000)) . sprintf('.%03dZ', $millis % 1000);
    }

    /** Back again. Anything unreadable is now, which is the only answer that is never wrong by years. */
    public static function millis(mixed $timestamp): int
    {
        if (is_int($timestamp)) {
            return $timestamp;
        }

        if (!is_string($timestamp) || $timestamp === '') {
            return Timestamp::nowMs();
        }

        $at = strtotime($timestamp);

        if ($at === false) {
            return Timestamp::nowMs();
        }

        // `strtotime` throws the milliseconds away, so they are read back off the string.
        $fraction = preg_match('/\.(\d{1,3})/', $timestamp, $match) === 1
            ? (int) str_pad($match[1], 3, '0')
            : 0;

        return $at * 1000 + $fraction;
    }

    /**
     * pi's `content` is a string or a list of blocks; pig's decoder wants the list.
     *
     * @return list<mixed>
     */
    private static function contentOf(mixed $content): array
    {
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }

        return is_array($content) ? array_values($content) : [];
    }

    /**
     * @param array<string, mixed> $details
     * @return list<string>
     */
    private static function files(array $details, string $key): array
    {
        return array_values(array_map(strval(...), (array) ($details[$key] ?? [])));
    }

    private static function timestampOf(mixed $item): int
    {
        return is_object($item) && property_exists($item, 'timestamp') && is_int($item->timestamp)
            ? $item->timestamp
            : Timestamp::nowMs();
    }
}
