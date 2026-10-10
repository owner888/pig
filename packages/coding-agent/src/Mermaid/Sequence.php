<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `Sequence`: a sequence diagram's participants and items.
 *
 * An item is upstream's `SeqItem` as an array, `kind` first:
 * `['kind' => 'message', 'from' => int, 'to' => int, 'text' => ?string, 'dashed' => bool, 'head' => 'arrow'|'cross']`,
 * `['kind' => 'note', 'anchor' => NoteAnchor, 'text' => string]` or `['kind' => 'divider', 'text' => string]`;
 * a note's anchor is `['kind' => 'over', 'from' => int, 'to' => int]`, `['kind' => 'left', 'at' => int]`
 * or `['kind' => 'right', 'at' => int]`.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Sequence
{
    /** @var list<string> */
    public array $labels = [];

    /** @var array<string, int> */
    public array $index = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function participant(string $id, ?string $label): ?int
    {
        if (array_key_exists($id, $this->index)) {
            $existing = $this->index[$id];

            if ($label !== null) {
                $this->labels[$existing] = $label;
            }

            return $existing;
        }

        if (count($this->labels) >= Graph::MAX_NODES) {
            return null;
        }

        $this->index[$id] = count($this->labels);
        $this->labels[] = $label ?? $id;

        return count($this->labels) - 1;
    }
}
