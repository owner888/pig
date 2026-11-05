<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * A `gitGraph` drawn down the screen as `git log --graph` does: one lane a branch, one row a
 * commit, forks and merges as corners between lanes, the id or message to the right:
 *
 *     ┬  main
 *     ●  c1
 *     ├──┐  feature
 *     │  ●  c2
 *     ●  │  c3
 *     ◎──┘  merge feature  v1.0
 *     ●  c5
 *
 * `●` is a commit, `◆` a `HIGHLIGHT` one, `○` a `REVERSE` one and `◎` a merge. Statements are
 * Mermaid's: `commit [id: "x"] [msg: "m"] [tag: "t"] [type: T]`, `branch name [order: n]`,
 * `checkout name` / `switch name`, `merge name [id:] [tag:] [type:]` and `cherry-pick id: "x"
 * [parent: "y"] [tag:]`. The `LR:` / `TB:` / `BT:` orientation is read and ignored; a terminal
 * reads a history best top to bottom.
 *
 * pig's own, not part of the grok-mermaid port: grok-mermaid draws no git graph.
 */
final class GitGraph
{
    /** Columns per lane. */
    private const int LANE_W = 3;

    private const int MAX_CANVAS_CELLS = 1 << 21;

    private const array NODE = ['NORMAL' => '●', 'HIGHLIGHT' => '◆', 'REVERSE' => '○'];

    /** @var list<string> branch names, lane by lane */
    public array $lanes = ['main'];

    /**
     * @var list<array{kind: 'start', lane: int}
     *     |array{kind: 'fork', from: int, lane: int}
     *     |array{kind: 'commit', lane: int, text: string, tag: ?string, type: string}
     *     |array{kind: 'merge', lane: int, from: int, text: string, tag: ?string}>
     */
    public array $rows = [];

    public static function parse(string $src): ?self
    {
        $statements = Parse::statementsOf($src);

        if (preg_match('/^gitGraph(?:' . Labels::SPACE . '*:|' . Labels::SPACE . '+(?:LR|TB|BT)' . Labels::SPACE . '*:)?$/iu', $statements[0] ?? '') !== 1) {
            return null;
        }

        $graph = new self();
        $graph->rows[] = ['kind' => 'start', 'lane' => 0];
        $current = 0;

        foreach (Rows::body($statements) as $st) {
            $words = Labels::words($st);
            $verb = Labels::asciiLower($words[0]);
            $rest = Labels::trim(substr($st, strlen($words[0])));

            if (count($graph->rows) >= Graph::MAX_EDGES) {
                return null;
            }

            switch ($verb) {
                case 'commit':
                    $o = self::options($rest);

                    if ($o === null) {
                        return null;
                    }

                    $graph->rows[] = ['kind' => 'commit', 'lane' => $current, 'text' => $o['msg'] ?? $o['id'] ?? '', 'tag' => $o['tag'] ?? null, 'type' => self::type($o)];

                    break;
                case 'cherry-pick':
                    $o = self::options($rest);

                    if ($o === null || !isset($o['id'])) {
                        return null;
                    }

                    $graph->rows[] = ['kind' => 'commit', 'lane' => $current, 'text' => "cherry-pick {$o['id']}", 'tag' => $o['tag'] ?? null, 'type' => 'NORMAL'];

                    break;
                case 'branch':
                    $named = self::name($rest);

                    if ($named === null || in_array($named['name'], $graph->lanes, true) || count($graph->lanes) >= Graph::MAX_NODES) {
                        return null;
                    }

                    $graph->lanes[] = $named['name'];
                    $graph->rows[] = ['kind' => 'fork', 'from' => $current, 'lane' => count($graph->lanes) - 1];
                    $current = count($graph->lanes) - 1;

                    break;
                case 'checkout':
                case 'switch':
                    $named = self::name($rest);
                    $lane = $named === null ? false : array_search($named['name'], $graph->lanes, true);

                    if ($lane === false) {
                        return null;
                    }

                    $current = $lane;

                    break;
                case 'merge':
                    $named = self::name($rest);
                    $lane = $named === null ? false : array_search($named['name'], $graph->lanes, true);

                    if ($lane === false || $lane === $current) {
                        return null;
                    }

                    $o = self::options($named['rest']);

                    if ($o === null) {
                        return null;
                    }

                    $graph->rows[] = ['kind' => 'merge', 'lane' => $current, 'from' => $lane, 'text' => $o['id'] ?? "merge {$named['name']}", 'tag' => $o['tag'] ?? null];

                    break;
                default:
                    return null;
            }
        }

        return $graph;
    }

    /**
     * A branch name, quoted or bare, and what follows it.
     *
     * @return array{name: string, rest: string}|null
     */
    private static function name(string $s): ?array
    {
        if (preg_match('/^(?:"([^"]*)"|([^"' . substr(Labels::SPACE, 1, -1) . ']+))' . Labels::SPACE . '*(.*)$/su', $s, $m) !== 1) {
            return null;
        }

        $name = $m[1] !== '' ? $m[1] : $m[2];

        return $name === '' ? null : ['name' => $name, 'rest' => $m[3]];
    }

    /**
     * `key: value` options, the value quoted or one word; anything else refuses the statement.
     *
     * @return array<string, string>|null
     */
    private static function options(string $s): ?array
    {
        $out = [];
        $pattern = '/^([A-Za-z]+)' . Labels::SPACE . '*:' . Labels::SPACE . '*(?:"([^"]*)"|([^"' . substr(Labels::SPACE, 1, -1) . ']+))' . Labels::SPACE . '*/u';

        while ($s !== '') {
            if (preg_match($pattern, $s, $m) !== 1) {
                return null;
            }

            $out[Labels::asciiLower($m[1])] = Labels::cleanLabel($m[2] !== '' ? $m[2] : $m[3]);
            $s = substr($s, strlen($m[0]));
        }

        return $out;
    }

    /** @param array<string, string> $o */
    private static function type(array $o): string
    {
        $type = Labels::asciiUpper($o['type'] ?? 'NORMAL');

        return isset(self::NODE[$type]) ? $type : 'NORMAL';
    }

    /** The graph drawn, or null for a history with nothing in it yet. */
    public function layout(): ?Canvas
    {
        if (count($this->rows) < 2) {
            return null;
        }

        $n = count($this->lanes);
        $textX = self::LANE_W * $n;
        $texts = [];
        $width = $textX;

        foreach ($this->rows as $i => $row) {
            $texts[$i] = self::text($row);
            $width = max($width, $textX + Rows::width($texts[$i]));
        }

        $height = count($this->rows);

        if ($width * $height > self::MAX_CANVAS_CELLS) {
            return null;
        }

        // A lane runs from the row that opens it to the last row that touches it.
        $first = array_fill(0, $n, null);
        $last = array_fill(0, $n, 0);

        foreach ($this->rows as $i => $row) {
            foreach (array_unique([$row['lane'], $row['from'] ?? $row['lane']]) as $lane) {
                $first[$lane] ??= $i;
                $last[$lane] = $i;
            }
        }

        $canvas = new Canvas($width, $height);

        for ($lane = 0; $lane < $n; $lane++) {
            if ($first[$lane] !== null) {
                $canvas->segV(self::x($lane), $first[$lane], $last[$lane]);
            }
        }

        foreach ($this->rows as $i => $row) {
            if ($row['kind'] === 'fork' || $row['kind'] === 'merge') {
                $canvas->segH($i, self::x($row['from']), self::x($row['lane']));
            }
        }

        $canvas->finalizeMask();

        foreach ($this->rows as $i => $row) {
            $glyph = match ($row['kind']) {
                'start' => '┬',
                'fork' => null,
                'commit' => self::NODE[$row['type']],
                'merge' => '◎',
            };

            if ($glyph !== null) {
                $canvas->set(self::x($row['lane']), $i, $glyph, 'edge');
            }

            $x = $textX;

            foreach ($texts[$i] as [$text, $cls]) {
                Canvas::drawText($canvas, $text, $x, $i, $cls);
                $x += Measure::width($text);
            }
        }

        return $canvas;
    }

    private static function x(int $lane): int
    {
        return self::LANE_W * $lane;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{0: string, 1: string}>
     */
    private function text(array $row): array
    {
        if ($row['kind'] === 'start' || $row['kind'] === 'fork') {
            return [[Labels::fitLabel($this->lanes[$row['lane']], Labels::WRAP_WIDTH), 'title']];
        }

        $out = [];

        if ($row['text'] !== '') {
            $out[] = [Labels::fitLabel($row['text'], Labels::WRAP_WIDTH * 2), 'text'];
        }

        if ($row['tag'] !== null && $row['tag'] !== '') {
            $out[] = [($out === [] ? '' : '  ') . Labels::fitLabel($row['tag'], Labels::WRAP_WIDTH), 'edgeLabel'];
        }

        return $out;
    }
}
