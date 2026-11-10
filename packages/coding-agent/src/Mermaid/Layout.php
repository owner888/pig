<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `layout.ts`: graph layout — rank, order, place, route, draw.
 *
 * Follows the Sugiyama outline: assign ranks along the flow axis, reorder within ranks to cut
 * crossings, then relax positions on the cross axis so chains stay straight. Edges between
 * adjacent ranks share horizontal "bus" rows; everything else is routed around the diagram
 * through vertical "lanes". `BT` and `RL` reuse the `TD`/`LR` layouts and flip the finished
 * canvas, so text never ends up mirrored.
 *
 * A node's `NodeExtra` is an array: `['kind' => 'plain']`, `['kind' => 'frame', 'sub' => Canvas]`
 * or `['kind' => 'compartments', 'sections' => list<list<string>>]`. A span competing for a track
 * (`TrackSpan`) is an array keyed `start`, `end`, `from`, `to`, `edge` and maybe `labeled`.
 * `NodeSizes` and `RoutePlan` are arrays keyed by upstream's field names, a lane label an array
 * keyed `text`, `y`, `lo` and `hi`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Layout
{
    /** Cells of padding between a box border and its text. */
    public const int PAD = 1;

    /** Minimum horizontal / vertical space between boxes. */
    private const int GAP_X = 3;
    private const int GAP_Y = 2;

    /** Refuse to allocate a canvas larger than this many cells. */
    public const int MAX_CANVAS_CELLS = 1 << 21;

    /** Saturating subtraction; Rust's `usize` arithmetic never goes negative. */
    public static function sat(int $a, int $b): int
    {
        return max(0, $a - $b);
    }

    public static function half(int $n): int
    {
        return (int) floor($n / 2);
    }

    /** JS `Math.round()`: nearest integer, ties toward +∞ (PHP's `round()` ties away from zero). */
    public static function jsRound(int|float $x): int
    {
        $f = floor($x);

        return (int) ($x - $f >= 0.5 ? $f + 1 : $f);
    }

    /**
     * Everything an edge says, joined — the fallback for routes that have no per-end placement
     * (lanes, self-loops). Forward routes place `cardFrom` / `cardTo` at their own ends instead.
     */
    private static function edgeText(Edge $edge): ?string
    {
        $joined = implode(' ', array_filter(
            [$edge->cardFrom ?? '', $edge->label ?? '', $edge->cardTo ?? ''],
            static fn (string $part): bool => $part !== '',
        ));

        return $joined === '' ? null : $joined;
    }

    // ------------------------------------------------------------------ ranking

    /**
     * Longest-path ranking over the graph's DAG. Back edges (those closing a cycle) are excluded
     * by a DFS colouring pass, so `A --> B --> C --> A` still ranks 0, 1, 2 rather than diverging.
     *
     * @return list<int>
     */
    public static function computeRanks(Graph $graph): array
    {
        $n = count($graph->nodes);
        $children = array_fill(0, $n, []);
        $indeg = array_fill(0, $n, 0);

        foreach ($graph->edges as $e) {
            if ($e->from !== $e->to) {
                $children[$e->from][] = $e->to;
                $indeg[$e->to]++;
            }
        }

        $color = array_fill(0, $n, 0);
        $dag = array_fill(0, $n, []);
        $order = [];

        // Roots first so ranks grow from natural entry points, then any leftovers.
        $roots = array_keys(array_filter($indeg, static fn (int $d): bool => $d === 0));

        foreach ([...$roots, ...array_keys($indeg)] as $start) {
            if ($color[$start] === 0) {
                self::dfsDag($start, $children, $color, $dag, $order);
            }
        }

        $rank = array_fill(0, $n, 0);

        for ($i = count($order) - 1; $i >= 0; $i--) {
            $u = $order[$i];

            foreach ($dag[$u] as $v) {
                $rank[$v] = max($rank[$v], $rank[$u] + 1);
            }
        }

        return $rank;
    }

    /**
     * Iterative DFS recording postorder and skipping edges back into the stack.
     *
     * @param array<int, list<int>> $children
     * @param array<int, int> $color
     * @param array<int, list<int>> $dag
     * @param list<int> $order
     */
    private static function dfsDag(int $start, array $children, array &$color, array &$dag, array &$order): void
    {
        $stack = [['u' => $start, 'i' => 0]];
        $color[$start] = 1;

        while (count($stack) > 0) {
            $top = count($stack) - 1;
            $u = $stack[$top]['u'];

            if ($stack[$top]['i'] < count($children[$u])) {
                $v = $children[$u][$stack[$top]['i']];
                $stack[$top]['i']++;

                if ($color[$v] === 1) {
                    continue; // grey: a back edge, ignore it
                }

                $dag[$u][] = $v;

                if ($color[$v] === 0) {
                    $color[$v] = 1;
                    $stack[] = ['u' => $v, 'i' => 0];
                }
            } else {
                $color[$u] = 2;
                $order[] = $u;
                array_pop($stack);
            }
        }
    }

    /**
     * Nodes reordered within each rank to cut edge crossings (barycenter sweeps): alternate
     * down/up passes sort each rank by the mean position of its neighbours, keeping whichever
     * ordering crossed least. `byRank` is rewritten in place.
     *
     * @param list<list<int>> $byRank
     * @param list<Edge> $edges
     * @param list<int> $ranks
     */
    public static function orderRanks(array &$byRank, array $edges, array $ranks): void
    {
        $n = count($ranks);

        if (count($byRank) < 2 || $n < 3) {
            return;
        }

        $parents = array_fill(0, $n, []);
        $children = array_fill(0, $n, []);

        foreach ($edges as $e) {
            if ($e->from !== $e->to && $ranks[$e->to] > $ranks[$e->from]) {
                $parents[$e->to][] = $e->from;
                $children[$e->from][] = $e->to;
            }
        }

        $pos = array_fill(0, $n, 0);

        foreach ($byRank as $row) {
            self::reindex($row, $pos);
        }

        $best = $byRank;
        $bestCrossings = self::countCrossings($edges, $ranks, $pos);

        if ($bestCrossings === 0) {
            return;
        }

        for ($it = 0; $it < 8; $it++) {
            // Alternate sweeping down (sort by parents) and up (sort by children).
            $rows = $it % 2 === 0 ? range(1, count($byRank) - 1) : array_reverse(range(0, count($byRank) - 2));
            $neigh = $it % 2 === 0 ? $parents : $children;

            foreach ($rows as $r) {
                self::sortByBarycenter($byRank[$r], $neigh, $pos);
                self::reindex($byRank[$r], $pos);
            }

            $crossings = self::countCrossings($edges, $ranks, $pos);

            if ($crossings < $bestCrossings) {
                $bestCrossings = $crossings;
                $best = $byRank;
            }

            if ($bestCrossings === 0) {
                break;
            }
        }

        $byRank = $best;
    }

    /**
     * @param list<int> $row
     * @param array<int, int> $pos
     */
    private static function reindex(array $row, array &$pos): void
    {
        foreach ($row as $i => $v) {
            $pos[$v] = $i;
        }
    }

    /**
     * A row stably sorted by its nodes' mean neighbour position, a node without neighbours keeping
     * its own.
     *
     * @param list<int> $row
     * @param array<int, list<int>> $neigh
     * @param array<int, int> $pos
     */
    private static function sortByBarycenter(array &$row, array $neigh, array $pos): void
    {
        $keyed = array_map(static fn (int $v): array => [
            'key' => $neigh[$v] === [] ? $pos[$v] : array_sum(array_map(static fn (int $u): int => $pos[$u], $neigh[$v])) / count($neigh[$v]),
            'v' => $v,
        ], $row);
        usort($keyed, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        foreach ($keyed as $i => $k) {
            $row[$i] = $k['v'];
        }
    }

    /**
     * Pairs of adjacent-rank edges out of one rank that cross.
     *
     * @param list<Edge> $edges
     * @param list<int> $ranks
     * @param array<int, int> $pos
     */
    public static function countCrossings(array $edges, array $ranks, array $pos): int
    {
        $adjacent = [];

        foreach ($edges as $e) {
            if ($e->from !== $e->to && $ranks[$e->to] === $ranks[$e->from] + 1) {
                $adjacent[] = [$ranks[$e->from], $pos[$e->from], $pos[$e->to]];
            }
        }

        $crossings = 0;
        $count = count($adjacent);

        for ($i = 0; $i < $count; $i++) {
            $a = $adjacent[$i];

            for ($j = $i + 1; $j < $count; $j++) {
                $b = $adjacent[$j];

                if ($a[0] === $b[0] && (($a[1] < $b[1] && $a[2] > $b[2]) || ($a[1] > $b[1] && $a[2] < $b[2]))) {
                    $crossings++;
                }
            }
        }

        return $crossings;
    }

    /**
     * A cross-axis centre for every node so nodes line up under their neighbours — each drifts
     * toward the average of its neighbours while ranks keep their order and boxes keep `sep`
     * between them.
     *
     * @param list<list<int>> $byRank
     * @param list<int> $size
     * @param list<Edge> $edges
     * @param list<int> $ranks
     * @return list<int>
     */
    public static function assignPositions(array $byRank, array $size, int $sep, array $edges, array $ranks): array
    {
        $n = count($size);
        $parents = array_fill(0, $n, []);
        $children = array_fill(0, $n, []);

        foreach ($edges as $e) {
            if ($e->from !== $e->to && $ranks[$e->to] > $ranks[$e->from]) {
                $parents[$e->to][] = $e->from;
                $children[$e->from][] = $e->to;
            }
        }

        // Positions are fractional while relaxing, as upstream's are.
        $pos = array_fill(0, $n, 0);

        foreach ($byRank as $row) {
            $x = 0;

            foreach ($row as $v) {
                $h = $size[$v] / 2;
                $x += $h;
                $pos[$v] = $x;
                $x += $h + $sep;
            }
        }

        for ($it = 0; $it < 10; $it++) {
            $rows = $it % 2 === 0 ? $byRank : array_reverse($byRank);
            $neigh = $it % 2 === 0 ? $parents : $children;

            foreach ($rows as $row) {
                self::relaxRank($row, $neigh, $pos, $size, $sep);
            }
        }

        $minLeft = INF;

        for ($v = 0; $v < $n; $v++) {
            $minLeft = min($minLeft, $pos[$v] - $size[$v] / 2);
        }

        if (!is_finite($minLeft)) {
            $minLeft = 0;
        }

        $out = [];

        for ($v = 0; $v < $n; $v++) {
            $out[] = max(0, self::jsRound($pos[$v] - $minLeft));
        }

        return $out;
    }

    /**
     * One rank pulled toward its neighbours' mean positions.
     *
     * @param list<int> $nodes
     * @param array<int, list<int>> $neigh
     * @param array<int, int|float> $pos
     * @param list<int> $size
     */
    private static function relaxRank(array $nodes, array $neigh, array &$pos, array $size, int $sep): void
    {
        $n = count($nodes);

        if ($n === 0) {
            return;
        }

        $desired = array_map(
            static fn (int $v): int|float => $neigh[$v] === [] ? $pos[$v] : array_sum(array_map(static fn (int $u): int|float => $pos[$u], $neigh[$v])) / count($neigh[$v]),
            $nodes,
        );
        $halfOf = static fn (int $i): int|float => $size[$nodes[$i]] / 2;

        // Sweep right then left, then take the midpoint: this centres a node between the
        // tightest packing that respects order from either side.
        $left = [];

        for ($i = 0; $i < $n; $i++) {
            $left[$i] = $i === 0 ? $desired[$i] : max($desired[$i], $left[$i - 1] + $halfOf($i - 1) + $sep + $halfOf($i));
        }

        $right = [];

        for ($i = $n - 1; $i >= 0; $i--) {
            $right[$i] = $i === $n - 1 ? $desired[$i] : min($desired[$i], $right[$i + 1] - $halfOf($i + 1) - $sep - $halfOf($i));
        }

        for ($i = 0; $i < $n; $i++) {
            $pos[$nodes[$i]] = ($left[$i] + $right[$i]) / 2;
        }

        for ($i = 1; $i < $n; $i++) {
            $minP = $pos[$nodes[$i - 1]] + $halfOf($i - 1) + $sep + $halfOf($i);

            if ($pos[$nodes[$i]] < $minP) {
                $pos[$nodes[$i]] = $minP;
            }
        }
    }

    // ------------------------------------------------------------------- tracks

    /**
     * Spans packed into as few parallel tracks as possible. Two spans share a track when they are
     * two cells apart, or when they share an endpoint — edges fanning out of one node reuse a
     * single row so a merge draws one arrowhead rather than a stack of them. A labelled lane
     * refuses that sharing: its label would appear to cover every edge merged onto the row.
     *
     * Lanes pack shortest first (`shortestFirst`): a span inside another takes the inner track,
     * so exits and entries at rows the inner lane never reaches cross nothing.
     *
     * @param list<array{start: int, end: int, from: int, to: int, edge: int, labeled?: bool}> $spans
     * @return array{assigned: list<array{0: int, 1: int}>, count: int}
     */
    public static function assignTracks(array $spans, bool $shortestFirst = false): array
    {
        $sorted = $spans;
        usort($sorted, static fn (array $a, array $b): int => ($shortestFirst ? ($a['end'] - $a['start']) <=> ($b['end'] - $b['start']) : 0)
            ?: $a['start'] <=> $b['start']
            ?: $a['end'] <=> $b['end']
            ?: $a['from'] <=> $b['from']
            ?: $a['to'] <=> $b['to']
            ?: $a['edge'] <=> $b['edge']);
        $tracks = [];
        $assigned = [];

        foreach ($sorted as $span) {
            $slot = -1;
            $labeled = $span['labeled'] ?? false;

            foreach ($tracks as $k => $members) {
                $fits = true;

                foreach ($members as $m) {
                    $shares = ($m['from'] === $span['from'] || $m['to'] === $span['to']) && !($m['labeled'] ?? false) && !$labeled;

                    if (!($m['end'] + 2 <= $span['start'] || $span['end'] + 2 <= $m['start'] || $shares)) {
                        $fits = false;

                        break;
                    }
                }

                if ($fits) {
                    $slot = $k;

                    break;
                }
            }

            if ($slot === -1) {
                $tracks[] = [];
                $slot = count($tracks) - 1;
            }

            $tracks[$slot][] = $span;
            $assigned[] = [$span['edge'], $slot];
        }

        return ['assigned' => $assigned, 'count' => count($tracks)];
    }

    /**
     * An edge between adjacent ranks running against the direction. Top-down, these route locally
     * through the band their forward siblings use — the short return arrow mermaid draws — rather
     * than around the diagram. Left-to-right boxes are three rows tall, leaving no room to offset
     * the return off the centre row, so LR and RL keep the lane.
     *
     * @param list<int> $ranks
     */
    public static function isAdjacentBack(array $ranks, Edge $e): bool
    {
        return $e->from !== $e->to && $ranks[$e->from] === $ranks[$e->to] + 1;
    }

    /**
     * Edges crossing the band between rank `r` and `r + 1` that must jog sideways, so need a bus
     * row. `withBack` admits adjacent back edges.
     *
     * @param list<int> $ranks
     * @param list<int> $centers
     * @return list<array{start: int, end: int, from: int, to: int, edge: int}>
     */
    private static function busSpans(Graph $graph, array $ranks, array $centers, int $r, bool $exact, bool $withBack): array
    {
        $out = [];

        foreach ($graph->edges as $i => $e) {
            $jogs = $exact
                ? $centers[$e->from] !== $centers[$e->to]
                : abs($centers[$e->from] - $centers[$e->to]) > 1;
            $lo = min($ranks[$e->from], $ranks[$e->to]);
            $adjacent = $withBack
                ? abs($ranks[$e->from] - $ranks[$e->to]) === 1
                : $ranks[$e->to] === $ranks[$e->from] + 1;

            if ($e->from !== $e->to && $adjacent && $lo === $r && $jogs) {
                $out[] = [
                    'start' => min($centers[$e->from], $centers[$e->to]),
                    'end' => max($centers[$e->from], $centers[$e->to]),
                    'from' => $e->from,
                    'to' => $e->to,
                    'edge' => $i,
                ];
            }
        }

        return $out;
    }

    /**
     * Edges skipping a rank or running backwards; these go around in a lane.
     *
     * @param list<int> $ranks
     * @param list<Placed> $placed
     * @return list<array{start: int, end: int, from: int, to: int, edge: int, labeled: bool}>
     */
    private static function laneSpans(Graph $graph, array $ranks, array $placed, bool $vertical): array
    {
        $out = [];

        foreach ($graph->edges as $i => $e) {
            if ($e->from === $e->to || $ranks[$e->to] === $ranks[$e->from] + 1) {
                continue;
            }

            if ($vertical && self::isAdjacentBack($ranks, $e)) {
                continue;
            }

            $pf = $placed[$e->from];
            $pt = $placed[$e->to];
            $a = $vertical ? min($pf->cy, $pt->cy) : min($pf->cx, $pt->cx);
            $b = $vertical ? max($pf->cy, $pt->cy) : max($pf->cx, $pt->cx);
            $out[] = ['start' => $a, 'end' => $b, 'from' => $e->from, 'to' => $e->to, 'edge' => $i, 'labeled' => self::edgeText($e) !== null];
        }

        return $out;
    }

    // ----------------------------------------------------------------- placement

    /**
     * Top-down placement, filling `placed` and returning the route plan.
     *
     * @param list<int> $ranks
     * @param list<list<int>> $byRank
     * @param array{boxW: list<int>, boxH: list<int>, layW: list<int>, layH: list<int>, extraH: list<int>, selfLabelW: list<int>} $sizes
     * @param list<Placed> $placed
     * @return array<string, mixed>
     */
    private static function placeTd(array $ranks, int $maxRank, array $byRank, array $sizes, Graph $graph, array &$placed): array
    {
        $centers = self::assignPositions($byRank, $sizes['layW'], self::GAP_X, $graph->edges, $ranks);
        $edgeCount = count($graph->edges);
        $nodeCount = count($graph->nodes);

        // Top-entry geometry, derivable before placement. A node's entries — the merged forward
        // cluster plus each skip — spread evenly across the box top (two entries land at about
        // 1/3 and 2/3). A forward arrival aligned with its source stays at centre so chains keep
        // no kinks, and the skips spread over the right half instead. A label that does not fit
        // before the next entry renders left of its arrow. A skip whose entry column crosses no
        // box on any intermediate rank drops straight instead of taking a lane.
        $boxL = static fn (int $j): int => self::sat($centers[$j], self::half($sizes['boxW'][$j]));
        $boxR = static fn (int $j): int => $boxL($j) + $sizes['boxW'][$j] - 1;
        $isSkip = static fn (Edge $e): bool => $e->from !== $e->to && $ranks[$e->to] - $ranks[$e->from] > 1;
        $edgeEntryX = array_fill(0, $edgeCount, -1);
        $edgeStraight = array_fill(0, $edgeCount, false);
        $fwdEntryX = array_fill(0, $nodeCount, -1);
        $edgeLabelLeft = array_fill(0, $edgeCount, false);
        $labelW = static function (Edge $e): int {
            $parts = array_values(array_filter([$e->label, $e->cardTo], static fn (?string $p): bool => $p !== null));

            return $parts === [] ? -1 : max(array_map(static fn (string $p): int => min(Measure::width($p), Labels::MAX_LABEL), $parts));
        };
        $skipsInto = array_fill(0, $nodeCount, []);
        $fwdsInto = array_fill(0, $nodeCount, []);

        foreach ($graph->edges as $i => $e) {
            if ($e->from === $e->to) {
                continue;
            }

            if ($isSkip($e)) {
                $skipsInto[$e->to][] = $i;
            } elseif ($ranks[$e->to] === $ranks[$e->from] + 1) {
                $fwdsInto[$e->to][] = $i;
            }
        }

        for ($t = 0; $t < $nodeCount; $t++) {
            $skips = $skipsInto[$t];

            if ($skips === []) {
                continue;
            }

            $fwds = $fwdsInto[$t];
            $cx = $centers[$t];
            $left = $boxL($t);
            $right = $boxR($t);
            $hasFwd = $fwds !== [];
            $aligned = false;

            foreach ($fwds as $i) {
                if (abs($centers[$graph->edges[$i]->from] - $cx) <= 1) {
                    $aligned = true;
                }
            }

            $spreadL = $hasFwd && $aligned ? $cx : $left;
            $slots = $hasFwd && $aligned ? count($skips) : ($hasFwd ? 1 : 0) + count($skips);
            $slot = static fn (int $i): int => $spreadL + self::jsRound((($right - $spreadL) * ($i + 1)) / ($slots + 1));

            // Ordered entries: the forward cluster (if any), then each skip.
            $items = [];

            if ($hasFwd) {
                $items[] = [
                    'slot' => $aligned ? $cx : $slot(0),
                    'w' => max(array_map(static fn (int $i): int => $labelW($graph->edges[$i]), $fwds)),
                    'skip' => null,
                ];
            }

            foreach ($skips as $j => $si) {
                $items[] = [
                    'slot' => $slot($hasFwd && $aligned ? $j : $j + ($hasFwd ? 1 : 0)),
                    'w' => $labelW($graph->edges[$si]),
                    'skip' => $si,
                ];
            }

            // Walk left to right with a cursor over the free head-row cells: each entry lands at
            // its slot (or past the previous label), its own label going right when the next slot
            // leaves room, else left when the cells behind the cursor allow. Overflow falls back
            // to the earlier rule.
            $cols = [];
            $lefts = [];
            $cursor = $left;
            $fits = true;

            foreach ($items as $i => $item) {
                $x = max($item['slot'], $cursor);

                if ($x > $right - 1) {
                    $fits = false;

                    break;
                }

                $next = $items[$i + 1]['slot'] ?? PHP_INT_MAX;
                $w = $item['w'];

                if ($w >= 0 && $x + $w + 2 > $next && $x - $cursor >= $w) {
                    $lefts[] = true;
                    $cursor = $x + 2;
                } else {
                    $lefts[] = false;
                    $cursor = $w >= 0 ? $x + $w + 2 : $x + 2;
                }

                $cols[] = $x;
            }

            if ($fits) {
                foreach ($items as $i => $item) {
                    if ($item['skip'] === null) {
                        $fwdEntryX[$t] = $cols[$i];

                        if ($lefts[$i]) {
                            foreach ($fwds as $fi) {
                                $edgeLabelLeft[$fi] = true;
                            }
                        }
                    } else {
                        $edgeEntryX[$item['skip']] = $cols[$i];
                        $edgeLabelLeft[$item['skip']] = $lefts[$i];
                    }
                }

                continue;
            }

            // The earlier rule: forward stays centred; a skip lands past the arrival labels, or
            // left of centre with its own label flipped left.
            $reach = $hasFwd
                ? max($cx, ...array_map(static fn (int $i): int => $cx + 1 + $labelW($graph->edges[$i]), $fwds))
                : -1;

            foreach ($skips as $si) {
                $clear = $reach === -1 ? $cx + 2 : $reach + 2;
                $capped = min($clear, $right - 1);

                if ($capped > max($cx, $reach)) {
                    $edgeEntryX[$si] = $capped;
                } else {
                    $edgeEntryX[$si] = max($left + 1, $cx - 2);
                    $edgeLabelLeft[$si] = true;
                }
            }
        }

        foreach ($graph->edges as $i => $e) {
            if (!$isSkip($e)) {
                continue;
            }

            $entryX = $edgeEntryX[$i];
            $straight = true;

            for ($j = 0; $j < $nodeCount; $j++) {
                if (!($ranks[$j] <= $ranks[$e->from] || $ranks[$j] >= $ranks[$e->to] || $entryX < $boxL($j) || $entryX > $boxR($j))) {
                    $straight = false;

                    break;
                }
            }

            $edgeStraight[$i] = $straight;
        }

        $edgeBus = array_fill(0, $edgeCount, 0);
        $busTracks = array_fill(0, $maxRank + 1, 0);

        for ($r = 0; $r < $maxRank; $r++) {
            $spans = self::busSpans($graph, $ranks, $centers, $r, false, true);

            // Skip departures ride the bus tracks of their own band: endpoint sharing folds them
            // onto their siblings' row, so a node's forward fan and its skips split from one `┴`.
            foreach ($graph->edges as $i => $e) {
                if (!$isSkip($e) || $ranks[$e->from] !== $r) {
                    continue;
                }

                $endX = $edgeStraight[$i] ? $edgeEntryX[$i] : 1 << 29;
                $spans[] = [
                    'start' => min($centers[$e->from], $endX),
                    'end' => max($centers[$e->from], $endX),
                    'from' => $e->from,
                    'to' => $e->to,
                    'edge' => $i,
                ];
            }

            if ($spans === []) {
                continue;
            }

            // Back-edge arrowheads sit on the first band row, back buses right under it, forward
            // buses below those: with the attach columns offset right of centre, a reciprocal
            // pair then runs as two parallel staircases — no crossings.
            $back = array_values(array_filter($spans, static fn (array $s): bool => self::isAdjacentBack($ranks, $graph->edges[$s['edge']])));
            $fwd = array_values(array_filter($spans, static fn (array $s): bool => !self::isAdjacentBack($ranks, $graph->edges[$s['edge']])));
            $base = 0;

            foreach ($graph->edges as $e) {
                if (self::isAdjacentBack($ranks, $e) && $ranks[$e->to] === $r) {
                    $base = 1;

                    break;
                }
            }

            $b = self::assignTracks($back);

            foreach ($b['assigned'] as [$idx, $slot]) {
                $edgeBus[$idx] = $base + $slot;
            }

            $f = self::assignTracks($fwd);

            foreach ($f['assigned'] as [$idx, $slot]) {
                $edgeBus[$idx] = $base + $b['count'] + $slot;
            }

            $busTracks[$r] = $base + $b['count'] + $f['count'];
        }

        // A laned skip re-enters along a reserved approach row above the target's rank, over the
        // arrival heads and their labels; straight drops need none.
        $entersInto = array_fill(0, $maxRank + 1, false);

        foreach ($graph->edges as $i => $e) {
            if ($isSkip($e) && !$edgeStraight[$i]) {
                $entersInto[$ranks[$e->to]] = true;
            }
        }

        for ($r = 0; $r < $maxRank; $r++) {
            if ($entersInto[$r + 1]) {
                $busTracks[$r]++;
            }
        }

        $rankH = array_map(
            static fn (array $row): int => $row === [] ? 3 : max(array_map(static fn (int $i): int => $sizes['boxH'][$i] + $sizes['extraH'][$i], $row)),
            $byRank,
        );

        // Per-end cardinalities want a row each around the verb: source card, label,
        // arrow-and-target card.
        $hasCards = false;

        foreach ($graph->edges as $e) {
            if ($e->cardFrom !== null || $e->cardTo !== null) {
                $hasCards = true;

                break;
            }
        }

        $gapY = $hasCards ? max(self::GAP_Y, 3) : self::GAP_Y;
        $rankY = array_fill(0, $maxRank + 1, 0);

        for ($r = 1; $r <= $maxRank; $r++) {
            $rankY[$r] = $rankY[$r - 1] + $rankH[$r - 1] + max($gapY, $busTracks[$r - 1] + 1);
        }

        $canvasH = $rankY[$maxRank] + $rankH[$maxRank];
        $bandEnd = [];

        for ($r = 0; $r <= $maxRank; $r++) {
            $bandEnd[] = $rankY[$r] + $rankH[$r];
        }

        // Approach rows sit just above each band's arrival-head row.
        $skipApproach = array_fill(0, $maxRank + 1, -1);

        for ($r = 0; $r < $maxRank; $r++) {
            if ($entersInto[$r + 1]) {
                $skipApproach[$r + 1] = $rankY[$r + 1] - 2;
            }
        }

        $diagramW = 1;

        foreach ($byRank as $r => $row) {
            foreach ($row as $idx) {
                $w = $sizes['boxW'][$idx];
                $h = $sizes['boxH'][$idx];
                $cx = $centers[$idx];
                $x = self::sat($cx, self::half($w));
                $y = $rankY[$r] + self::half($rankH[$r] - $h - $sizes['extraH'][$idx]);
                $placed[$idx] = new Placed($x, $y, $w, $h, $cx, $y + self::half($h), $r);
                $diagramW = max($diagramW, $x + $w);

                if ($sizes['extraH'][$idx] > 0 && $sizes['selfLabelW'][$idx] > 0) {
                    $diagramW = max($diagramW, $x + $w + 2 + $sizes['selfLabelW'][$idx]);
                }
            }
        }

        $contentW = $diagramW;

        foreach ($graph->edges as $e) {
            if ($e->from === $e->to) {
                continue;
            }

            if ($ranks[$e->to] === $ranks[$e->from] + 1) {
                foreach ([$e->label, $e->cardTo] as $part) {
                    if ($part !== null) {
                        $contentW = max($contentW, $placed[$e->to]->cx + 2 + min(Measure::width($part), Labels::MAX_LABEL));
                    }
                }

                if ($e->cardFrom !== null) {
                    $contentW = max($contentW, $placed[$e->from]->cx + 2 + Measure::width($e->cardFrom));
                }
            } elseif (self::isAdjacentBack($ranks, $e)) {
                $text = self::edgeText($e);

                if ($text !== null) {
                    $contentW = max($contentW, $placed[$e->to]->cx + 2 + min(Measure::width($text), Labels::MAX_LABEL));
                }
            } else {
                $text = self::edgeText($e);

                if ($text !== null) {
                    $contentW = max($contentW, $diagramW + min(Measure::width($text), Labels::MAX_LABEL) + 1);
                }
            }
        }

        $edgeLane = array_fill(0, $edgeCount, 0);
        $lanes = array_values(array_filter(self::laneSpans($graph, $ranks, $placed, true), static fn (array $s): bool => !$edgeStraight[$s['edge']]));
        $canvasW = $contentW;
        $laneBase = 0;

        if ($lanes !== []) {
            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($lanes, true);

            foreach ($assigned as [$idx, $slot]) {
                $edgeLane[$idx] = $slot;
            }

            $canvasW = $contentW + 1 + $count;
            $laneBase = $contentW + 1;
        }

        return [
            'canvasW' => $canvasW,
            'canvasH' => $canvasH,
            'bandEnd' => $bandEnd,
            'rankStart' => $rankY,
            'edgeBus' => $edgeBus,
            'laneBase' => $laneBase,
            'edgeLane' => $edgeLane,
            'skipApproach' => $skipApproach,
            'edgeEntryX' => $edgeEntryX,
            'edgeStraight' => $edgeStraight,
            'fwdEntryX' => $fwdEntryX,
            'edgeLabelLeft' => $edgeLabelLeft,
        ];
    }

    /**
     * Left-to-right placement, filling `placed` and returning the route plan.
     *
     * @param list<int> $ranks
     * @param list<list<int>> $byRank
     * @param array{boxW: list<int>, boxH: list<int>, layW: list<int>, layH: list<int>, extraH: list<int>, selfLabelW: list<int>} $sizes
     * @param list<Placed> $placed
     * @return array<string, mixed>
     */
    private static function placeLr(array $ranks, int $maxRank, array $byRank, array $sizes, Graph $graph, array &$placed): array
    {
        $edgeCount = count($graph->edges);
        $nodeCount = count($graph->nodes);
        $colW = array_map(static fn (array $row): int => $row === [] ? 0 : max(array_map(static fn (int $i): int => $sizes['boxW'][$i], $row)), $byRank);

        $centers = self::assignPositions($byRank, $sizes['layH'], 1, $graph->edges, $ranks);

        // A skip whose target entry row crosses no box on any intermediate rank runs straight
        // through the diagram into the target's left side, exiting through the source's
        // right-side fan; the bottom lane is the fallback.
        $isSkip = static fn (Edge $e): bool => $e->from !== $e->to && $ranks[$e->to] - $ranks[$e->from] > 1;

        // A back-edge target's bottom-entry `▲` stub sits one row below its box; a straight run
        // through that cell would appear to carry the arrival.
        $stubRows = [];

        foreach ($graph->edges as $e) {
            if ($e->from === $e->to || $ranks[$e->to] >= $ranks[$e->from]) {
                continue;
            }

            $t = $e->to;
            $stubRows[self::sat($centers[$t], self::half($sizes['boxH'][$t] + $sizes['extraH'][$t])) + $sizes['boxH'][$t]] = true;
        }

        $edgeStraight = array_fill(0, $edgeCount, false);

        foreach ($graph->edges as $i => $e) {
            if (!$isSkip($e)) {
                continue;
            }

            $row = $centers[$e->to];
            $straight = !isset($stubRows[$row]);

            for ($j = 0; $straight && $j < $nodeCount; $j++) {
                if (!($ranks[$j] <= $ranks[$e->from] || $ranks[$j] >= $ranks[$e->to] || abs($centers[$j] - $row) > self::half($sizes['boxH'][$j] + $sizes['extraH'][$j]))) {
                    $straight = false;
                }
            }

            $edgeStraight[$i] = $straight;
        }

        // Left-to-right edge labels sit in the gap after their source's column, so each gap sizes
        // to the widest label leaving through it. Straight skips label there too; a self-loop's
        // label hangs beside its own box (`selfLabelW`).
        $bandLabel = array_fill(0, $maxRank + 1, 0);

        foreach ($graph->edges as $i => $e) {
            if ($e->from === $e->to) {
                continue;
            }

            if ($ranks[$e->to] !== $ranks[$e->from] + 1 && !$edgeStraight[$i]) {
                continue;
            }

            $verb = $e->label === null ? 0 : min(Measure::width($e->label), Labels::MAX_LABEL);
            $cards = 0;

            foreach ([$e->cardFrom, $e->cardTo] as $card) {
                if ($card !== null) {
                    $cards += Measure::width($card) + 1;
                }
            }

            $bandLabel[$ranks[$e->from]] = max($bandLabel[$ranks[$e->from]], $verb + $cards);
        }

        $edgeBus = array_fill(0, $edgeCount, 0);
        $busTracks = array_fill(0, $maxRank + 1, 0);

        for ($r = 0; $r < $maxRank; $r++) {
            $spans = self::busSpans($graph, $ranks, $centers, $r, true, false);

            // Straight-skip departures ride their own band's bus tracks: endpoint sharing folds
            // them onto their siblings' column — one fan origin.
            foreach ($graph->edges as $i => $e) {
                if (!$isSkip($e) || !$edgeStraight[$i] || $ranks[$e->from] !== $r) {
                    continue;
                }

                $spans[] = [
                    'start' => min($centers[$e->from], $centers[$e->to]),
                    'end' => max($centers[$e->from], $centers[$e->to]),
                    'from' => $e->from,
                    'to' => $e->to,
                    'edge' => $i,
                ];
            }

            if ($spans === []) {
                continue;
            }

            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($spans);

            foreach ($assigned as [$idx, $slot]) {
                $edgeBus[$idx] = $slot;
            }

            $busTracks[$r] = $count;
        }

        $rankX = array_fill(0, $maxRank + 1, 0);

        for ($r = 1; $r <= $maxRank; $r++) {
            $gap = max(self::GAP_X + 1, $bandLabel[$r - 1] + 3, $busTracks[$r - 1] + 1);
            $rankX[$r] = $rankX[$r - 1] + $colW[$r - 1] + $gap;
        }

        $selfTails = [];

        foreach ($byRank[$maxRank] as $i) {
            if ($sizes['extraH'][$i] > 0 && $sizes['selfLabelW'][$i] > 0) {
                $selfTails[] = 2 + $sizes['selfLabelW'][$i];
            }
        }

        $canvasW = $rankX[$maxRank] + $colW[$maxRank] + ($selfTails === [] ? 0 : max($selfTails));
        $bandEnd = [];

        for ($r = 0; $r <= $maxRank; $r++) {
            $bandEnd[] = $rankX[$r] + $colW[$r];
        }

        $diagramH = 1;

        foreach ($byRank as $r => $row) {
            $x = $rankX[$r];

            foreach ($row as $idx) {
                $w = $sizes['boxW'][$idx];
                $h = $sizes['boxH'][$idx];
                $cy = $centers[$idx];
                $y = self::sat($cy, self::half($h + $sizes['extraH'][$idx]));
                $placed[$idx] = new Placed($x, $y, $w, $h, $x + self::half($w), $y + self::half($h), $r);
                $diagramH = max($diagramH, $y + $h + $sizes['extraH'][$idx]);
            }
        }

        $edgeLane = array_fill(0, $edgeCount, 0);
        $lanes = array_values(array_filter(self::laneSpans($graph, $ranks, $placed, false), static fn (array $s): bool => !$edgeStraight[$s['edge']]));
        $canvasH = $diagramH;
        $laneBase = 0;

        if ($lanes !== []) {
            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($lanes, true);

            foreach ($assigned as [$idx, $slot]) {
                $edgeLane[$idx] = $slot;
            }

            $canvasH = $diagramH + 1 + $count;
            $laneBase = $diagramH + 1;
        }

        return [
            'canvasW' => $canvasW,
            'canvasH' => $canvasH,
            'bandEnd' => $bandEnd,
            'rankStart' => $rankX,
            'edgeBus' => $edgeBus,
            'laneBase' => $laneBase,
            'edgeLane' => $edgeLane,
            'skipApproach' => array_fill(0, $maxRank + 1, -1),
            'edgeEntryX' => array_fill(0, $edgeCount, -1),
            'edgeStraight' => $edgeStraight,
            'fwdEntryX' => array_fill(0, $nodeCount, -1),
            'edgeLabelLeft' => array_fill(0, $edgeCount, false),
        ];
    }

    // -------------------------------------------------------------------- canvas

    /**
     * A graph ranked, placed, drawn and routed onto a fresh canvas. Null when the graph is empty
     * or over the cell cap.
     *
     * @param list<array<string, mixed>> $extras one `NodeExtra` per node
     */
    public static function layoutCanvas(Graph $graph, array $extras): ?Canvas
    {
        $n = count($graph->nodes);

        if ($n === 0) {
            return null;
        }

        // Parallel edges ride the same cells, so all labels after the first would be lost — they
        // are joined onto the first instead, before sizing so the joined label gets its room.
        $firstOf = [];

        foreach ($graph->edges as $i => $e) {
            if ($e->from === $e->to) {
                continue;
            }

            $key = "{$e->from}>{$e->to}";

            if (!array_key_exists($key, $firstOf)) {
                $firstOf[$key] = $i;

                continue;
            }

            if ($e->label !== null) {
                $first = $graph->edges[$firstOf[$key]];
                $first->label = $first->label === null ? $e->label : "{$first->label} / {$e->label}";
                $e->label = null;
            }
        }

        $ranks = self::computeRanks($graph);
        $maxRank = max([...$ranks, 0]);

        $byRank = array_fill(0, $maxRank + 1, []);

        foreach ($ranks as $idx => $rank) {
            $byRank[$rank][] = $idx;
        }

        self::orderRanks($byRank, $graph->edges, $ranks);

        // Lane edges exit through the rank's trailing side toward the lane strip; their endpoints
        // go last within the rank, or whatever the ordering put beyond them would sit in that
        // corridor and be cut through.
        $vertical = $graph->dir === 'down' || $graph->dir === 'up';
        $inLane = array_fill(0, $n, false);

        foreach ($graph->edges as $e) {
            if ($vertical && self::isAdjacentBack($ranks, $e)) {
                continue;
            }

            if ($e->from !== $e->to && $ranks[$e->to] !== $ranks[$e->from] + 1) {
                $inLane[$e->from] = true;
                $inLane[$e->to] = true;
            }
        }

        foreach ($byRank as &$row) {
            usort($row, static fn (int $a, int $b): int => (int) $inLane[$a] <=> (int) $inLane[$b]);
        }

        unset($row);

        $wrapped = array_map(static fn (Node $node): array => Labels::wrapLabel($node->label, Labels::WRAP_WIDTH, Labels::MAX_LINES), $graph->nodes);
        $widest = static fn (array $lines): int => max(1, $lines === [] ? 1 : max(array_map(Measure::width(...), $lines)));

        $boxW = [];
        $boxH = [];

        foreach ($extras as $i => $extra) {
            $boxW[] = match ($extra['kind']) {
                'frame' => max($extra['sub']->w + 2, Measure::width(Labels::fitLabel($graph->nodes[$i]->label, Labels::WRAP_WIDTH)) + 4),
                'compartments' => $widest(array_merge(...$extra['sections'])) + 2 * self::PAD + 2,
                default => $widest($wrapped[$i]) + 2 * self::PAD + 2,
            };
            $boxH[] = match ($extra['kind']) {
                'frame' => $extra['sub']->h + 2,
                'compartments' => array_sum(array_map(count(...), $extra['sections']))
                    + self::sat(count(array_filter($extra['sections'], static fn (array $s): bool => $s !== [])), 1) + 2,
                default => count($wrapped[$i]) + 2,
            };
        }

        // A self-edge needs two rows below its box, and room beside it for a label.
        $extraH = array_fill(0, $n, 0);
        $selfLabelW = array_fill(0, $n, 0);

        foreach ($graph->edges as $e) {
            if ($e->from !== $e->to) {
                continue;
            }

            $extraH[$e->from] = 2;
            $text = self::edgeText($e);

            if ($text !== null) {
                $selfLabelW[$e->from] = max($selfLabelW[$e->from], min(Measure::width($text), Labels::MAX_LABEL));
            }
        }

        for ($i = 0; $i < $n; $i++) {
            if ($extraH[$i] > 0) {
                $boxW[$i] = max($boxW[$i], 7);
            }
        }

        $layW = [];
        $layH = [];

        foreach ($boxW as $i => $w) {
            $layW[] = $w + ($selfLabelW[$i] > 0 ? 2 * ($selfLabelW[$i] + 3) : 0);
        }

        foreach ($boxH as $i => $h) {
            $layH[] = $h + $extraH[$i];
        }

        $sizes = ['boxW' => $boxW, 'boxH' => $boxH, 'layW' => $layW, 'layH' => $layH, 'extraH' => $extraH, 'selfLabelW' => $selfLabelW];

        $placed = [];

        for ($i = 0; $i < $n; $i++) {
            $placed[] = new Placed(0, 0, 0, 0, 0, 0, 0);
        }

        $plan = $vertical
            ? self::placeTd($ranks, $maxRank, $byRank, $sizes, $graph, $placed)
            : self::placeLr($ranks, $maxRank, $byRank, $sizes, $graph, $placed);

        if ($plan['canvasW'] * $plan['canvasH'] > self::MAX_CANVAS_CELLS) {
            return null;
        }

        $canvas = new Canvas($plan['canvasW'], $plan['canvasH']);
        // `BT` mirrors the finished canvas; multi-row content draws upside down so the flip
        // restores reading order.
        $mirrored = $graph->dir === 'up';

        for ($idx = 0; $idx < $n; $idx++) {
            $extra = $extras[$idx];
            $node = $graph->nodes[$idx];
            $canvas->curTag = $node->classes === null ? null : implode(' ', $node->classes);
            $canvas->curHref = $node->href;

            if ($extra['kind'] === 'frame') {
                self::drawFrame($canvas, $placed[$idx], $node->label, $extra['sub'], $mirrored);
            } elseif ($extra['kind'] === 'compartments') {
                self::drawClassBox($canvas, $placed[$idx], $extra['sections'], $mirrored);
            } else {
                self::drawBox($canvas, $placed[$idx], $wrapped[$idx], $node->shape, $mirrored);
            }
        }

        $canvas->curTag = null;
        $canvas->curHref = null;

        $laneLabels = [];

        foreach ($graph->edges as $i => $edge) {
            $canvas->curStyle = $edge->line === 'dotted' ? Canvas::STY_DOT : ($edge->line === 'thick' ? Canvas::STY_THICK : Canvas::STY_SOLID);

            if ($edge->from === $edge->to) {
                self::routeSelf($canvas, $placed[$edge->from], $edge);

                continue;
            }

            $from = $placed[$edge->from];
            $to = $placed[$edge->to];
            $adjacent = $to->rank === $from->rank + 1;
            $adjacentBack = $from->rank === $to->rank + 1;
            // A back edge crosses the band below its target's rank.
            $bus = $plan['bandEnd'][($adjacentBack ? $to : $from)->rank] + $plan['edgeBus'][$i];
            $lane = $plan['laneBase'] + $plan['edgeLane'][$i];

            if ($vertical) {
                if ($adjacent) {
                    self::routeForward($canvas, $from, $to, $edge, $bus, $plan['fwdEntryX'][$edge->to], $plan['edgeLabelLeft'][$i]);
                } elseif ($adjacentBack) {
                    self::routeBackAdjacent($canvas, $from, $to, $edge, $bus);
                } elseif ($to->rank > $from->rank) {
                    self::routeSkip(
                        $canvas,
                        $from,
                        $to,
                        $edge,
                        $lane,
                        $plan['edgeEntryX'][$i],
                        $bus,
                        $plan['skipApproach'][$to->rank],
                        $plan['edgeStraight'][$i],
                        $plan['edgeLabelLeft'][$i],
                    );
                } else {
                    self::routeBack($canvas, $from, $to, $edge, $lane);
                }
            } elseif ($adjacent) {
                self::routeForwardLr($canvas, $from, $to, $edge, $bus);
            } elseif ($to->rank > $from->rank && $plan['edgeStraight'][$i]) {
                self::routeSkipLr($canvas, $from, $to, $edge, $bus);
            } else {
                self::routeBackLr($canvas, $from, $to, $edge, $lane, $laneLabels);
            }
        }

        self::placeLaneLabels($canvas, $laneLabels);

        $canvas->finalizeMask();

        return $canvas;
    }

    /** The direction flip a finished canvas needs for `BT` / `RL`. */
    public static function orient(Canvas $canvas, Graph $graph): Canvas
    {
        if ($graph->dir === 'up') {
            $canvas->flipVertical();
        } elseif ($graph->dir === 'left') {
            $canvas->flipHorizontal();
        }

        return $canvas;
    }

    /** Flowchart and state diagrams: plain boxes, no extra content. */
    public static function layoutFlowchart(Graph $graph): ?Canvas
    {
        $extras = array_map(static fn (): array => ['kind' => 'plain'], $graph->nodes);
        $canvas = self::layoutCanvas($graph, $extras);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    /** Class and ER diagrams: boxes divided into title / attribute / method rows. */
    public static function layoutClass(Graph $graph): ?Canvas
    {
        $extras = array_map(static fn (Node $node): array => ['kind' => 'compartments', 'sections' => $node->sections ?? [[$node->label]]], $graph->nodes);
        $canvas = self::layoutCanvas($graph, $extras);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    // -------------------------------------------------------------------- groups

    private static function nodeKey(int $i): string
    {
        return "n{$i}";
    }

    private static function groupKey(int $i): string
    {
        return "g{$i}";
    }

    /** A scope as an array key: upstream's `Map` keys the top level by `null`, here `-1`. */
    private static function scopeKey(?int $scope): int
    {
        return $scope ?? -1;
    }

    /**
     * A flowchart that uses `subgraph`. Each subgraph becomes a framed box holding its own
     * independently laid-out canvas. An edge is drawn in the innermost scope containing both
     * endpoints; one crossing a subgraph boundary attaches to the frame instead of the node.
     */
    public static function layoutGrouped(Graph $graph): ?Canvas
    {
        // A node whose id matches a subgraph id stands in for that subgraph.
        $proxy = [];

        foreach ($graph->groups as $gi => $g) {
            $ni = $graph->find($g->id);

            if ($ni !== null) {
                $proxy[$ni] = $gi;
            }
        }

        $groupChain = static function (?int $g) use ($graph): array {
            $chain = [];
            $cur = $g;

            while ($cur !== null) {
                $chain[] = $cur;
                $cur = $graph->groups[$cur]->parent;
            }

            return array_reverse($chain);
        };
        $endpoint = static fn (int $n): array => !array_key_exists($n, $proxy)
            ? ['key' => self::nodeKey($n), 'chain' => $groupChain($graph->nodeGroup[$n])]
            : ['key' => self::groupKey($proxy[$n]), 'chain' => $groupChain($graph->groups[$proxy[$n]]->parent)];

        // Edges bucketed by the scope that draws them (`scopeKey()`).
        $scopeEdges = [];
        $referenced = array_fill(0, count($graph->groups), false);

        foreach ($graph->edges as $ei => $e) {
            $f = $endpoint($e->from);
            $t = $endpoint($e->to);
            $k = 0;

            while ($k < count($f['chain']) && $k < count($t['chain']) && $f['chain'][$k] === $t['chain'][$k]) {
                $k++;
            }

            $scope = $k === 0 ? null : $f['chain'][$k - 1];
            $fKey = count($f['chain']) > $k ? self::groupKey($f['chain'][$k]) : $f['key'];
            $tKey = count($t['chain']) > $k ? self::groupKey($t['chain'][$k]) : $t['key'];

            foreach ([$fKey, $tKey] as $key) {
                if (str_starts_with($key, 'g')) {
                    $referenced[(int) substr($key, 1)] = true;
                }
            }

            $scopeEdges[self::scopeKey($scope)][] = [$fKey, $tKey, $ei];
        }

        $directNodes = [];

        foreach ($graph->nodeGroup as $ni => $g) {
            if (array_key_exists($ni, $proxy)) {
                continue;
            }

            $directNodes[self::scopeKey($g)][] = $ni;
        }

        // Drop empty subgraphs, but keep any that an edge attaches to. Walked by the actual child
        // relation: state `--` regions reparent earlier groups under later ones, so index order
        // says nothing about depth.
        $childGroups = array_fill(0, count($graph->groups), []);

        foreach ($graph->groups as $gi => $g) {
            if ($g->parent !== null) {
                $childGroups[$g->parent][] = $gi;
            }
        }

        $keep = array_fill(0, count($graph->groups), false);
        $visit = static function (int $gi) use (&$visit, &$keep, $referenced, $directNodes, $childGroups): bool {
            $kept = $referenced[$gi] || ($directNodes[$gi] ?? []) !== [];

            foreach ($childGroups[$gi] as $c) {
                if ($visit($c)) {
                    $kept = true;
                }
            }

            $keep[$gi] = $kept;

            return $kept;
        };

        foreach ($graph->groups as $gi => $g) {
            if ($g->parent === null) {
                $visit($gi);
            }
        }

        $canvas = self::buildScope($graph, null, $scopeEdges, $directNodes, $keep);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    /**
     * One scope's nodes and kept child subgraphs laid out as a graph of their own, each child
     * framed around its recursively built canvas.
     *
     * @param array<int, list<array{0: string, 1: string, 2: int}>> $scopeEdges
     * @param array<int, list<int>> $directNodes
     * @param list<bool> $keep
     */
    private static function buildScope(Graph $graph, ?int $scope, array $scopeEdges, array $directNodes, array $keep): ?Canvas
    {
        $items = array_map(self::nodeKey(...), $directNodes[self::scopeKey($scope)] ?? []);

        foreach ($graph->groups as $gi => $g) {
            if ($g->parent === $scope && $keep[$gi]) {
                $items[] = self::groupKey($gi);
            }
        }

        if ($items === []) {
            return new Canvas(1, 1);
        }

        $indexOf = [];
        $nodes = [];
        $extras = [];

        foreach ($items as $item) {
            $indexOf[$item] = count($nodes);
            $i = (int) substr($item, 1);

            if (str_starts_with($item, 'n')) {
                $source = $graph->nodes[$i];
                $nodes[] = new Node($source->label, $source->shape, null, $source->classes, $source->href);
                $extras[] = ['kind' => 'plain'];
            } else {
                $sub = self::buildScope($graph, $i, $scopeEdges, $directNodes, $keep);

                if ($sub === null) {
                    return null;
                }

                $nodes[] = new Node($graph->groups[$i]->label, 'rect');
                $extras[] = ['kind' => 'frame', 'sub' => $sub];
            }
        }

        $edges = [];

        foreach ($scopeEdges[self::scopeKey($scope)] ?? [] as [$f, $t, $ei]) {
            if (!array_key_exists($f, $indexOf) || !array_key_exists($t, $indexOf)) {
                continue;
            }

            $e = $graph->edges[$ei];
            $edges[] = new Edge($indexOf[$f], $indexOf[$t], $e->label, $e->headTo, $e->headFrom, $e->line);
        }

        // Layout only reads nodes/edges/dir, so a bare Graph carrying those is enough.
        $synth = new Graph($graph->dir);
        $synth->nodes = $nodes;
        $synth->edges = $edges;

        return self::layoutCanvas($synth, $extras);
    }

    // ------------------------------------------------------------------- drawing

    /**
     * A box outline with its lines centred inside: rounded for `round`, double-lined for
     * `diamond`. `mirrored` draws the lines bottom up, for a canvas `BT` will flip.
     *
     * @param list<string> $lines
     */
    public static function drawBox(Canvas $canvas, Placed $p, array $lines, string $shape, bool $mirrored = false): void
    {
        $x = $p->x;
        $y = $p->y;
        $w = $p->w;
        $h = $p->h;
        $right = $x + $w - 1;
        $bottom = $y + $h - 1;

        // A diamond is a double-line box — the terminal's nod to `A{...}`.
        [$tl, $tr, $bl, $br] = match ($shape) {
            'diamond' => ['╔', '╗', '╚', '╝'],
            'round' => ['╭', '╮', '╰', '╯'],
            default => ['┌', '┐', '└', '┘'],
        };
        $canvas->set($x, $y, $tl, 'border');
        $canvas->set($right, $y, $tr, 'border');
        $canvas->set($x, $bottom, $bl, 'border');
        $canvas->set($right, $bottom, $br, 'border');

        if ($shape === 'diamond') {
            // Double lines have no direction bits; edges tee into them through the mixed
            // junctions (`╤` `╧` `╟` `╢`) that `finalizeMask()` resolves.
            for ($cx = $x + 1; $cx < $right; $cx++) {
                $canvas->set($cx, $y, '═', 'border');
                $canvas->set($cx, $bottom, '═', 'border');
            }

            for ($cy = $y + 1; $cy < $bottom; $cy++) {
                $canvas->set($x, $cy, '║', 'border');
                $canvas->set($right, $cy, '║', 'border');
            }
        } else {
            // The perimeter is drawn as bits so edges can tee into it, but it is the box
            // outline, so it claims `border` rather than `edge`.
            for ($cx = $x + 1; $cx < $right; $cx++) {
                $canvas->addBits($cx, $y, Canvas::L | Canvas::R, 'border');
                $canvas->addBits($cx, $bottom, Canvas::L | Canvas::R, 'border');
            }

            for ($cy = $y + 1; $cy < $bottom; $cy++) {
                $canvas->addBits($x, $cy, Canvas::U | Canvas::D, 'border');
                $canvas->addBits($right, $cy, Canvas::U | Canvas::D, 'border');
            }
        }

        for ($cy = $y; $cy <= $bottom; $cy++) {
            for ($cx = $x; $cx <= $right; $cx++) {
                $i = $canvas->idx($cx, $cy);
                $canvas->occupied[$i] = 1;

                if ($canvas->curTag !== null) {
                    $canvas->tag[$i] = $canvas->curTag;
                }

                if ($canvas->curHref !== null) {
                    $canvas->href[$i] = $canvas->curHref;
                }
            }
        }

        $inner = max(1, self::sat($w, 2 * self::PAD + 2));
        $ordered = $mirrored ? array_reverse($lines) : $lines;

        foreach ($ordered as $li => $line) {
            $text = Labels::fitLabel($line, $inner);
            $textX = $x + 1 + self::PAD + self::half(self::sat($inner, Measure::width($text)));
            Canvas::drawText($canvas, $text, $textX, $y + 1 + $li, 'text');
        }
    }

    /**
     * A class or ER box: sections separated by horizontal rules, title centred.
     *
     * @param list<list<string>> $sections
     */
    private static function drawClassBox(Canvas $canvas, Placed $p, array $sections, bool $mirrored = false): void
    {
        self::drawBox($canvas, $p, [], 'rect');
        $inner = max(1, self::sat($p->w, 2 * self::PAD + 2));
        $rows = [];

        foreach ($sections as $si => $section) {
            if ($section === []) {
                continue;
            }

            if ($rows !== []) {
                $rows[] = null;
            }

            foreach ($section as $line) {
                $rows[] = ['text' => Labels::fitLabel($line, $inner), 'center' => $si === 0];
            }
        }

        if ($mirrored) {
            $rows = array_reverse($rows);
        }

        foreach ($rows as $ri => $r) {
            $row = $p->y + 1 + $ri;

            if ($r === null) {
                $canvas->set($p->x, $row, '├', 'border');

                for ($x = $p->x + 1; $x < $p->x + $p->w - 1; $x++) {
                    $canvas->set($x, $row, '─', 'border');
                }

                $canvas->set($p->x + $p->w - 1, $row, '┤', 'border');
            } else {
                $tx = $r['center'] ? $p->x + 1 + self::PAD + self::half(self::sat($inner, Measure::width($r['text']))) : $p->x + 1 + self::PAD;
                Canvas::drawTextOverEdges($canvas, $r['text'], $tx, $row, 'text');
            }
        }
    }

    /** A subgraph frame: a titled box with a finished sub-canvas centred inside. */
    private static function drawFrame(Canvas $canvas, Placed $p, string $title, Canvas $sub, bool $mirrored = false): void
    {
        self::drawBox($canvas, $p, [], 'rect');

        // An unlabelled frame (a state `--` region) keeps its border unbroken.
        if ($title !== '') {
            $t = Labels::fitLabel($title, self::sat($p->w, 4));
            // Mirrored: the bottom border becomes the top after the flip.
            Canvas::drawTextOverEdges($canvas, " {$t} ", $p->x + 1, $mirrored ? $p->y + $p->h - 1 : $p->y, 'text');
        }

        $canvas->blit($sub, $p->x + 1 + self::half($p->w - 2 - $sub->w), $p->y + 1 + self::half($p->h - 2 - $sub->h));
    }

    // ------------------------------------------------------------------- routing

    /** The glyph for an edge head, given the plain arrow for its direction. */
    private static function headGlyph(string $head, string $arrow): string
    {
        return match ($head) {
            'circle' => 'o',
            'cross' => '×',
            'diamondFill' => '◆',
            'diamondOpen' => '◇',
            'triangle' => ['▼' => '▽', '▲' => '△', '◄' => '◁', '▶' => '▷'][$arrow] ?? $arrow,
            default => $arrow,
        };
    }

    /**
     * Adjacent ranks, top-down: drop, jog along the bus row, drop into the head. `entryX` overrides
     * the centre entry column when the target's top is shared with skip entries; `labelLeft`
     * renders the label left of the arrowhead.
     */
    private static function routeForward(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $bus, int $entryX = -1, bool $labelLeft = false): void
    {
        $tx = $entryX === -1 ? $to->cx : $entryX;
        // A jog of one column reads as a kink; snap straight instead.
        $bx = abs($from->cx - $tx) <= 1 ? $tx : $from->cx;
        $by = $from->y + $from->h - 1;
        $headRow = $to->y - 1;

        $canvas->junction($bx, $by, Canvas::D);
        $canvas->segV($bx, $by, $bus);

        if ($bx === $tx) {
            $canvas->segV($bx, $bus, $headRow);
        } else {
            $canvas->segH($bus, $bx, $tx);
            $canvas->segV($tx, $bus, $headRow);
        }

        if ($edge->headTo === 'none') {
            $canvas->addBits($tx, $headRow, Canvas::U);
        } else {
            $canvas->set($tx, $headRow, self::headGlyph($edge->headTo, '▼'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($bx, $by, self::headGlyph($edge->headFrom, '▲'), 'edge');
        }

        if ($edge->cardFrom === null && $edge->cardTo === null) {
            if ($edge->label !== null) {
                $start = $labelLeft ? self::sat($tx, min(Measure::width($edge->label), Labels::MAX_LABEL)) : $tx + 1;
                self::placeLabel($canvas, $edge->label, $headRow, $start);
            }

            return;
        }

        // Cardinalities sit at their own ends; the verb takes the row above the head, falling
        // back beside the target card when the gap has no spare row.
        $srcRow = $by + 1;

        if ($edge->cardFrom !== null) {
            self::placeLabel($canvas, $edge->cardFrom, $srcRow, $bx + 1);
        }

        if ($edge->cardTo !== null) {
            self::placeLabel($canvas, $edge->cardTo, $headRow, $tx + 1);
        }

        if ($edge->label !== null) {
            $midRow = $headRow - 1;

            if ($midRow > $srcRow) {
                $lineX = $midRow > $bus ? $tx : $bx;
                self::placeLabel($canvas, $edge->label, $midRow, $lineX + 1);
            } else {
                self::placeLabel($canvas, $edge->label, $headRow, $tx + 1 + ($edge->cardTo === null ? 0 : Measure::width($edge->cardTo) + 1));
            }
        }
    }

    /**
     * Adjacent ranks, top-down, against the flow: up out of the source's top, jog along the band,
     * arrow into the target's bottom — the short local return mermaid draws, not a trip around
     * the diagram.
     */
    private static function routeBackAdjacent(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $bus): void
    {
        // Attach right of centre so the return does not merge with the forward exits and
        // arrivals that own the centre column.
        $tx = min($to->x + $to->w - 2, $to->cx + 2);
        $bx0 = min($from->x + $from->w - 2, $from->cx + 2);
        $bx = abs($bx0 - $tx) <= 1 ? $tx : $bx0;
        $fy = $from->y;
        $headRow = $to->y + $to->h;

        $canvas->junction($bx, $fy, Canvas::U);

        if ($bx === $tx) {
            $canvas->segV($bx, $headRow, $fy);
        } else {
            $canvas->segV($bx, $bus, $fy);
            $canvas->segH($bus, $bx, $tx);
            $canvas->segV($tx, $headRow, $bus);
        }

        if ($edge->headTo === 'none') {
            $canvas->addBits($tx, $headRow, Canvas::D);
        } else {
            $canvas->set($tx, $headRow, self::headGlyph($edge->headTo, '▲'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($bx, $fy, self::headGlyph($edge->headFrom, '▼'), 'edge');
        }

        $text = self::edgeText($edge);

        if ($text !== null) {
            self::placeLabel($canvas, $text, $headRow, $tx + 1);
        }
    }

    /** A self-edge: a stub loop hanging below the box. */
    private static function routeSelf(Canvas $canvas, Placed $p, Edge $edge): void
    {
        $bottom = $p->y + $p->h - 1;
        $exitX = $p->cx + 1;
        $retX = $p->x + $p->w - 2;

        if ($retX <= $exitX || $bottom + 2 >= $canvas->h) {
            return;
        }

        [$v, $h, $bl, $br] = match ($edge->line) {
            'dotted' => ['╎', '╌', '╰', '╯'],
            'thick' => ['┃', '━', '┗', '┛'],
            default => ['│', '─', '╰', '╯'],
        };

        $canvas->junction($exitX, $bottom, Canvas::D);
        $canvas->set($exitX, $bottom + 1, $v, 'edge');
        $canvas->set($exitX, $bottom + 2, $bl, 'edge');

        for ($x = $exitX + 1; $x < $retX; $x++) {
            $canvas->set($x, $bottom + 2, $h, 'edge');
        }

        $canvas->set($retX, $bottom + 2, $br, 'edge');
        $canvas->set($retX, $bottom + 1, self::headGlyph($edge->headTo, '▲'), 'edge');
        $text = self::edgeText($edge);

        if ($text !== null) {
            self::placeLabel($canvas, $text, $bottom + 1, $p->x + $p->w + 1);
        }
    }

    /**
     * Forward skip edge, top-down: out the source's bottom onto its band's bus row (endpoint
     * sharing folds it onto its siblings' row — one `┴` origin split), then either straight down an
     * unobstructed column into the target's top, or around: down the lane and in along the
     * reserved approach row above the target's rank, over the arrival heads and labels.
     */
    private static function routeSkip(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $laneX, int $entryX, int $busY, int $approachY, bool $straight, bool $labelLeft): void
    {
        $bx = $from->cx;
        $bottom = $from->y + $from->h - 1;

        $canvas->junction($bx, $bottom, Canvas::D);
        $canvas->segV($bx, $bottom, $busY);

        if ($straight) {
            $canvas->segH($busY, $bx, $entryX);
            $canvas->segV($entryX, $busY, $to->y - 1);
        } else {
            $canvas->segH($busY, $bx, $laneX);
            $canvas->segV($laneX, $busY, $approachY);
            $canvas->segH($approachY, $entryX, $laneX);
            $canvas->segV($entryX, $approachY, $to->y - 1);
        }

        if ($edge->headTo === 'none') {
            $canvas->addBits($entryX, $to->y - 1, Canvas::D);
        } else {
            $canvas->set($entryX, $to->y - 1, self::headGlyph($edge->headTo, '▼'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($bx, $bottom, self::headGlyph($edge->headFrom, '▲'), 'edge');
        }

        $text = self::edgeText($edge);

        if ($text !== null) {
            $start = $labelLeft ? self::sat($entryX, min(Measure::width($text), Labels::MAX_LABEL)) : $entryX + 1;
            self::placeLabel($canvas, $text, $to->y - 1, $start);
        }
    }

    /**
     * Multi-rank back edge, top-down: out the right side, up a lane, back in through the target's
     * right side — side entry keeps returns recognisable at a glance.
     */
    private static function routeBack(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $laneX): void
    {
        $sx = $from->x + $from->w - 1;
        $sy = $from->cy;
        $backText = self::edgeText($edge);

        $canvas->junction($sx, $sy, Canvas::R);
        $canvas->segH($sy, $sx, $laneX);

        if ($edge->headFrom !== 'none') {
            $canvas->set($sx, $sy, self::headGlyph($edge->headFrom, '◄'), 'edge');
        }

        $tx = $to->x + $to->w - 1;
        $tyc = $to->cy;
        $canvas->segV($laneX, $sy, $tyc);
        $canvas->segH($tyc, $tx + 1, $laneX);

        if ($edge->headTo === 'none') {
            $canvas->addBits($tx + 1, $tyc, Canvas::R);
        } else {
            $canvas->set($tx + 1, $tyc, self::headGlyph($edge->headTo, '◄'), 'edge');
        }

        if ($backText !== null) {
            self::placeLabel($canvas, $backText, self::sat($tyc, 1), self::sat($laneX, Measure::width($backText) + 1));
        }
    }

    /** Adjacent ranks, left-to-right: out the right side, jog on the bus column. */
    private static function routeForwardLr(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $bus): void
    {
        $rx = $from->x + $from->w - 1;
        $ry = $from->cy;
        $ly = $to->cy;
        $headCol = $to->x - 1;

        $canvas->junction($rx, $ry, Canvas::R);
        $canvas->segH($ry, $rx, $bus);

        if ($ry === $ly) {
            $canvas->segH($ry, $bus, $headCol);
        } else {
            $canvas->segV($bus, $ry, $ly);
            $canvas->segH($ly, $bus, $headCol);
        }

        if ($edge->headTo === 'none') {
            $canvas->addBits($headCol, $ly, Canvas::R);
        } else {
            $canvas->set($headCol, $ly, self::headGlyph($edge->headTo, '▶'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($rx, $ry, self::headGlyph($edge->headFrom, '◄'), 'edge');
        }

        // The verb keeps its usual spot above the line; cardinalities hug their own ends on the
        // rows above the departure and arrival cells.
        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, self::sat($ly, 1), $bus + 1);
        }

        if ($edge->cardFrom !== null) {
            self::placeLabel($canvas, $edge->cardFrom, self::sat($ry, 1), $rx + 1);
        }

        if ($edge->cardTo !== null) {
            self::placeLabel($canvas, $edge->cardTo, self::sat($ly, 1), self::sat($headCol, Measure::width($edge->cardTo)));
        }
    }

    /**
     * Straight forward skip, left-to-right: out the source's right-side fan, jog on its own band's
     * bus column, then straight along the target's entry row into its left side — merging with
     * the forward arrivals' head. Only used when that row crosses no intermediate box.
     */
    private static function routeSkipLr(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $bus): void
    {
        $ry = $from->cy;
        $ty = $to->cy;
        $rx = $from->x + $from->w - 1;
        $headCol = $to->x - 1;

        $canvas->junction($rx, $ry, Canvas::R);
        $canvas->segH($ry, $rx, $bus);

        if ($ry !== $ty) {
            $canvas->segV($bus, $ry, $ty);
        }

        $canvas->segH($ty, $bus, $headCol);

        if ($edge->headTo === 'none') {
            $canvas->addBits($headCol, $ty, Canvas::R);
        } else {
            $canvas->set($headCol, $ty, self::headGlyph($edge->headTo, '▶'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($rx, $ry, self::headGlyph($edge->headFrom, '◄'), 'edge');
        }

        // Label after the bus jog, where forward labels sit — the gap before the target belongs
        // to the arrivals that end there.
        $text = self::edgeText($edge);

        if ($text !== null) {
            self::placeLabel($canvas, $text, self::sat($ty, 1), $bus + 1);
        }
    }

    /**
     * Skip or back edge, left-to-right: down out the bottom, along a lane, back up. The label is
     * collected into `laneLabels`, placed once every edge has landed.
     *
     * @param list<array{text: string, y: int, lo: int, hi: int}> $laneLabels
     */
    private static function routeBackLr(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $laneY, array &$laneLabels): void
    {
        $sx = $from->cx;
        $sy = $from->y + $from->h - 1;
        $tx = $to->cx;
        $ty = $to->y + $to->h - 1;

        $canvas->junction($sx, $sy, Canvas::D);
        $canvas->segV($sx, $sy, $laneY);
        $canvas->segH($laneY, $sx, $tx);
        $canvas->segV($tx, $laneY, $ty + 1);

        if ($edge->headTo === 'none') {
            $canvas->addBits($tx, $ty + 1, Canvas::D);
        } else {
            $canvas->set($tx, $ty + 1, self::headGlyph($edge->headTo, '▲'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($sx, $sy, self::headGlyph($edge->headFrom, '▲'), 'edge');
        }

        // The label interrupts its own lane row — the row above belongs to the neighbouring lane
        // once several stack. Deferred until all edges landed, so it can dodge the verticals
        // that cross this row.
        $backText = self::edgeText($edge);

        if ($backText !== null) {
            $laneLabels[] = [
                'text' => ' ' . Labels::fitLabel($backText, Labels::MAX_LABEL) . ' ',
                'y' => $laneY,
                'lo' => min($sx, $tx),
                'hi' => max($sx, $tx),
            ];
        }
    }

    /**
     * Each lane label written onto its own row, centred on the run but slid to the nearest
     * stretch free of crossing verticals, arrowheads and earlier labels — clearing a crossing
     * line under a label would sever it.
     *
     * @param list<array{text: string, y: int, lo: int, hi: int}> $labels
     */
    private static function placeLaneLabels(Canvas $canvas, array $labels): void
    {
        foreach ($labels as ['text' => $text, 'y' => $y, 'lo' => $lo, 'hi' => $hi]) {
            $tw = Measure::width($text);
            $lastStart = $hi - 1 - $tw;

            if ($lastStart < $lo + 1 || $y >= $canvas->h) {
                continue;
            }

            $clear = static function (int $start) use ($canvas, $tw, $y): bool {
                for ($x = $start; $x < $start + $tw; $x++) {
                    $i = $canvas->idx($x, $y);

                    if (!isset($canvas->ch[$i]) || $canvas->occupied[$i] === 1 || ($canvas->mask[$i] & (Canvas::U | Canvas::D)) !== 0 || $canvas->ch[$i] !== ' ') {
                        return false;
                    }
                }

                return true;
            };
            $mid = min(max(self::half($lo + $hi) - self::half($tw), $lo + 1), $lastStart);
            $at = $mid;

            for ($d = 0; ; $d++) {
                $left = $mid - $d;
                $right = $mid + $d;

                if ($left < $lo + 1 && $right > $lastStart) {
                    break;
                }

                if ($left >= $lo + 1 && $clear($left)) {
                    $at = $left;

                    break;
                }

                if ($right <= $lastStart && $clear($right)) {
                    $at = $right;

                    break;
                }
            }

            Canvas::drawTextOverEdges($canvas, $text, $at, $y, 'edgeLabel');
        }
    }

    /** An edge label written from `startX`, stopping at the first cell already taken. */
    private static function placeLabel(Canvas $canvas, string $label, int $row, int $startX): void
    {
        if ($row >= $canvas->h) {
            return;
        }

        $text = Labels::fitLabel($label, Labels::MAX_LABEL);
        $x = $startX;

        foreach (Measure::measured($text) as [$c, $cw]) {
            if ($cw === 0) {
                continue;
            }

            if ($x + $cw > $canvas->w) {
                break;
            }

            $blocked = false;

            for ($k = 0; $k < $cw; $k++) {
                $i = $canvas->idx($x + $k, $row);

                // A cell off the grid is taken, as upstream's `undefined` reads.
                if (!isset($canvas->ch[$i]) || $canvas->ch[$i] !== ' ' || $canvas->mask[$i] !== 0 || $canvas->occupied[$i] !== 0) {
                    $blocked = true;
                }
            }

            if ($blocked) {
                break;
            }

            $canvas->set($x, $row, $c, 'edgeLabel');

            for ($k = 1; $k < $cw; $k++) {
                $canvas->set($x + $k, $row, Canvas::CONT, 'edgeLabel');
            }

            $x += $cw;
        }
    }
}
