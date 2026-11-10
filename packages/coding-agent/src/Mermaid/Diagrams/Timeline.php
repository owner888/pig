<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Canvas;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/timeline.ts`: `timeline` — periods and their events as a vertical
 * list, one row per event, the period named on its first row. Lenient: an unreadable statement is
 * dropped and recorded in `warnings`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class Timeline implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'timeline';
    }

    #[\Override]
    public function headers(): array
    {
        return ['timeline'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $parsed = self::parseTimeline($src);

        if ($parsed === null) {
            return null;
        }

        ['title' => $title, 'rows' => $rows, 'warnings' => $warnings] = $parsed;

        $periodW = max(array_map(static fn (array $r): int => $r['section'] ? 0 : Measure::width($r['period']), $rows));
        $top = $title === null ? 0 : 1;
        $width = max(
            $title === null ? 0 : Measure::width($title) + 6,
            ...array_map(static fn (array $r): int => $r['section'] ? Measure::width($r['event']) : $periodW + 3 + Measure::width($r['event']), $rows),
        );
        $canvas = new Canvas($width, $top + count($rows));

        if ($title !== null) {
            $t = " {$title} ";
            $x = max(0, (int) floor(($width - Measure::width($t) - 4) / 2));
            Canvas::drawText($canvas, '──', $x, 0, 'edge');
            Canvas::drawText($canvas, $t, $x + 2, 0, 'title');
            Canvas::drawText($canvas, '──', $x + 2 + Measure::width($t), 0, 'edge');
        }

        foreach ($rows as $i => $row) {
            $y = $top + $i;

            if ($row['section']) {
                Canvas::drawText($canvas, $row['event'], 0, $y, 'title');

                continue;
            }

            Canvas::drawText($canvas, $row['period'], 0, $y, 'text');

            if ($row['event'] === '') {
                continue;
            }

            Canvas::drawText($canvas, '─', $periodW + 1, $y, 'edge');
            Canvas::drawText($canvas, $row['event'], $periodW + 3, $y, 'edgeLabel');
        }

        return ['canvas' => $canvas, 'warnings' => $warnings, 'classDefs' => []];
    }

    /** @return array{title: ?string, rows: list<array{period: string, event: string, section: bool}>, warnings: list<string>}|null */
    private static function parseTimeline(string $src): ?array
    {
        $statements = Statements::statementsOf($src);

        if (Statements::headerKind($statements) !== 'timeline') {
            return null;
        }

        $title = null;
        $rows = [];
        $warnings = [];
        $truncated = false;
        $lastPeriod = false;
        $row = static fn (string $period, string $event, bool $section = false): array => ['period' => $period, 'event' => $event, 'section' => $section];

        foreach (array_slice($statements, 1) as $st) {
            if (count($rows) >= Graph::MAX_EDGES) {
                $truncated = true;

                break;
            }

            $first = Labels::words($st)[0] ?? null;
            $first = $first === null ? null : mb_strtolower($first);

            if ($first === 'title') {
                $title = Statements::nonEmpty(Labels::trim(substr($st, (int) stripos($st, 'title') + 5)));

                continue;
            }

            if ($first === 'section') {
                $name = Labels::trim(substr($st, (int) stripos($st, 'section') + 7));
                $rows[] = $row('', self::clean($name), true);
                $lastPeriod = false;

                continue;
            }

            // `period : event : event`; a statement of only `: event`s continues the previous
            // period (the `;`-split form of mermaid's multi-line events).
            $parts = array_map(self::clean(...), explode(':', $st));
            $period = array_shift($parts) ?? '';

            if ($period === '' && $parts !== [] && $lastPeriod) {
                foreach ($parts as $event) {
                    $rows[] = $row('', $event);
                }

                continue;
            }

            // A bare period renders event-less, as mermaid draws it.
            if ($period !== '' && $parts === []) {
                $rows[] = $row($period, '');
                $lastPeriod = true;

                continue;
            }

            if ($period === '' || in_array('', $parts, true)) {
                $warnings[] = "dropped, unreadable statement: \"{$st}\"";

                continue;
            }

            foreach ($parts as $i => $event) {
                $rows[] = $row($i === 0 ? $period : '', $event);
            }

            $lastPeriod = true;
        }

        if ($truncated) {
            $warnings[] = 'diagram truncated: event cap (' . Graph::MAX_EDGES . ') reached';
        }

        return $rows === [] ? null : ['title' => $title, 'rows' => $rows, 'warnings' => $warnings];
    }

    private static function clean(string $s): string
    {
        return Labels::fitLabel(Labels::decodeHtmlEntities(Labels::trim($s)), Labels::MAX_LABEL);
    }
}
