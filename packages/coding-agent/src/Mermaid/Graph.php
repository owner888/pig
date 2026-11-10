<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `graph.ts`: the model flowchart, state, class and ER sources all parse into;
 * only sequence diagrams have their own. A direction is `down`, `up`, `right` or `left`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Graph
{
    /** Caps that keep layout bounded. */
    public const int MAX_NODES = 128;
    public const int MAX_EDGES = 512;
    public const int MAX_GROUPS = 24;
    public const int MAX_GROUP_DEPTH = 6;

    /** Class members or ER attributes listed per box before eliding with `…`. */
    public const int MAX_MEMBERS = 8;

    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<Edge> */
    public array $edges = [];

    /** @var array<array-key, int> keyed by id — an integer-looking id becomes an integer key */
    public array $index = [];

    /** @var list<Group> */
    public array $groups = [];

    /** @var list<int|null> innermost subgraph each node was declared in, parallel to `nodes` */
    public array $nodeGroup = [];

    public ?int $curGroup = null;

    /**
     * The cap that was hit, or null. The parser stops reading statements and the prefix is drawn,
     * with a warning: a streamed diagram that outgrows a cap never shrinks back under it.
     */
    public ?string $truncated = null;

    /** @var list<string> source the grammar could not read and dropped */
    public array $warnings = [];

    /** @var array<string, array<string, string>> parsed `classDef` declarations */
    public array $classDefs = [];

    public function __construct(public string $dir = 'down')
    {
    }

    /** `LR`/`RL`/`BT` as written in a header or `direction` statement; else `down`. */
    public static function parseDir(string $token): string
    {
        return match (Labels::asciiUpper($token)) {
            'LR' => 'right',
            'RL' => 'left',
            'BT' => 'up',
            default => 'down',
        };
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->index);
    }

    public function find(string $id): ?int
    {
        return $this->index[$id] ?? null;
    }

    /**
     * Index of `id`, creating the node if new. A later declaration carrying a label overwrites
     * the placeholder an edge created. Null once `MAX_NODES` is reached, which truncates the parse.
     */
    public function nodeIndex(string $id, ?string $label, string $shape): ?int
    {
        $existing = $this->find($id);

        if ($existing !== null) {
            if ($label !== null) {
                $this->nodes[$existing]->label = $label;
                $this->nodes[$existing]->shape = $shape;
            }

            return $existing;
        }

        if (count($this->nodes) >= self::MAX_NODES) {
            $this->truncated ??= 'node cap (' . self::MAX_NODES . ') reached';

            return null;
        }

        $this->index[$id] = count($this->nodes);
        $this->nodes[] = new Node($label ?? $id, $shape);
        $this->nodeGroup[] = $this->curGroup;

        return count($this->nodes) - 1;
    }

    /** A node's label set without disturbing its shape, creating it if new. */
    public function nodeLabel(string $id, string $label): ?int
    {
        $existing = $this->find($id);

        if ($existing !== null) {
            $this->nodes[$existing]->label = $label;

            return $existing;
        }

        return $this->nodeIndex($id, $label, 'round');
    }

    /** An author class name attached to a node, a repeat ignored. */
    public function addClass(int $idx, string $name): void
    {
        $node = $this->nodes[$idx];
        $node->classes ??= [];

        if (!in_array($name, $node->classes, true)) {
            $node->classes[] = $name;
        }
    }

    /**
     * Collected `[ids, names]` class assignments applied — after the statement walk, so a `class
     * A,B name` (or a `:::` tag) may come before the nodes it names. Unknown ids are ignored.
     *
     * @param list<array{0: list<string>, 1: list<string>}> $assignments
     */
    public function applyClasses(array $assignments): void
    {
        foreach ($assignments as [$ids, $names]) {
            foreach ($ids as $id) {
                $idx = $this->find(Labels::trim($id));

                if ($idx === null) {
                    continue;
                }

                foreach ($names as $name) {
                    if (Labels::trim($name) !== '') {
                        $this->addClass($idx, Labels::trim($name));
                    }
                }
            }
        }
    }

    /**
     * `[id, url]` link targets applied, the last per id winning, unknown ids ignored. Deferred like
     * `applyClasses()`, for the same reason.
     *
     * @param list<array{0: string, 1: string}> $hrefs
     */
    public function applyHrefs(array $hrefs): void
    {
        foreach ($hrefs as [$id, $url]) {
            $idx = $this->find(Labels::trim($id));

            if ($idx !== null) {
                $this->nodes[$idx]->href = $url;
            }
        }
    }

    /** An unreadable statement recorded; skipped once truncated, which is not the statement's fault. */
    public function drop(string $st): void
    {
        if ($this->truncated === null) {
            $this->warnings[] = "dropped, unreadable statement: \"{$st}\"";
        }
    }

    /** An edge appended, or `truncated` set when `MAX_EDGES` is reached. */
    public function pushEdge(Edge $edge): bool
    {
        if (count($this->edges) >= self::MAX_EDGES) {
            $this->truncated ??= 'edge cap (' . self::MAX_EDGES . ') reached';

            return false;
        }

        $this->edges[] = $edge;

        return true;
    }
}
