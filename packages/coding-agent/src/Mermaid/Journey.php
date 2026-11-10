<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * A user `journey` as a listing — one row a task, its score as stars out of five, the actors
 * beside it, sections as rules across:
 *
 *              My working day
 *     ─ Go to work ───────────────────
 *     Make tea     ★★★★★  Me
 *     Go upstairs  ★★★☆☆  Me
 *     Do work      ★☆☆☆☆  Me, Cat
 *     ─ Go home ──────────────────────
 *     Sit down     ★★★★★  Me
 *
 * A task is `name: score: actor, actor`, the actors optional; `title X` and `section X` as in
 * Mermaid. A score outside 0..5 is shown as Mermaid draws it, at the nearest end of the scale.
 *
 * pig's own, not part of the lovely-mermaid port: lovely-mermaid draws no journey (`Diagrams\JourneyDiagram` registers it).
 */
final class Journey
{
    public const int MAX_SCORE = 5;

    public ?string $title = null;

    /** @var list<array{kind: 'section', text: string}|array{kind: 'task', text: string, score: int, actors: list<string>}> */
    public array $items = [];

    public static function parse(string $src): ?self
    {
        $statements = Statements::statementsOf($src);

        if (Labels::asciiLower($statements[0] ?? '') !== 'journey') {
            return null;
        }

        $journey = new self();
        $tasks = 0;

        foreach (Rows::body($statements) as $st) {
            $title = Rows::titleOf($st);

            if ($title !== null) {
                $journey->title = $title;

                continue;
            }

            $section = Rows::sectionOf($st);

            if ($section !== null) {
                $journey->items[] = ['kind' => 'section', 'text' => $section];

                continue;
            }

            $parts = array_map(Labels::trim(...), explode(':', $st));

            if (count($parts) < 2 || count($parts) > 3 || preg_match('/^-?[0-9]+$/', $parts[1]) !== 1) {
                return null;
            }

            if (++$tasks > Graph::MAX_NODES) {
                return null;
            }

            $actors = $parts[2] ?? '';
            $journey->items[] = [
                'kind' => 'task',
                'text' => Labels::cleanLabel($parts[0]),
                'score' => min(self::MAX_SCORE, max(0, (int) $parts[1])),
                'actors' => array_values(array_filter(array_map(Labels::trim(...), explode(',', $actors)), static fn (string $a): bool => $a !== '')),
            ];
        }

        return $journey;
    }

    public function layout(): ?Canvas
    {
        $tasks = array_filter($this->items, static fn (array $it): bool => $it['kind'] === 'task');

        if ($tasks === []) {
            return null;
        }

        $labels = [];
        $labelW = 1;

        foreach ($this->items as $i => $it) {
            if ($it['kind'] === 'task') {
                $labels[$i] = Labels::fitLabel($it['text'], Labels::WRAP_WIDTH);
                $labelW = max($labelW, Measure::width($labels[$i]));
            }
        }

        $rows = [];
        $width = 0;

        foreach ($this->items as $i => $it) {
            if ($it['kind'] === 'section') {
                $rows[] = ['section', $it['text']];

                continue;
            }

            $row = [
                [$labels[$i], 'text'],
                ...Rows::gap($labelW - Measure::width($labels[$i]) + 2),
            ];

            if ($it['score'] > 0) {
                $row[] = [str_repeat('★', $it['score']), 'edge'];
            }

            if ($it['score'] < self::MAX_SCORE) {
                $row[] = [str_repeat('☆', self::MAX_SCORE - $it['score']), 'edgeLabel'];
            }

            if ($it['actors'] !== []) {
                $row[] = ['  ', 'none'];
                $row[] = [Labels::fitLabel(implode(', ', $it['actors']), Labels::WRAP_WIDTH * 2), 'edgeLabel'];
            }

            $rows[] = $row;
            $width = max($width, Rows::width($row));
        }

        foreach ($rows as $i => $row) {
            if ($row[0] === 'section') {
                $rows[$i] = Rows::divider($row[1], $width);
            }
        }

        if ($this->title !== null && $this->title !== '') {
            array_unshift($rows, Rows::title($this->title, $width));
        }

        return Rows::canvas($rows);
    }
}
