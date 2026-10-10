<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `layout-seq.ts`: sequence diagram layout.
 *
 * Participants get one column each, with lifelines running the full height and a box repeated at
 * top and bottom. Column gaps are solved from the widest thing that has to fit between any two
 * columns — a message label, a note, a self-message stub — then items stack down the canvas in
 * source order. Items and note anchors are the arrays `Sequence` documents.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class SequenceLayout
{
    private const int PAD = 1;

    /** Minimum columns between adjacent lifelines. */
    private const int SEQ_GAP = 5;

    private const int MAX_CANVAS_CELLS = 1 << 21;

    private static function sat(int $a, int $b): int
    {
        return max(0, $a - $b);
    }

    private static function half(int $n): int
    {
        return (int) floor($n / 2);
    }

    private static function ceilHalf(int $n): int
    {
        return (int) ceil($n / 2);
    }

    /**
     * grok-mermaid's `noteGeometry()`: where a note box sits, given the lifeline positions.
     *
     * @param list<int> $xs
     * @param array<string, mixed> $anchor
     * @return array{x: int, w: int}
     */
    private static function noteGeometry(array $xs, array $anchor, int $textW): array
    {
        if ($anchor['kind'] === 'over') {
            $center = self::half($xs[$anchor['from']] + $xs[$anchor['to']]);
            $w = max($xs[$anchor['to']] - $xs[$anchor['from']] + 5, $textW + 2 * self::PAD + 2);

            return ['x' => self::sat($center, self::half($w)), 'w' => $w];
        }

        $w = $textW + 2 * self::PAD + 2;

        if ($anchor['kind'] === 'left') {
            return ['x' => self::sat($xs[$anchor['at']], 2 + $w - 1), 'w' => $w];
        }

        return ['x' => $xs[$anchor['at']] + 2, 'w' => $w];
    }

    private static function itemTextW(?string $text): int
    {
        return $text === null ? 0 : Measure::width($text);
    }

    /** grok-mermaid's `layoutSequence()`: a sequence diagram drawn, or null over the cell cap. */
    public static function layoutSequence(Sequence $seq): ?Canvas
    {
        $n = count($seq->labels);
        $labels = array_map(static fn (string $l): string => Labels::fitLabel($l, Labels::WRAP_WIDTH), $seq->labels);
        $boxW = array_map(static fn (string $l): int => max(1, Measure::width($l)) + 2 * self::PAD + 2, $labels);
        $boxH = 3;

        $gaps = [];

        for ($i = 0; $i < self::sat($n, 1); $i++) {
            $gaps[] = max(self::SEQ_GAP, self::ceilHalf($boxW[$i]) + self::ceilHalf($boxW[$i + 1]) + 1);
        }

        // Each requirement is "columns l..r together need at least `need` cells".
        $reqs = [];

        foreach ($seq->items as $item) {
            if ($item['kind'] === 'message') {
                $tw = self::itemTextW($item['text']);

                if ($item['from'] !== $item['to']) {
                    $reqs[] = [min($item['from'], $item['to']), max($item['from'], $item['to']), max($tw + 2, 4)];
                } elseif ($item['from'] + 1 < $n) {
                    $reqs[] = [$item['from'], $item['from'] + 1, 5 + $tw + 2];
                }
            } elseif ($item['kind'] === 'note') {
                $tw = Measure::width($item['text']);
                $a = $item['anchor'];

                if ($a['kind'] === 'over' && $a['from'] < $a['to']) {
                    $reqs[] = [$a['from'], $a['to'], self::sat($tw, 1)];
                } elseif ($a['kind'] === 'over') {
                    $need = self::ceilHalf($tw + 4) + 2;

                    if ($a['from'] > 0) {
                        $reqs[] = [$a['from'] - 1, $a['from'], $need];
                    }

                    if ($a['from'] + 1 < $n) {
                        $reqs[] = [$a['from'], $a['from'] + 1, $need];
                    }
                } elseif ($a['kind'] === 'left' && $a['at'] > 0) {
                    $reqs[] = [$a['at'] - 1, $a['at'], $tw + 7];
                } elseif ($a['kind'] === 'right' && $a['at'] + 1 < $n) {
                    $reqs[] = [$a['at'], $a['at'] + 1, $tw + 7];
                }
            }
        }

        // Narrowest spans first, so a wide requirement absorbs what they already gave.
        usort($reqs, static fn (array $a, array $b): int => ($a[1] - $a[0]) <=> ($b[1] - $b[0]));

        foreach ($reqs as [$l, $r, $need]) {
            $cur = 0;

            for ($i = $l; $i < $r; $i++) {
                $cur += $gaps[$i];
            }

            if ($cur < $need) {
                $gaps[$r - 1] += $need - $cur;
            }
        }

        $xs = [self::half($boxW[0])];

        for ($i = 1; $i < $n; $i++) {
            $xs[$i] = $xs[$i - 1] + $gaps[$i - 1];
        }

        $canvasW = $xs[$n - 1] + self::ceilHalf($boxW[$n - 1]) + 1;

        foreach ($seq->items as $item) {
            if ($item['kind'] === 'message' && $item['from'] === $item['to']) {
                $canvasW = max($canvasW, $xs[$item['from']] + 5 + self::itemTextW($item['text']) + 1);
            } elseif ($item['kind'] === 'note') {
                $g = self::noteGeometry($xs, $item['anchor'], Measure::width($item['text']));
                $canvasW = max($canvasW, $g['x'] + $g['w'] + 1);
            } elseif ($item['kind'] === 'divider') {
                $canvasW = max($canvasW, Measure::width($item['text']) + 4);
            }
        }

        $rows = [];
        $y = $boxH + 1;

        foreach ($seq->items as $item) {
            $rows[] = $y;
            $y += self::rowHeight($item);
        }

        $bottomTop = $y;
        $canvasH = $bottomTop + $boxH;

        if ($canvasW * $canvasH > self::MAX_CANVAS_CELLS) {
            return null;
        }

        $canvas = new Canvas($canvasW, $canvasH);

        for ($i = 0; $i < $n; $i++) {
            foreach ([0, $bottomTop] as $by) {
                Layout::drawBox($canvas, self::box(self::sat($xs[$i], self::half($boxW[$i])), $by, $boxW[$i], $boxH), [$labels[$i]], 'rect');
            }
        }

        foreach ($seq->items as $k => $item) {
            if ($item['kind'] !== 'note') {
                continue;
            }

            $g = self::noteGeometry($xs, $item['anchor'], Measure::width($item['text']));
            Layout::drawBox($canvas, self::box($g['x'], $rows[$k], $g['w'], 3), [$item['text']], 'rect');
        }

        foreach ($xs as $x) {
            $canvas->junction($x, $boxH - 1, Canvas::D);
            $canvas->segV($x, $boxH, $bottomTop - 1);
            $canvas->junction($x, $bottomTop, Canvas::U);
        }

        foreach ($seq->items as $k => $item) {
            $r = $rows[$k];

            if ($item['kind'] === 'message') {
                self::drawMessage($canvas, $item, $xs, $r);
            } elseif ($item['kind'] === 'divider') {
                self::drawDivider($canvas, $item['text'], $r, $canvasW);
            }
        }

        $canvas->finalizeMask();

        return $canvas;
    }

    /**
     * grok-mermaid's `rowHeight()`: the rows an item takes.
     *
     * @param array<string, mixed> $item
     */
    private static function rowHeight(array $item): int
    {
        if ($item['kind'] === 'note') {
            return 4;
        }

        if ($item['kind'] === 'divider') {
            return 2;
        }

        if ($item['from'] === $item['to']) {
            return 4;
        }

        return $item['text'] !== null ? 3 : 2;
    }

    /** grok-mermaid's `box()`: geometry for a box drawn by position and size; ranks are irrelevant here. */
    private static function box(int $x, int $y, int $w, int $h): Placed
    {
        return new Placed($x, $y, $w, $h, $x + self::half($w), $y + 1, 0);
    }

    /**
     * grok-mermaid's `drawMessage()`: a message arrow between lifelines, or a self-message stub.
     *
     * @param array<string, mixed> $item a `message` item
     * @param list<int> $xs
     */
    private static function drawMessage(Canvas $canvas, array $item, array $xs, int $r): void
    {
        $lineCh = $item['dashed'] ? '╌' : '─';

        if ($item['from'] === $item['to']) {
            // A stub that leaves the lifeline and returns two rows down.
            $x = $xs[$item['from']];
            $canvas->junction($x, $r, Canvas::R);
            $canvas->set($x + 1, $r, $lineCh, 'edge');
            $canvas->set($x + 2, $r, $lineCh, 'edge');
            $canvas->set($x + 3, $r, '╮', 'edge');
            $canvas->set($x + 3, $r + 1, '│', 'edge');
            $canvas->set($x + 1, $r + 2, $item['head'] === 'cross' ? '×' : '◄', 'edge');
            $canvas->set($x + 2, $r + 2, $lineCh, 'edge');
            $canvas->set($x + 3, $r + 2, '╯', 'edge');

            if ($item['text'] !== null) {
                Canvas::drawTextOverEdges($canvas, $item['text'], $x + 5, $r + 1, 'text');
            }

            return;
        }

        $x0 = $xs[$item['from']];
        $x1 = $xs[$item['to']];
        $rightward = $x1 > $x0;
        // A labelled message writes its text on `r` and draws the arrow below it.
        $arrowRow = $item['text'] !== null ? $r + 1 : $r;
        $lo = min($x0, $x1);
        $hi = max($x0, $x1);

        $canvas->junction($x0, $arrowRow, $rightward ? Canvas::R : Canvas::L);

        for ($x = $lo + 1; $x < $hi; $x++) {
            $canvas->set($x, $arrowRow, $lineCh, 'edge');
        }

        $headCh = $item['head'] === 'cross' ? '×' : ($rightward ? '▶' : '◄');
        $canvas->set($rightward ? $x1 - 1 : $x1 + 1, $arrowRow, $headCh, 'edge');

        if ($item['text'] !== null) {
            $span = $hi - $lo - 1;
            $t = Labels::fitLabel($item['text'], max(1, $span));
            Canvas::drawTextOverEdges($canvas, $t, $lo + 1 + self::half(self::sat($span, Measure::width($t))), $r, 'text');
        }
    }

    /** grok-mermaid's `drawDivider()`: a full-width rule labelling a `loop` / `alt` / `opt` block boundary. */
    private static function drawDivider(Canvas $canvas, string $text, int $r, int $canvasW): void
    {
        for ($x = 0; $x < $canvasW; $x++) {
            $canvas->set($x, $r, '─', 'edge');
        }

        Canvas::drawTextOverEdges($canvas, ' ' . Labels::fitLabel($text, self::sat($canvasW, 4)) . ' ', 2, $r, 'edgeLabel');
    }
}
