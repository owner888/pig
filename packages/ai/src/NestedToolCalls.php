<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Bounded record of the nested calls a tool made — upstream's `NestedToolCalls`, the
 * `nestedCalls` of a `ToolResultMessage`. Kept for the session record; not sent to the model.
 * Results are not recorded.
 */
final readonly class NestedToolCalls
{
    /**
     * @param list<NestedToolCallRecord> $calls
     * @param bool                       $complete false when calls were dropped, arguments omitted, or calls had not finished
     */
    public function __construct(
        public array $calls,
        public bool $complete,
    ) {
    }

    /** @return array{calls: list<array<string, mixed>>, complete: bool} */
    public function toArray(): array
    {
        return [
            'calls' => array_map(static fn (NestedToolCallRecord $call): array => $call->toArray(), $this->calls),
            'complete' => $this->complete,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $calls = [];

        foreach (is_array($data['calls'] ?? null) ? $data['calls'] : [] as $row) {
            if (is_array($row)) {
                $calls[] = NestedToolCallRecord::fromArray($row);
            }
        }

        return new self($calls, (bool) ($data['complete'] ?? false));
    }
}
