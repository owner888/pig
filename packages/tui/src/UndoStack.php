<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Snapshots for `tui.editor.undo` — upstream's `undo-stack.ts`. Upstream deep-clones on push
 * with `structuredClone()`; a PHP array is a value already, so a snapshot is copied by being
 * assigned and nothing here needs to clone it.
 *
 * @template S
 */
final class UndoStack
{
    /** @var list<S> */
    private array $stack = [];

    /** @param S $state */
    public function push(mixed $state): void
    {
        $this->stack[] = $state;
    }

    /** @return S|null the most recent snapshot, or null when there is none */
    public function pop(): mixed
    {
        return array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
    }

    public function length(): int
    {
        return count($this->stack);
    }
}
