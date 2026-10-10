<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `graph.ts`: the model flowchart, state, class and ER sources all parse into.
 * A direction is `down`, `up`, `right` or `left`.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Graph
{
    /** Caps that keep layout bounded; exceeding one drops the diagram. */
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

    /** @var array<string, int> */
    public array $index = [];

    /** @var list<Group> */
    public array $groups = [];

    /** @var list<int|null> innermost subgraph each node was declared in, parallel to `nodes` */
    public array $nodeGroup = [];

    public ?int $curGroup = null;

    /** Set when a cap was hit; the caller abandons the parse. */
    public bool $overCap = false;

    /** @var list<string> text the flowchart grammar could not read and dropped */
    public array $warnings = [];

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

    /**
     * Index of `id`, creating the node if new. A later declaration carrying a label overwrites
     * the placeholder an edge created. Null once `MAX_NODES` is reached, which aborts the parse.
     */
    public function nodeIndex(string $id, ?string $label, string $shape): ?int
    {
        // An array key that looks like an integer becomes one; the id is compared as a string.
        if (array_key_exists($id, $this->index)) {
            $existing = $this->index[$id];

            if ($label !== null) {
                $this->nodes[$existing]->label = $label;
                $this->nodes[$existing]->shape = $shape;
            }

            return $existing;
        }

        if (count($this->nodes) >= self::MAX_NODES) {
            $this->overCap = true;

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
        if (array_key_exists($id, $this->index)) {
            $existing = $this->index[$id];
            $this->nodes[$existing]->label = $label;

            return $existing;
        }

        return $this->nodeIndex($id, $label, 'round');
    }

    /** An edge appended, or `overCap` flagged when `MAX_EDGES` is reached. */
    public function pushEdge(Edge $edge): bool
    {
        if (count($this->edges) >= self::MAX_EDGES) {
            $this->overCap = true;

            return false;
        }

        $this->edges[] = $edge;

        return true;
    }
}
