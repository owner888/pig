<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Canvas;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/gitgraph.ts`: `gitGraph` — commit lanes, drawn the way `git log
 * --graph` draws them: newest commit on top, one column per branch, connector rows where history
 * splits. Lenient: an unreadable statement is dropped and recorded in `warnings`.
 *
 * Connector rows go through the canvas direction bits, so a merge reaching across an active lane
 * crosses it with a `┼` instead of erasing it.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class GitGraph implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'gitgraph';
    }

    #[\Override]
    public function headers(): array
    {
        return ['gitgraph', 'gitgraph:'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $model = $this->parseGitGraph($src);

        if ($model === null) {
            return null;
        }

        ['branches' => $branches, 'commits' => $commits, 'forkAt' => $forkAt, 'warnings' => $warnings] = $model;
        $laneCount = count($branches);

        // The newest commit of each lane wears the branch name.
        $headOf = [];

        foreach ($commits as $i => $c) {
            $headOf[$c['lane']] = $i;
        }

        // Rows, top-down: commit rows interleaved with connector rows. `open` hangs a merged lane
        // off its merge commit, `close` returns a forked lane to its parent at its fork point.
        // Lanes never used (a branch with no commits that nothing merged) never open.
        $rows = [];
        $used = [];

        foreach ($branches as $lane => $_) {
            $isUsed = $lane === 0;

            foreach ($commits as $c) {
                if ($c['lane'] === $lane || $c['mergeFrom'] === $lane) {
                    $isUsed = true;
                }
            }

            $used[] = $isUsed;
        }

        for ($i = count($commits) - 1; $i >= 0; $i--) {
            $c = $commits[$i];
            $rows[] = ['kind' => 'commit', 'at' => $i];

            if ($c['mergeFrom'] !== null) {
                $rows[] = ['kind' => 'open', 'parent' => $c['lane'], 'lane' => $c['mergeFrom']];
            }

            // Close outer lanes first so an inner close still sees them as columns.
            for ($lane = $laneCount - 1; $lane > 0; $lane--) {
                if ($forkAt[$lane] === $i - 1 && $used[$lane]) {
                    $rows[] = ['kind' => 'close', 'parent' => $commits[$i - 1]['lane'], 'lane' => $lane];
                }
            }
        }

        $laneX = static fn (int $lane): int => $lane * 2;
        $graphW = $laneCount * 2;
        $labels = array_map(static function (array $row) use ($commits, $headOf, $branches): ?array {
            if ($row['kind'] !== 'commit') {
                return null;
            }

            $c = $commits[$row['at']];
            $parts = $c['id'] === '' ? [] : [[$c['id'], 'text']];

            if (($headOf[$c['lane']] ?? null) === $row['at']) {
                $parts[] = ["({$branches[$c['lane']]})", 'edgeLabel'];
            }

            if ($c['tag'] !== null) {
                $parts[] = ["[{$c['tag']}]", 'edgeLabel'];
            }

            if ($c['mergeFrom'] !== null) {
                $parts[] = ["⇐ {$branches[$c['mergeFrom']]}", 'edgeLabel'];
            }

            return $parts;
        }, $rows);
        $width = $graphW + max(1, ...array_map(static function (?array $parts): int {
            if ($parts === null) {
                return 0;
            }

            $w = 0;

            foreach ($parts as [$t]) {
                $w += Measure::width($t) + 1;
            }

            return $w - 1;
        }, $labels));
        $canvas = new Canvas($width, count($rows));

        // Walk top-down with the set of lanes currently drawn as columns: a lane joins at its
        // newest own commit or its `open` connector, and leaves at its `close`. Everything in the
        // set draws `│` through every other row.
        $live = [];

        foreach ($rows as $y => $row) {
            if ($row['kind'] === 'commit') {
                $c = $commits[$row['at']];
                $live[$c['lane']] = true;

                foreach (array_keys($live) as $lane) {
                    if ($lane !== $c['lane']) {
                        $canvas->addBits($laneX($lane), $y, Canvas::U | Canvas::D);
                    }
                }

                $canvas->set($laneX($c['lane']), $y, '●', 'edge');
                $x = $graphW;

                foreach ($labels[$y] ?? [] as [$text, $role]) {
                    Canvas::drawText($canvas, $text, $x, $y, $role);
                    $x += Measure::width($text) + 1;
                }

                continue;
            }

            // The parent keeps its column, the child lane hooks on toward it, and unrelated live
            // lanes cross the horizontal run as `┼` through the bit merge.
            $parent = $row['parent'];
            $lane = $row['lane'];
            $open = $row['kind'] === 'open';
            $rejoins = $open && isset($live[$lane]);

            if ($open) {
                $live[$lane] = true;
            }

            foreach (array_keys($live) as $l) {
                if ($l !== $parent && $l !== $lane) {
                    $canvas->addBits($laneX($l), $y, Canvas::U | Canvas::D);
                }
            }

            // An open hangs off the merge commit directly above; a close bends down into the
            // fork commit below, continuing up only if the parent already had a column here.
            if ($open) {
                $canvas->addBits($laneX($parent), $y, Canvas::U | Canvas::D);
            } else {
                $canvas->addBits($laneX($parent), $y, Canvas::D | (isset($live[$parent]) ? Canvas::U : 0));
            }

            $live[$parent] = true;
            $canvas->segH($y, $laneX($parent), $laneX($lane));
            $canvas->addBits($laneX($lane), $y, ($open ? Canvas::D : Canvas::U) | ($rejoins ? Canvas::U : 0));

            if (!$open) {
                unset($live[$lane]);
            }
        }

        $canvas->finalizeMask();

        return ['canvas' => $canvas, 'warnings' => $warnings, 'classDefs' => []];
    }

    /** @return array{branches: list<string>, commits: list<array{lane: int, id: string, tag: ?string, mergeFrom: ?int}>, forkAt: list<?int>, warnings: list<string>}|null */
    public function parseGitGraph(string $src): ?array
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $branches = ['main'];
        $forkAt = [null];
        $commits = [];
        $warnings = [];
        // Newest commit index per lane — the fork point for branches cut from it.
        $heads = [null];
        $cur = 0;
        $auto = 0;
        $truncated = false;

        foreach (array_slice($statements, 1) as $st) {
            if (count($commits) >= Graph::MAX_EDGES) {
                $truncated = true;

                break;
            }

            $word = Labels::words($st)[0] ?? null;
            $first = $word === null ? '' : mb_strtolower($word);
            $rest = Labels::trim(substr($st, $word === null ? 0 : strlen($word)));

            if ($first === 'commit') {
                $attrs = self::commitAttrs($rest);
                $heads[$cur] = count($commits);
                $commits[] = ['lane' => $cur, 'id' => $attrs['id'] ?? 'c' . $auto++, 'tag' => $attrs['tag'], 'mergeFrom' => null];
            } elseif ($first === 'branch') {
                $name = self::nameToken($rest)['name'];
                // The fork point is the current branch's head, not the newest commit.
                $fork = $heads[$cur] ?? $forkAt[$cur];

                if ($name === null || in_array($name, $branches, true) || $fork === null) {
                    $warnings[] = "dropped, unreadable statement: \"{$st}\"";

                    continue;
                }

                $branches[] = $name;
                $forkAt[] = $fork;
                $heads[] = null;
                $cur = count($branches) - 1;
            } elseif ($first === 'checkout' || $first === 'switch') {
                $lane = array_search(self::nameToken($rest)['name'] ?? '', $branches, true);

                if ($lane === false) {
                    $warnings[] = "dropped, unreadable statement: \"{$st}\"";

                    continue;
                }

                $cur = $lane;
            } elseif ($first === 'merge') {
                ['name' => $name, 'after' => $after] = self::nameToken($rest);
                $lane = array_search($name ?? '', $branches, true);

                if ($lane === false || $lane === $cur) {
                    $warnings[] = "dropped, unreadable statement: \"{$st}\"";

                    continue;
                }

                // An unnamed merge shows no id — the `⇐ branch` marker already says what it is,
                // and an invented id would collide with authored ones.
                $attrs = self::commitAttrs($after);
                $heads[$cur] = count($commits);
                $commits[] = ['lane' => $cur, 'id' => $attrs['id'] ?? '', 'tag' => $attrs['tag'], 'mergeFrom' => $lane];
            } elseif ($first === 'cherry-pick') {
                $attrs = self::commitAttrs($rest);
                $heads[$cur] = count($commits);
                $commits[] = [
                    'lane' => $cur,
                    'id' => $attrs['id'] === null ? 'c' . $auto++ : "⟲ {$attrs['id']}",
                    'tag' => $attrs['tag'],
                    'mergeFrom' => null,
                ];
            } else {
                $warnings[] = "dropped, unreadable statement: \"{$st}\"";
            }
        }

        if ($truncated) {
            $warnings[] = 'diagram truncated: commit cap (' . Graph::MAX_EDGES . ') reached';
        }

        return $commits === [] ? null : ['branches' => $branches, 'commits' => $commits, 'forkAt' => $forkAt, 'warnings' => $warnings];
    }

    /**
     * The first branch-name token; quotes let a name carry spaces or keywords.
     *
     * @return array{name: ?string, after: string}
     */
    private static function nameToken(string $rest): array
    {
        if (str_starts_with($rest, '"')) {
            $close = strpos($rest, '"', 1);

            if ($close !== false) {
                return ['name' => substr($rest, 1, $close - 1), 'after' => substr($rest, $close + 1)];
            }
        }

        $w = Labels::words($rest)[0] ?? null;

        return ['name' => $w, 'after' => $w === null ? $rest : substr($rest, strlen($w))];
    }

    /**
     * `id: "x" tag: "v1" …` key/value pairs trailing a commit or merge.
     *
     * @return array{id: ?string, tag: ?string}
     */
    private static function commitAttrs(string $rest): array
    {
        $out = ['id' => null, 'tag' => null];
        preg_match_all('/(id|tag)' . Labels::SPACE . '*:' . Labels::SPACE . '*"([^"]*)"/iu', $rest, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $out[strtolower($m[1])] = Labels::fitLabel(Labels::cleanLabel($m[2]), Labels::MAX_LABEL);
        }

        return $out;
    }
}
