<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\NestedToolCallRecord;
use Pig\Ai\NestedToolCalls;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;

/**
 * Collects the nested calls of one model-issued tool call, including calls made by nested tools —
 * upstream's `NestedCallRecorder` (`core/nested-tool-calls.ts`). The snapshot becomes `nestedCalls`
 * on the tool result message, and the summed usage of the nested results goes into its `usage`,
 * because the nested results themselves are not persisted and this is where their bill survives.
 *
 * Bounded, as upstream's: arguments over the per-call or total size are left out of the record
 * (their size kept), calls past the count are dropped, and the record says so with `complete:
 * false` — as it does when a call had not finished when the calling tool did.
 */
final class NestedCallRecorder
{
    public const int MAX_CALLS = 256;
    public const int MAX_ARGUMENT_BYTES_PER_CALL = 8 * 1024;
    public const int MAX_ARGUMENT_BYTES_TOTAL = 32 * 1024;
    public const int MAX_ERROR_CHARS = 500;

    /** @var list<NestedToolCallRecord> */
    private array $calls = [];

    /** @var array<int, int|float> `hrtime(true)` when each row's call started, by the row's index */
    private array $startedAt = [];

    private bool $complete = true;

    private int $argumentBytes = 0;

    /** Summed usage of every nested result, including calls dropped from the record. */
    private ?Usage $usage = null;

    /** Record a call as it starts. Null when the call is dropped (over `MAX_CALLS`). */
    public function start(ToolCall $toolCall): ?NestedToolCallRecord
    {
        if (count($this->calls) >= self::MAX_CALLS) {
            $this->complete = false;

            return null;
        }

        $record = new NestedToolCallRecord($toolCall->id, $toolCall->name);
        $json = json_encode($toolCall->arguments === [] ? new \stdClass() : $toolCall->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = $json === false ? 0 : strlen($json);

        if ($bytes > self::MAX_ARGUMENT_BYTES_PER_CALL || $this->argumentBytes + $bytes > self::MAX_ARGUMENT_BYTES_TOTAL) {
            $record->argumentsBytes = $bytes;
            $this->complete = false;
        } else {
            $record->arguments = $toolCall->arguments;
            $this->argumentBytes += $bytes;
        }

        $this->calls[] = $record;
        $this->startedAt[count($this->calls) - 1] = hrtime(true);

        return $record;
    }

    public function finish(?NestedToolCallRecord $record, bool $isError, string $errorText): void
    {
        if ($record === null) {
            return;
        }

        $index = array_search($record, $this->calls, true);
        $startedAt = $index === false ? null : ($this->startedAt[$index] ?? null);
        $record->status = $isError ? 'error' : 'ok';
        $record->durationMs = $startedAt === null ? 0 : (int) round((hrtime(true) - $startedAt) / 1e6);

        if ($index !== false) {
            unset($this->startedAt[$index]);
        }

        if ($isError && $errorText !== '') {
            $record->error = mb_substr($errorText, 0, self::MAX_ERROR_CHARS);
        }
    }

    public function addUsage(Usage $usage): void
    {
        $this->usage = $this->usage === null ? $usage : $this->usage->plus($usage);
    }

    public function totalUsage(): ?Usage
    {
        return $this->usage;
    }

    /** Copy of the record so far, or null when no nested call was made. */
    public function snapshot(): ?NestedToolCalls
    {
        if ($this->calls === [] && $this->complete) {
            return null;
        }

        $calls = array_map(static fn (NestedToolCallRecord $call): NestedToolCallRecord => clone $call, $this->calls);
        // Spelled with a filter because the 8.4 one-liner for "every" is past the 8.3 floor.
        $finished = array_filter($calls, static fn (NestedToolCallRecord $call): bool => $call->status === 'unfinished') === [];

        return new NestedToolCalls($calls, $this->complete && $finished);
    }
}
