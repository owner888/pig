<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * A `kanban` board: columns side by side, each a frame with its title over a rule and its cards
 * stacked below, every card a box of wrapped text with the `@{ … }` metadata as a muted line:
 *
 *     ┌──────────────────┐ ┌──────────────────┐
 *     │       Todo       │ │   In progress    │
 *     ├──────────────────┤ ├──────────────────┤
 *     │ ┌──────────────┐ │ │ ┌──────────────┐ │
 *     │ │ Write docs   │ │ │ │ Fix parser   │ │
 *     │ └──────────────┘ │ │ │ knsv · MC-1  │ │
 *     └──────────────────┘ │ └──────────────┘ │
 *                          └──────────────────┘
 *
 * Indentation is the structure, as in Mermaid: the least-indented lines under the header are
 * columns, anything deeper is a card in the column above. A node is `id[Label]`, `[Label]` or a
 * bare `id`, optionally followed by `@{ ticket: X, assigned: Y, priority: Z }`.
 *
 * pig's own, not part of the grok-mermaid port: grok-mermaid draws no kanban.
 */
final class Kanban
{
    /** Card text wraps to this many columns. */
    public const int CARD_WIDTH = 20;

    private const int PAD = 1;

    private const int MAX_CANVAS_CELLS = 1 << 21;

    /** @var list<array{title: string, cards: list<array{lines: list<string>, meta: ?string}>}> */
    public array $columns = [];

    public static function parse(string $src): ?self
    {
        $lines = Labels::srcLines($src);
        $header = Labels::trim($lines[0] ?? '');

        if (Labels::asciiLower($header) !== 'kanban') {
            return null;
        }

        $body = [];

        foreach (array_slice($lines, 1) as $line) {
            $trimmed = Labels::trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '%%')) {
                continue;
            }

            $first = Labels::asciiLower(Labels::words($trimmed)[0]);

            if (str_starts_with($first, 'acctitle') || str_starts_with($first, 'accdescr')) {
                continue;
            }

            $body[] = [self::indent($line), $trimmed];
        }

        if ($body === []) {
            return new self();
        }

        $columnIndent = min(array_column($body, 0));
        $kanban = new self();
        $cards = 0;

        foreach ($body as [$indent, $text]) {
            $node = self::node($text);

            if ($node === null) {
                return null;
            }

            if ($indent === $columnIndent) {
                if (count($kanban->columns) >= Graph::MAX_GROUPS) {
                    return null;
                }

                $kanban->columns[] = ['title' => $node['label'], 'cards' => []];

                continue;
            }

            if (++$cards > Graph::MAX_NODES) {
                return null;
            }

            $kanban->columns[count($kanban->columns) - 1]['cards'][] = [
                'lines' => Labels::wrapLabel($node['label'], self::CARD_WIDTH, Labels::MAX_LINES),
                'meta' => $node['meta'],
            ];
        }

        return $kanban;
    }

    /** Leading white space as columns, a tab counting four. */
    private static function indent(string $line): int
    {
        preg_match('/^[ \t]*/', $line, $m);

        return strlen(str_replace("\t", '    ', $m[0]));
    }

    /** @return array{label: string, meta: ?string}|null */
    private static function node(string $text): ?array
    {
        if (preg_match('/^([^\[\]@]*?)' . Labels::SPACE . '*(?:\[(.*)\])?' . Labels::SPACE . '*(?:@\{(.*)\})?$/su', $text, $m) !== 1) {
            return null;
        }

        $id = Labels::trim($m[1]);
        $label = ($m[2] ?? '') !== '' ? Labels::cleanLabel($m[2]) : $id;

        if ($label === '') {
            return null;
        }

        return ['label' => $label, 'meta' => self::meta($m[3] ?? '')];
    }

    /** `assigned`, `ticket` and `priority` out of `@{ … }`, joined with ` · `; null when none. */
    private static function meta(string $raw): ?string
    {
        if (Labels::trim($raw) === '') {
            return null;
        }

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)' . Labels::SPACE . '*:' . Labels::SPACE . '*(?:\'([^\']*)\'|"([^"]*)"|([^,]*))/u', $raw, $all, PREG_SET_ORDER);
        $fields = [];

        foreach ($all as $m) {
            $fields[Labels::asciiLower($m[1])] = Labels::trim(($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')));
        }

        $shown = array_filter([$fields['assigned'] ?? '', $fields['ticket'] ?? '', $fields['priority'] ?? ''], static fn (string $s): bool => $s !== '');

        return $shown === [] ? null : implode(' · ', $shown);
    }

    public function layout(): ?Canvas
    {
        if ($this->columns === []) {
            return null;
        }

        $geometry = [];
        $x = 0;
        $height = 0;

        foreach ($this->columns as $column) {
            $title = Labels::fitLabel($column['title'], Labels::WRAP_WIDTH);
            $cardText = 1;

            foreach ($column['cards'] as $card) {
                foreach ($card['lines'] as $line) {
                    $cardText = max($cardText, Measure::width($line));
                }

                if ($card['meta'] !== null) {
                    $cardText = max($cardText, Measure::width(Labels::fitLabel($card['meta'], self::CARD_WIDTH)));
                }
            }

            $cardW = $cardText + 2 * self::PAD + 2;
            $inner = max($cardW, Measure::width($title));
            $w = $inner + 2 * self::PAD + 2;
            // Top border, title, rule, the cards, bottom border.
            $h = 4;

            foreach ($column['cards'] as $card) {
                $h += count($card['lines']) + ($card['meta'] === null ? 0 : 1) + 2;
            }

            $geometry[] = ['x' => $x, 'w' => $w, 'h' => $h, 'inner' => $inner, 'cardW' => $cardW, 'cardText' => $cardText, 'title' => $title];
            $x += $w + 1;
            $height = max($height, $h);
        }

        $width = $x - 1;

        if ($width * $height > self::MAX_CANVAS_CELLS) {
            return null;
        }

        $canvas = new Canvas($width, $height);

        foreach ($this->columns as $i => $column) {
            $g = $geometry[$i];
            self::frame($canvas, $g['x'], 0, $g['w'], $g['h']);
            Canvas::drawText($canvas, $g['title'], $g['x'] + 1 + self::PAD + intdiv($g['inner'] - Measure::width($g['title']), 2), 1, 'title');
            $canvas->set($g['x'], 2, '├', 'border');
            $canvas->set($g['x'] + $g['w'] - 1, 2, '┤', 'border');

            for ($cx = $g['x'] + 1; $cx < $g['x'] + $g['w'] - 1; $cx++) {
                $canvas->set($cx, 2, '─', 'border');
            }

            $y = 3;
            $cardX = $g['x'] + 1 + self::PAD;

            foreach ($column['cards'] as $card) {
                $lines = $card['lines'];
                $cardH = count($lines) + ($card['meta'] === null ? 0 : 1) + 2;
                self::frame($canvas, $cardX, $y, $g['cardW'], $cardH);

                foreach ($lines as $k => $line) {
                    Canvas::drawText($canvas, $line, $cardX + 1 + self::PAD, $y + 1 + $k, 'text');
                }

                if ($card['meta'] !== null) {
                    Canvas::drawText($canvas, Labels::fitLabel($card['meta'], $g['cardText']), $cardX + 1 + self::PAD, $y + 1 + count($lines), 'edgeLabel');
                }

                $y += $cardH;
            }
        }

        return $canvas;
    }

    /** A plain rectangle of `border` cells; cells only, no edge bits, so frames nest. */
    private static function frame(Canvas $canvas, int $x, int $y, int $w, int $h): void
    {
        $right = $x + $w - 1;
        $bottom = $y + $h - 1;
        $canvas->set($x, $y, '┌', 'border');
        $canvas->set($right, $y, '┐', 'border');
        $canvas->set($x, $bottom, '└', 'border');
        $canvas->set($right, $bottom, '┘', 'border');

        for ($cx = $x + 1; $cx < $right; $cx++) {
            $canvas->set($cx, $y, '─', 'border');
            $canvas->set($cx, $bottom, '─', 'border');
        }

        for ($cy = $y + 1; $cy < $bottom; $cy++) {
            $canvas->set($x, $cy, '│', 'border');
            $canvas->set($right, $cy, '│', 'border');
        }
    }
}
