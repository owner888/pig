<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * A `timeline` drawn down the screen — periods on the left, their events to the right of one
 * spine, sections as rules across:
 *
 *            History of the web
 *     ─ Early days ─────────────────
 *     1991 ─┐ WWW
 *           │
 *     2004 ─┘ Web 2.0
 *             Social media
 *     ─ Modern ─────────────────────
 *     2023 ── LLMs everywhere
 *
 * A period is `period : event : event`, more events on lines starting with `:`; `title X` and
 * `section X` as in Mermaid.
 *
 * pig's own, not part of the grok-mermaid port: grok-mermaid draws no timeline.
 */
final class Timeline
{
    /** Events wrap to this many columns. */
    public const int EVENT_WIDTH = 40;

    public ?string $title = null;

    /** @var list<array{kind: 'section', text: string}|array{kind: 'period', text: string, events: list<string>}> */
    public array $items = [];

    public static function parse(string $src): ?self
    {
        $statements = Parse::statementsOf($src);

        if (Labels::asciiLower(Labels::words($statements[0] ?? '')[0] ?? '') !== 'timeline' || count(Labels::words($statements[0])) !== 1) {
            return null;
        }

        $timeline = new self();
        $periods = 0;

        foreach (Rows::body($statements) as $st) {
            $title = Rows::titleOf($st);

            if ($title !== null) {
                $timeline->title = $title;

                continue;
            }

            $section = Rows::sectionOf($st);

            if ($section !== null) {
                $timeline->items[] = ['kind' => 'section', 'text' => $section];

                continue;
            }

            $parts = array_map(Labels::cleanLabel(...), explode(':', $st));

            if ($parts[0] === '') {
                // `: event` continues the period above.
                $last = count($timeline->items) - 1;

                if ($last < 0 || $timeline->items[$last]['kind'] !== 'period') {
                    return null;
                }

                array_push($timeline->items[$last]['events'], ...self::events(array_slice($parts, 1)));

                continue;
            }

            if (++$periods > Graph::MAX_NODES) {
                return null;
            }

            $timeline->items[] = ['kind' => 'period', 'text' => $parts[0], 'events' => self::events(array_slice($parts, 1))];
        }

        return $timeline;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private static function events(array $parts): array
    {
        return array_values(array_filter($parts, static fn (string $e): bool => $e !== ''));
    }

    public function layout(): ?Canvas
    {
        $periods = array_filter($this->items, static fn (array $it): bool => $it['kind'] === 'period');

        if ($periods === []) {
            return null;
        }

        $labels = [];
        $labelW = 1;

        foreach ($this->items as $i => $it) {
            if ($it['kind'] === 'period') {
                $labels[$i] = Labels::fitLabel($it['text'], Labels::WRAP_WIDTH);
                $labelW = max($labelW, Measure::width($labels[$i]));
            }
        }

        $rows = [];
        $n = count($this->items);

        foreach ($this->items as $i => $it) {
            if ($it['kind'] === 'section') {
                $rows[] = ['section', $it['text']];

                continue;
            }

            $lines = [];

            foreach ($it['events'] as $event) {
                array_push($lines, ...Labels::wrapLabel($event, self::EVENT_WIDTH, Labels::MAX_LINES));
            }

            $above = $i > 0 && $this->items[$i - 1]['kind'] === 'period';
            $below = $i + 1 < $n && $this->items[$i + 1]['kind'] === 'period';
            $node = Canvas::maskChar(Canvas::L | ($above ? Canvas::U : 0) | (($below || count($lines) > 1) ? Canvas::D : 0));
            $pad = str_repeat(' ', $labelW - Measure::width($labels[$i]));

            $first = [[$pad . $labels[$i], 'text'], [' ─' . $node, 'edge']];

            if ($lines !== []) {
                $first[] = [' ' . $lines[0], 'text'];
            }

            $rows[] = $first;

            foreach (array_slice($lines, 1) as $k => $line) {
                $tail = $k + 2 === count($lines) && !$below;
                $rows[] = [...Rows::gap($labelW + 2), [$tail ? '╵' : '│', 'edge'], [' ' . $line, 'text']];
            }

            if ($below) {
                $rows[] = [...Rows::gap($labelW + 2), ['│', 'edge']];
            }
        }

        $width = 0;

        foreach ($rows as $row) {
            if ($row[0] !== 'section') {
                $width = max($width, Rows::width($row));
            }
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
