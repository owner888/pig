<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `Sequence`: a sequence diagram's participants in declaration order and a flat
 * list of items.
 *
 * An item is upstream's `SeqItem` as an array, `kind` first:
 * `['kind' => 'message', 'from' => int, 'to' => int, 'text' => ?string, 'dashed' => bool, 'head' => 'arrow'|'cross']`,
 * `['kind' => 'note', 'anchor' => NoteAnchor, 'text' => string]` or `['kind' => 'divider', 'text' => string]`;
 * a note's anchor is `['kind' => 'over', 'from' => int, 'to' => int]`, `['kind' => 'left', 'at' => int]`
 * or `['kind' => 'right', 'at' => int]`. An activation — a hot span of one lifeline — is
 * `['at' => int, 'from' => int, 'to' => ?int]`, item indices, `to` null while still open.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Sequence
{
    /** @var list<string> */
    public array $labels = [];

    /** @var array<array-key, int> */
    public array $index = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @var list<array{at: int, from: int, to: ?int}> */
    public array $activations = [];

    /** Set when a size cap was hit; the parser stops and the prefix is drawn. */
    public ?string $truncated = null;

    /** @var list<string> statements the grammar could not read and dropped */
    public array $warnings = [];

    public function find(string $id): ?int
    {
        return $this->index[$id] ?? null;
    }

    public function participant(string $id, ?string $label): ?int
    {
        $existing = $this->find($id);

        if ($existing !== null) {
            if ($label !== null) {
                $this->labels[$existing] = $label;
            }

            return $existing;
        }

        if (count($this->labels) >= Graph::MAX_NODES) {
            $this->truncated ??= 'participant cap (' . Graph::MAX_NODES . ') reached';

            return null;
        }

        $this->index[$id] = count($this->labels);
        $this->labels[] = $label ?? $id;

        return count($this->labels) - 1;
    }

    /**
     * An item appended, or `truncated` set when the item cap is reached.
     *
     * @param array<string, mixed> $item
     */
    public function pushItem(array $item): void
    {
        if (count($this->items) >= Graph::MAX_EDGES) {
            $this->truncated ??= 'item cap (' . Graph::MAX_EDGES . ') reached';
        } else {
            $this->items[] = $item;
        }
    }

    /** An unreadable statement recorded; skipped once truncated. */
    public function drop(string $st): void
    {
        if ($this->truncated === null) {
            $this->warnings[] = "dropped, unreadable statement: \"{$st}\"";
        }
    }

    /** An activation opened at item `from`. */
    public function activate(int $at, int $from): void
    {
        $this->activations[] = ['at' => $at, 'from' => $from, 'to' => null];
    }

    /** The innermost open activation of `at` closed at item `to`. */
    public function deactivate(int $at, int $to): void
    {
        for ($i = count($this->activations) - 1; $i >= 0; $i--) {
            $a = $this->activations[$i];

            if ($a['at'] === $at && $a['to'] === null) {
                $this->activations[$i]['to'] = $to;

                return;
            }
        }
    }
}
