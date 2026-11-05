<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * A `pie` chart as a bar listing — one row a slice, its bar scaled to the largest, the share
 * beside it and, under `showData`, the value:
 *
 *                 Pets
 *     Dogs ████████████████████████  79.4%  386
 *     Cats █████                     17.5%   85
 *     Rats █                          3.1%   15
 *
 * The header is `pie`, `pie showData`, `pie title X` or both; a `title X` line works too, and
 * each slice is `"Label" : value`.
 *
 * pig's own, not part of the grok-mermaid port: grok-mermaid draws no pie.
 */
final class Pie
{
    public const int BAR_WIDTH = 24;

    /** @var list<array{label: string, value: float}> */
    public array $slices = [];

    public ?string $title = null;

    public bool $showData = false;

    public static function parse(string $src): ?self
    {
        $statements = Parse::statementsOf($src);
        $header = $statements[0] ?? '';

        if (preg_match('/^pie(?:' . Labels::SPACE . '+(showData))?(?:' . Labels::SPACE . '+title(?:' . Labels::SPACE . '+(.*))?)?$/iu', $header, $m) !== 1) {
            return null;
        }

        $pie = new self();
        $pie->showData = ($m[1] ?? '') !== '';

        if (($m[2] ?? '') !== '') {
            $pie->title = Labels::cleanLabel($m[2]);
        }

        foreach (Rows::body($statements) as $st) {
            $title = Rows::titleOf($st);

            if ($title !== null) {
                $pie->title = $title;

                continue;
            }

            if (preg_match('/^(?:"([^"]*)"|([^":]+?))' . Labels::SPACE . '*:' . Labels::SPACE . '*([0-9]+(?:\.[0-9]+)?)$/u', $st, $m) !== 1) {
                return null;
            }

            if (count($pie->slices) >= Graph::MAX_NODES) {
                return null;
            }

            $pie->slices[] = ['label' => Labels::cleanLabel($m[1] !== '' ? $m[1] : $m[2]), 'value' => (float) $m[3]];
        }

        return $pie;
    }

    public function layout(): ?Canvas
    {
        if ($this->slices === []) {
            return null;
        }

        $total = array_sum(array_column($this->slices, 'value'));
        $largest = max(array_column($this->slices, 'value'));

        if ($total <= 0) {
            return null;
        }

        $labels = array_map(static fn (array $s): string => Labels::fitLabel($s['label'], Labels::WRAP_WIDTH), $this->slices);
        $labelW = max(array_map(Measure::width(...), $labels));
        $shares = array_map(static fn (array $s): string => sprintf('%.1f%%', $s['value'] * 100 / $total), $this->slices);
        $shareW = max(array_map('strlen', $shares));
        $values = array_map(static fn (array $s): string => self::number($s['value']), $this->slices);
        $valueW = max(array_map(Measure::width(...), $values));

        $rows = [];

        foreach ($this->slices as $i => $slice) {
            $filled = $slice['value'] <= 0 ? 0 : max(1, (int) round($slice['value'] / $largest * self::BAR_WIDTH));
            $row = [
                [$labels[$i], 'text'],
                ...Rows::gap($labelW - Measure::width($labels[$i]) + 1),
            ];

            if ($filled > 0) {
                $row[] = [str_repeat('█', $filled), 'edge'];
            }

            $row[] = [str_repeat(' ', self::BAR_WIDTH - $filled + 2), 'none'];
            $row[] = [str_pad($shares[$i], $shareW, ' ', STR_PAD_LEFT), 'edgeLabel'];

            if ($this->showData) {
                $row[] = [str_repeat(' ', 2 + $valueW - Measure::width($values[$i])), 'none'];
                $row[] = [$values[$i], 'text'];
            }

            $rows[] = $row;
        }

        if ($this->title !== null && $this->title !== '') {
            array_unshift($rows, Rows::title($this->title, Rows::width($rows[0])));
        }

        return Rows::canvas($rows);
    }

    /** A value as written by the shortest round trip: `386`, `2.5`. */
    private static function number(float $v): string
    {
        return $v === floor($v) && abs($v) < 1e15 ? (string) (int) $v : rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
