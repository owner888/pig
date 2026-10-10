<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `layout.ts`: graph layout — rank, order, place, route, draw.
 *
 * Follows the Sugiyama outline: assign ranks along the flow axis, reorder within ranks to cut
 * crossings, then relax positions on the cross axis so chains stay straight. Edges between
 * adjacent ranks share horizontal "bus" rows; everything else is routed around the diagram
 * through vertical "lanes". `BT` and `RL` reuse the `TD`/`LR` layouts and flip the finished
 * canvas, so text never ends up mirrored.
 *
 * A node's `NodeExtra` is an array: `['kind' => 'plain']`, `['kind' => 'frame', 'sub' => Canvas]`
 * or `['kind' => 'compartments', 'sections' => list<list<string>>]`. A span competing for a track
 * (`Span5`) is `[start, end, from, to, edgeIndex]`. `NodeSizes` and `RoutePlan` are arrays keyed
 * by upstream's field names.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Layout
{
    /** Cells of padding between a box border and its text. */
    private const int PAD = 1;

    /** Minimum horizontal / vertical space between boxes. */
    private const int GAP_X = 3;
    private const int GAP_Y = 2;

    /** Refuse to allocate a canvas larger than this many cells. */
    private const int MAX_CANVAS_CELLS = 1 << 21;

    /** Saturating subtraction; Rust's `usize` arithmetic never goes negative. */
    private static function sat(int $a, int $b): int
    {
        return max(0, $a - $b);
    }

    private static function half(int $n): int
    {
        return (int) floor($n / 2);
    }

    /** JS `Math.round()`: nearest integer, ties toward +∞ (PHP's `round()` ties away from zero). */
    private static function jsRound(int|float $x): int
    {
        $f = floor($x);

        return (int) ($x - $f >= 0.5 ? $f + 1 : $f);
    }

    // ------------------------------------------------------------------ ranking

    /**
     * grok-mermaid's `computeRanks()`: longest-path ranking over the graph's DAG. Back edges
     * (those closing a cycle) are excluded by a DFS colouring pass, so `A --> B --> C --> A`
     * still ranks 0, 1, 2 rather than diverging.
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
     * grok-mermaid's `dfsDag()`: iterative DFS recording postorder and skipping edges back into
     * the stack.
     *
     * @param list<list<int>> $children
     * @param list<int> $color
     * @param list<list<int>> $dag
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
     * grok-mermaid's `orderRanks()`: nodes reordered within each rank to cut edge crossings
     * (barycenter sweeps) — alternate down/up passes sort each rank by the mean position of its
     * neighbours, keeping whichever ordering crossed least. `byRank` is rewritten in place.
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
     * @param list<int> $pos
     */
    private static function reindex(array $row, array &$pos): void
    {
        foreach ($row as $i => $v) {
            $pos[$v] = $i;
        }
    }

    /**
     * grok-mermaid's `sortByBarycenter()`: a row stably sorted by its nodes' mean neighbour
     * position, a node without neighbours keeping its own.
     *
     * @param list<int> $row
     * @param list<list<int>> $neigh
     * @param list<int> $pos
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
     * grok-mermaid's `countCrossings()`: pairs of adjacent-rank edges out of one rank that cross.
     *
     * @param list<Edge> $edges
     * @param list<int> $ranks
     * @param list<int> $pos
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
     * grok-mermaid's `assignPositions()`: a cross-axis centre for every node so nodes line up
     * under their neighbours — each drifts toward the average of its neighbours while ranks keep
     * their order and boxes keep `sep` between them.
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
     * grok-mermaid's `relaxRank()`: one rank pulled toward its neighbours' mean positions.
     *
     * @param list<int> $nodes
     * @param list<list<int>> $neigh
     * @param list<int|float> $pos
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
     * grok-mermaid's `assignTracks()`: spans packed into as few parallel tracks as possible. Two
     * spans share a track when they are two cells apart, or when they share an endpoint — edges
     * fanning out of one node deliberately reuse a single row so a merge draws one arrowhead
     * rather than a stack of them.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4: int}> $spans
     * @return array{assigned: list<array{0: int, 1: int}>, count: int}
     */
    public static function assignTracks(array $spans): array
    {
        $sorted = $spans;
        usort($sorted, static function (array $a, array $b): int {
            for ($i = 0; $i < 5; $i++) {
                if ($a[$i] !== $b[$i]) {
                    return $a[$i] <=> $b[$i];
                }
            }

            return 0;
        });
        $tracks = [];
        $assigned = [];

        foreach ($sorted as [$s, $e, $f, $t, $idx]) {
            $slot = -1;

            foreach ($tracks as $k => $members) {
                $fits = true;

                foreach ($members as [$s2, $e2, $f2, $t2]) {
                    if (!($e2 + 2 <= $s || $e + 2 <= $s2 || $f2 === $f || $t2 === $t)) {
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

            $tracks[$slot][] = [$s, $e, $f, $t];
            $assigned[] = [$idx, $slot];
        }

        return ['assigned' => $assigned, 'count' => count($tracks)];
    }

    /**
     * grok-mermaid's `busSpans()`: edges from rank `r` to `r + 1` that must jog sideways, so
     * need a bus row.
     *
     * @param list<int> $ranks
     * @param list<int> $centers
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private static function busSpans(Graph $graph, array $ranks, array $centers, int $r, bool $exact): array
    {
        $out = [];

        foreach ($graph->edges as $i => $e) {
            $jogs = $exact
                ? $centers[$e->from] !== $centers[$e->to]
                : abs($centers[$e->from] - $centers[$e->to]) > 1;

            if ($e->from !== $e->to && $ranks[$e->from] === $r && $ranks[$e->to] === $r + 1 && $jogs) {
                $out[] = [
                    min($centers[$e->from], $centers[$e->to]),
                    max($centers[$e->from], $centers[$e->to]),
                    $e->from,
                    $e->to,
                    $i,
                ];
            }
        }

        return $out;
    }

    /**
     * grok-mermaid's `laneSpans()`: edges skipping a rank or running backwards; these go around
     * in a lane.
     *
     * @param list<int> $ranks
     * @param list<Placed> $placed
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private static function laneSpans(Graph $graph, array $ranks, array $placed, bool $vertical): array
    {
        $out = [];

        foreach ($graph->edges as $i => $e) {
            if ($e->from === $e->to || $ranks[$e->to] === $ranks[$e->from] + 1) {
                continue;
            }

            $pf = $placed[$e->from];
            $pt = $placed[$e->to];
            $a = $vertical ? min($pf->cy, $pt->cy) : min($pf->cx, $pt->cx);
            $b = $vertical ? max($pf->cy, $pt->cy) : max($pf->cx, $pt->cx);
            $out[] = [$a, $b, $e->from, $e->to, $i];
        }

        return $out;
    }

    // ----------------------------------------------------------------- placement

    /**
     * grok-mermaid's `placeTd()`: top-down placement, filling `placed` and returning the route
     * plan (`canvasW`, `canvasH`, `bandEnd`, `edgeBus`, `laneBase`, `edgeLane`).
     *
     * @param list<int> $ranks
     * @param list<list<int>> $byRank
     * @param array{boxW: list<int>, boxH: list<int>, layW: list<int>, layH: list<int>, extraH: list<int>, selfLabelW: list<int>} $sizes
     * @param list<Placed> $placed
     * @return array{canvasW: int, canvasH: int, bandEnd: list<int>, edgeBus: list<int>, laneBase: int, edgeLane: list<int>}
     */
    private static function placeTd(array $ranks, int $maxRank, array $byRank, array $sizes, Graph $graph, array &$placed): array
    {
        $centers = self::assignPositions($byRank, $sizes['layW'], self::GAP_X, $graph->edges, $ranks);

        $edgeBus = array_fill(0, count($graph->edges), 0);
        $busTracks = array_fill(0, $maxRank + 1, 0);

        for ($r = 0; $r < $maxRank; $r++) {
            $spans = self::busSpans($graph, $ranks, $centers, $r, false);

            if ($spans === []) {
                continue;
            }

            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($spans);

            foreach ($assigned as [$idx, $slot]) {
                $edgeBus[$idx] = $slot;
            }

            $busTracks[$r] = $count;
        }

        $rankH = array_map(
            static fn (array $row): int => $row === [] ? 3 : max(array_map(static fn (int $i): int => $sizes['boxH'][$i] + $sizes['extraH'][$i], $row)),
            $byRank,
        );
        $rankY = array_fill(0, $maxRank + 1, 0);

        for ($r = 1; $r <= $maxRank; $r++) {
            $rankY[$r] = $rankY[$r - 1] + $rankH[$r - 1] + max(self::GAP_Y, $busTracks[$r - 1] + 1);
        }

        $canvasH = $rankY[$maxRank] + $rankH[$maxRank];
        $bandEnd = [];

        for ($r = 0; $r <= $maxRank; $r++) {
            $bandEnd[] = $rankY[$r] + $rankH[$r];
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
            if ($e->from === $e->to || $e->label === null) {
                continue;
            }

            $lw = min(Measure::width($e->label), Labels::MAX_LABEL);
            $contentW = $ranks[$e->to] === $ranks[$e->from] + 1
                ? max($contentW, $placed[$e->to]->cx + 2 + $lw)
                : max($contentW, $diagramW + $lw + 1);
        }

        $edgeLane = array_fill(0, count($graph->edges), 0);
        $lanes = self::laneSpans($graph, $ranks, $placed, true);
        $canvasW = $contentW;
        $laneBase = 0;

        if ($lanes !== []) {
            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($lanes);

            foreach ($assigned as [$idx, $slot]) {
                $edgeLane[$idx] = $slot;
            }

            $canvasW = $contentW + 1 + $count;
            $laneBase = $contentW + 1;
        }

        return ['canvasW' => $canvasW, 'canvasH' => $canvasH, 'bandEnd' => $bandEnd, 'edgeBus' => $edgeBus, 'laneBase' => $laneBase, 'edgeLane' => $edgeLane];
    }

    /**
     * grok-mermaid's `placeLr()`: left-to-right placement, filling `placed` and returning the
     * route plan.
     *
     * @param list<int> $ranks
     * @param list<list<int>> $byRank
     * @param array{boxW: list<int>, boxH: list<int>, layW: list<int>, layH: list<int>, extraH: list<int>, selfLabelW: list<int>} $sizes
     * @param list<Placed> $placed
     * @return array{canvasW: int, canvasH: int, bandEnd: list<int>, edgeBus: list<int>, laneBase: int, edgeLane: list<int>}
     */
    private static function placeLr(array $ranks, int $maxRank, array $byRank, array $sizes, Graph $graph, array &$placed): array
    {
        $colW = array_map(
            static fn (array $row): int => $row === [] ? 0 : max(array_map(static fn (int $i): int => $sizes['boxW'][$i], $row)),
            $byRank,
        );

        // Left-to-right edge labels sit in the gap between columns, so the gap has to be wide
        // enough for the widest of them.
        $labelWidths = [];

        foreach ($graph->edges as $e) {
            if (($e->from === $e->to || $ranks[$e->to] === $ranks[$e->from] + 1) && $e->label !== null) {
                $labelWidths[] = min(Measure::width($e->label), Labels::MAX_LABEL);
            }
        }

        $maxLabel = $labelWidths === [] ? 0 : max($labelWidths);
        $baseGap = max(self::GAP_X + 1, $maxLabel + 3);

        $centers = self::assignPositions($byRank, $sizes['layH'], 1, $graph->edges, $ranks);

        $edgeBus = array_fill(0, count($graph->edges), 0);
        $busTracks = array_fill(0, $maxRank + 1, 0);

        for ($r = 0; $r < $maxRank; $r++) {
            $spans = self::busSpans($graph, $ranks, $centers, $r, true);

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
            $rankX[$r] = $rankX[$r - 1] + $colW[$r - 1] + max($baseGap, $busTracks[$r - 1] + 1);
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

        $edgeLane = array_fill(0, count($graph->edges), 0);
        $lanes = self::laneSpans($graph, $ranks, $placed, false);
        $canvasH = $diagramH;
        $laneBase = 0;

        if ($lanes !== []) {
            ['assigned' => $assigned, 'count' => $count] = self::assignTracks($lanes);

            foreach ($assigned as [$idx, $slot]) {
                $edgeLane[$idx] = $slot;
            }

            $canvasH = $diagramH + 1 + $count;
            $laneBase = $diagramH + 1;
        }

        return ['canvasW' => $canvasW, 'canvasH' => $canvasH, 'bandEnd' => $bandEnd, 'edgeBus' => $edgeBus, 'laneBase' => $laneBase, 'edgeLane' => $edgeLane];
    }

    // -------------------------------------------------------------------- canvas

    /**
     * grok-mermaid's `layoutCanvas()`: a graph ranked, placed, drawn and routed onto a fresh
     * canvas. Null when the graph is empty or over the cell cap.
     *
     * @param list<array<string, mixed>> $extras one `NodeExtra` per node
     */
    public static function layoutCanvas(Graph $graph, array $extras): ?Canvas
    {
        $n = count($graph->nodes);

        if ($n === 0) {
            return null;
        }

        $ranks = self::computeRanks($graph);
        $maxRank = max([...$ranks, 0]);

        $byRank = array_fill(0, $maxRank + 1, []);

        foreach ($ranks as $idx => $rank) {
            $byRank[$rank][] = $idx;
        }

        self::orderRanks($byRank, $graph->edges, $ranks);

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

            if ($e->label !== null) {
                $selfLabelW[$e->from] = max($selfLabelW[$e->from], min(Measure::width($e->label), Labels::MAX_LABEL));
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

        $vertical = $graph->dir === 'down' || $graph->dir === 'up';
        $plan = $vertical
            ? self::placeTd($ranks, $maxRank, $byRank, $sizes, $graph, $placed)
            : self::placeLr($ranks, $maxRank, $byRank, $sizes, $graph, $placed);

        if ($plan['canvasW'] * $plan['canvasH'] > self::MAX_CANVAS_CELLS) {
            return null;
        }

        $canvas = new Canvas($plan['canvasW'], $plan['canvasH']);

        for ($idx = 0; $idx < $n; $idx++) {
            $extra = $extras[$idx];

            if ($extra['kind'] === 'frame') {
                self::drawFrame($canvas, $placed[$idx], $graph->nodes[$idx]->label, $extra['sub']);
            } elseif ($extra['kind'] === 'compartments') {
                self::drawClassBox($canvas, $placed[$idx], $extra['sections']);
            } else {
                self::drawBox($canvas, $placed[$idx], $wrapped[$idx], $graph->nodes[$idx]->shape);
            }
        }

        foreach ($graph->edges as $i => $edge) {
            $canvas->curStyle = $edge->line === 'dotted' ? Canvas::STY_DOT : ($edge->line === 'thick' ? Canvas::STY_THICK : Canvas::STY_SOLID);

            if ($edge->from === $edge->to) {
                self::routeSelf($canvas, $placed[$edge->from], $edge);

                continue;
            }

            $from = $placed[$edge->from];
            $to = $placed[$edge->to];
            $adjacent = $to->rank === $from->rank + 1;
            $bus = $plan['bandEnd'][$from->rank] + $plan['edgeBus'][$i];
            $lane = $plan['laneBase'] + $plan['edgeLane'][$i];

            if ($vertical) {
                if ($adjacent) {
                    self::routeForward($canvas, $from, $to, $edge, $bus);
                } else {
                    self::routeBack($canvas, $from, $to, $edge, $lane);
                }
            } elseif ($adjacent) {
                self::routeForwardLr($canvas, $from, $to, $edge, $bus);
            } else {
                self::routeBackLr($canvas, $from, $to, $edge, $lane);
            }
        }

        $canvas->finalizeMask();

        return $canvas;
    }

    /** grok-mermaid's `orient()`: the direction flip a finished canvas needs for `BT` / `RL`. */
    public static function orient(Canvas $canvas, Graph $graph): Canvas
    {
        if ($graph->dir === 'up') {
            $canvas->flipVertical();
        } elseif ($graph->dir === 'left') {
            $canvas->flipHorizontal();
        }

        return $canvas;
    }

    /** grok-mermaid's `layoutFlowchart()`: flowchart and state diagrams — plain boxes, no extra content. */
    public static function layoutFlowchart(Graph $graph): ?Canvas
    {
        $extras = array_map(static fn (): array => ['kind' => 'plain'], $graph->nodes);
        $canvas = self::layoutCanvas($graph, $extras);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    /**
     * grok-mermaid's `layoutClass()`: class and ER diagrams — boxes divided into title /
     * attribute / method rows.
     *
     * @param list<ClassInfo> $infos parallel to `graph->nodes`
     */
    public static function layoutClass(Graph $graph, array $infos): ?Canvas
    {
        $extras = [];

        foreach ($graph->nodes as $i => $node) {
            $title = [];

            if ($infos[$i]->annotation !== null) {
                $title[] = "«{$infos[$i]->annotation}»";
            }

            $title[] = self::displayGenerics($node->label);
            $extras[] = ['kind' => 'compartments', 'sections' => [$title, $infos[$i]->attrs, $infos[$i]->methods]];
        }

        $canvas = self::layoutCanvas($graph, $extras);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    /**
     * grok-mermaid's `displayGenerics()`: `~` pairs shown as `<` and `>`. Walked by byte, which
     * is safe for `~`: no UTF-8 multibyte sequence contains 0x7E.
     */
    private static function displayGenerics(string $s): string
    {
        $out = '';
        $open = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($c === '~') {
                $out .= $open ? '>' : '<';
                $open = !$open;
            } else {
                $out .= $c;
            }
        }

        return $out;
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
     * grok-mermaid's `layoutGrouped()`: a flowchart that uses `subgraph`. Each subgraph becomes a
     * framed box holding its own independently laid-out canvas. An edge is drawn in the innermost
     * scope containing both endpoints; one crossing a subgraph boundary attaches to the frame
     * instead of the node.
     */
    public static function layoutGrouped(Graph $graph): ?Canvas
    {
        // A node whose id matches a subgraph id stands in for that subgraph.
        $proxy = [];

        foreach ($graph->groups as $gi => $g) {
            if (array_key_exists($g->id, $graph->index)) {
                $proxy[$graph->index[$g->id]] = $gi;
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

        // Drop empty subgraphs, but keep any that an edge attaches to.
        $keep = array_fill(0, count($graph->groups), false);

        for ($gi = count($graph->groups) - 1; $gi >= 0; $gi--) {
            $hasNodes = ($directNodes[$gi] ?? []) !== [];
            $hasChildren = false;

            foreach ($graph->groups as $c => $g) {
                if ($g->parent === $gi && $keep[$c]) {
                    $hasChildren = true;

                    break;
                }
            }

            $keep[$gi] = $hasNodes || $hasChildren || $referenced[$gi];
        }

        $canvas = self::buildScope($graph, null, $scopeEdges, $directNodes, $keep);

        return $canvas === null ? null : self::orient($canvas, $graph);
    }

    /**
     * grok-mermaid's `buildScope()`: one scope's nodes and kept child subgraphs laid out as a
     * graph of their own, each child framed around its recursively built canvas.
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
                $nodes[] = new Node($graph->nodes[$i]->label, $graph->nodes[$i]->shape);
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
     * grok-mermaid's `drawBox()`: a box outline (rounded for `round` and `diamond`) with its
     * lines centred inside.
     *
     * @param list<string> $lines
     */
    public static function drawBox(Canvas $canvas, Placed $p, array $lines, string $shape): void
    {
        $x = $p->x;
        $y = $p->y;
        $w = $p->w;
        $h = $p->h;
        $right = $x + $w - 1;
        $bottom = $y + $h - 1;

        $rounded = $shape === 'round' || $shape === 'diamond';
        $canvas->set($x, $y, $rounded ? '╭' : '┌', 'border');
        $canvas->set($right, $y, $rounded ? '╮' : '┐', 'border');
        $canvas->set($x, $bottom, $rounded ? '╰' : '└', 'border');
        $canvas->set($right, $bottom, $rounded ? '╯' : '┘', 'border');

        // The perimeter is drawn as bits so edges can tee into it, but it is the box outline,
        // so it claims `border` rather than `edge`.
        for ($cx = $x + 1; $cx < $right; $cx++) {
            $canvas->addBits($cx, $y, Canvas::L | Canvas::R, 'border');
            $canvas->addBits($cx, $bottom, Canvas::L | Canvas::R, 'border');
        }

        for ($cy = $y + 1; $cy < $bottom; $cy++) {
            $canvas->addBits($x, $cy, Canvas::U | Canvas::D, 'border');
            $canvas->addBits($right, $cy, Canvas::U | Canvas::D, 'border');
        }

        for ($cy = $y; $cy <= $bottom; $cy++) {
            for ($cx = $x; $cx <= $right; $cx++) {
                $canvas->occupied[$canvas->idx($cx, $cy)] = 1;
            }
        }

        $inner = max(1, self::sat($w, 2 * self::PAD + 2));

        foreach ($lines as $li => $line) {
            $text = Labels::fitLabel($line, $inner);
            $textX = $x + 1 + self::PAD + self::half(self::sat($inner, Measure::width($text)));
            Canvas::drawText($canvas, $text, $textX, $y + 1 + $li, 'text');
        }
    }

    /**
     * grok-mermaid's `drawClassBox()`: a class or ER box, sections separated by horizontal
     * rules, title centred.
     *
     * @param list<list<string>> $sections
     */
    private static function drawClassBox(Canvas $canvas, Placed $p, array $sections): void
    {
        self::drawBox($canvas, $p, [], 'rect');
        $inner = max(1, self::sat($p->w, 2 * self::PAD + 2));
        $row = $p->y + 1;
        $first = true;

        foreach ($sections as $si => $section) {
            if ($section === []) {
                continue;
            }

            if (!$first) {
                $canvas->set($p->x, $row, '├', 'border');

                for ($x = $p->x + 1; $x < $p->x + $p->w - 1; $x++) {
                    $canvas->set($x, $row, '─', 'border');
                }

                $canvas->set($p->x + $p->w - 1, $row, '┤', 'border');
                $row++;
            }

            $first = false;

            foreach ($section as $line) {
                $text = Labels::fitLabel($line, $inner);
                $tx = $si === 0 ? $p->x + 1 + self::PAD + self::half(self::sat($inner, Measure::width($text))) : $p->x + 1 + self::PAD;
                Canvas::drawTextOverEdges($canvas, $text, $tx, $row, 'text');
                $row++;
            }
        }
    }

    /** grok-mermaid's `drawFrame()`: a subgraph frame — a titled box with a finished sub-canvas centred inside. */
    private static function drawFrame(Canvas $canvas, Placed $p, string $title, Canvas $sub): void
    {
        self::drawBox($canvas, $p, [], 'rect');
        $t = Labels::fitLabel($title, self::sat($p->w, 4));
        Canvas::drawTextOverEdges($canvas, " {$t} ", $p->x + 1, $p->y, 'text');
        $canvas->blit($sub, $p->x + 1 + self::half($p->w - 2 - $sub->w), $p->y + 1 + self::half($p->h - 2 - $sub->h));
    }

    // ------------------------------------------------------------------- routing

    /** grok-mermaid's `headGlyph()`: the glyph for an edge head, given the plain arrow for its direction. */
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

    /** grok-mermaid's `routeForward()`: adjacent ranks, top-down — drop, jog along the bus row, drop into the head. */
    private static function routeForward(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $bus): void
    {
        $tx = $to->cx;
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

        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, $headRow, $tx + 1);
        }
    }

    /** grok-mermaid's `routeSelf()`: a self-edge, a stub loop hanging below the box. */
    private static function routeSelf(Canvas $canvas, Placed $p, Edge $edge): void
    {
        $bottom = $p->y + $p->h - 1;
        $exitX = $p->cx + 1;
        $retX = $p->x + $p->w - 2;

        if ($retX <= $exitX || $bottom + 2 >= $canvas->h) {
            return;
        }

        [$v, $h, $bl, $br] = $edge->line === 'dotted'
            ? ['╎', '╌', '╰', '╯']
            : ($edge->line === 'thick' ? ['┃', '━', '┗', '┛'] : ['│', '─', '╰', '╯']);

        $canvas->junction($exitX, $bottom, Canvas::D);
        $canvas->set($exitX, $bottom + 1, $v, 'edge');
        $canvas->set($exitX, $bottom + 2, $bl, 'edge');

        for ($x = $exitX + 1; $x < $retX; $x++) {
            $canvas->set($x, $bottom + 2, $h, 'edge');
        }

        $canvas->set($retX, $bottom + 2, $br, 'edge');
        $canvas->set($retX, $bottom + 1, self::headGlyph($edge->headTo, '▲'), 'edge');

        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, $bottom + 1, $p->x + $p->w + 1);
        }
    }

    /** grok-mermaid's `routeBack()`: skip or back edge, top-down — out the right side, up a lane, back in. */
    private static function routeBack(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $laneX): void
    {
        $sx = $from->x + $from->w - 1;
        $sy = $from->cy;
        $tx = $to->x + $to->w - 1;
        $tyc = $to->cy;

        $canvas->junction($sx, $sy, Canvas::R);
        $canvas->segH($sy, $sx, $laneX);
        $canvas->segV($laneX, $sy, $tyc);
        $canvas->segH($tyc, $tx + 1, $laneX);

        if ($edge->headTo === 'none') {
            $canvas->addBits($tx + 1, $tyc, Canvas::R);
        } else {
            $canvas->set($tx + 1, $tyc, self::headGlyph($edge->headTo, '◄'), 'edge');
        }

        if ($edge->headFrom !== 'none') {
            $canvas->set($sx, $sy, self::headGlyph($edge->headFrom, '◄'), 'edge');
        }

        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, self::sat($tyc, 1), self::sat($laneX, Measure::width($edge->label) + 1));
        }
    }

    /** grok-mermaid's `routeForwardLr()`: adjacent ranks, left-to-right — out the right side, jog on the bus column. */
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

        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, self::sat($ly, 1), $bus + 1);
        }
    }

    /** grok-mermaid's `routeBackLr()`: skip or back edge, left-to-right — down out the bottom, along a lane, back up. */
    private static function routeBackLr(Canvas $canvas, Placed $from, Placed $to, Edge $edge, int $laneY): void
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

        if ($edge->label !== null) {
            self::placeLabel($canvas, $edge->label, self::sat($laneY, 1), self::half($sx + $tx));
        }
    }

    /** grok-mermaid's `placeLabel()`: an edge label written, stopping at the first cell already occupied. */
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

                if ($canvas->ch[$i] !== ' ' || $canvas->mask[$i] !== 0 || $canvas->occupied[$i] !== 0) {
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
