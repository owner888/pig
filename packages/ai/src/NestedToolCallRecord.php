<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * A tool call that another tool made while it ran, for example from a codemode script — upstream's
 * `NestedToolCallRecord`. One row of a `NestedToolCalls`.
 *
 * Mutable, unlike the messages: the recorder opens the row when the call starts and fills in the
 * outcome when it ends, and the row is copied into the message when the calling tool finishes.
 */
final class NestedToolCallRecord
{
    /**
     * @param ?array<string, mixed> $arguments      omitted (null) when over the size limits; `$argumentsBytes` then gives their size
     * @param ?int                  $argumentsBytes UTF-8 size of the arguments as JSON, set when `$arguments` is omitted
     * @param 'ok'|'error'|'unfinished' $status      `unfinished`: still running when the calling tool finished
     * @param ?string               $error          error text, truncated
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public ?array $arguments = null,
        public ?int $argumentsBytes = null,
        public string $status = 'unfinished',
        public ?int $durationMs = null,
        public ?string $error = null,
    ) {
    }

    /** The row as upstream writes it: absent fields left out. @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'argumentsBytes' => $this->argumentsBytes,
            'status' => $this->status,
            'durationMs' => $this->durationMs,
            'error' => $this->error,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $status = $row['status'] ?? 'unfinished';

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            is_array($row['arguments'] ?? null) ? $row['arguments'] : null,
            isset($row['argumentsBytes']) ? (int) $row['argumentsBytes'] : null,
            in_array($status, ['ok', 'error', 'unfinished'], true) ? $status : 'unfinished',
            isset($row['durationMs']) ? (int) $row['durationMs'] : null,
            isset($row['error']) ? (string) $row['error'] : null,
        );
    }
}
