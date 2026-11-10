<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Edge;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Group;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Layout;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/flowchart.ts`: `graph` / `flowchart` — node chains with inline shapes
 * and labelled links, plus `subgraph` grouping. Lenient: a statement contributes whatever prefix
 * parsed and the rest is dropped, recorded in `graph->warnings`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class Flowchart implements Diagram
{
    /**
     * Flowchart v2 shape names that read as something other than a plain box. The terminal has
     * three silhouettes; every name not listed here means some kind of box, `rect`.
     */
    private const array AT_SHAPES = [
        'rounded' => 'round', 'stadium' => 'round', 'pill' => 'round', 'terminal' => 'round',
        'cyl' => 'round', 'cylinder' => 'round', 'database' => 'round', 'db' => 'round',
        'circle' => 'round', 'circ' => 'round', 'sm-circ' => 'round', 'small-circle' => 'round',
        'dbl-circ' => 'round', 'double-circle' => 'round', 'fr-circ' => 'round', 'framed-circle' => 'round',
        'start' => 'round', 'stop' => 'round', 'event' => 'round', 'delay' => 'round', 'cloud' => 'round',
        'bang' => 'round', 'diam' => 'diamond', 'diamond' => 'diamond', 'decision' => 'diamond',
        'question' => 'diamond', 'hex' => 'diamond', 'hexagon' => 'diamond', 'prepare' => 'diamond',
    ];

    #[\Override]
    public function kind(): string
    {
        return 'flowchart';
    }

    #[\Override]
    public function headers(): array
    {
        return ['graph', 'flowchart'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $graph = $this->parseGraph($src);

        if ($graph === null) {
            return null;
        }

        $canvas = $graph->groups === [] ? Layout::layoutFlowchart($graph) : Layout::layoutGrouped($graph);

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $graph->warnings, 'classDefs' => $graph->classDefs];
    }

    public function parseGraph(string $src): ?Graph
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $graph = new Graph(Graph::parseDir(Labels::words($statements[0])[1] ?? 'TB'));
        $stack = [];
        // `class A,B name` assignments and `click A "url"` links, applied after the walk so a
        // statement may come before the nodes it names. Unknown ids are ignored.
        $classAssignments = [];
        $hrefs = [];

        foreach (array_slice($statements, 1) as $st) {
            $first = Statements::firstWord($st);

            $lower = Labels::asciiLower($first);

            if ($lower === 'subgraph') {
                if (count($graph->groups) >= Graph::MAX_GROUPS || count($stack) >= Graph::MAX_GROUP_DEPTH) {
                    $graph->truncated ??= 'subgraph cap (' . Graph::MAX_GROUPS . ' groups, depth ' . Graph::MAX_GROUP_DEPTH . ') reached';

                    // The cap ends the walk below, as upstream's `break` out of its `switch` does.
                    break;
                }

                [$id, $label] = self::parseSubgraphDecl(Labels::trim(substr($st, strlen('subgraph'))));
                $graph->groups[] = new Group($id, $label, $stack === [] ? null : $stack[count($stack) - 1]);
                $stack[] = count($graph->groups) - 1;
                $graph->curGroup = $stack[count($stack) - 1];

                continue;
            }

            if ($lower === 'end') {
                array_pop($stack);
                $graph->curGroup = $stack === [] ? null : $stack[count($stack) - 1];

                continue;
            }

            if ($lower === 'classdef') {
                $def = Statements::parseClassDef(substr($st, strlen($first)));

                if ($def !== null) {
                    foreach ($def['names'] as $name) {
                        $graph->classDefs[$name] = $def['props'];
                    }
                }

                continue;
            }

            if ($lower === 'class') {
                $assign = Statements::parseClassAssign(substr($st, strlen($first)));

                if ($assign !== null) {
                    $classAssignments[] = $assign;
                }

                continue;
            }

            if ($lower === 'click') {
                // `click A "url" [tooltip]` / `click A href "url" …`; the callback forms carry
                // nothing a terminal can invoke.
                $target = Statements::parseHref(substr($st, strlen($first)));

                if ($target !== null) {
                    $hrefs[] = $target;
                }

                continue;
            }

            if ($lower === 'style' || $lower === 'linkstyle' || $lower === 'direction') {
                continue;
            }

            if ($graph->truncated === null) {
                self::parseStatement($st, $graph);
            }

            if ($graph->truncated !== null) {
                break;
            }
        }

        $graph->applyClasses($classAssignments);
        $graph->applyHrefs($hrefs);

        if ($graph->truncated !== null) {
            $graph->warnings[] = "diagram truncated: {$graph->truncated}";
        }

        return $graph->nodes === [] ? null : $graph;
    }

    /**
     * `subgraph id[Title]`, `subgraph "Title"`, or a bare title.
     *
     * @return array{0: string, 1: string}
     */
    private static function parseSubgraphDecl(string $rest): array
    {
        if (str_starts_with($rest, '"')) {
            $close = strpos($rest, '"', 1);

            if ($close !== false) {
                $label = substr($rest, 1, $close - 1);

                return [$label, Labels::decodeHtmlEntities($label)];
            }
        }

        $open = strpos($rest, '[');

        if ($open !== false) {
            $id = Labels::trim(substr($rest, 0, $open));
            $label = Labels::cleanLabel(Labels::trim((string) preg_replace('/\]+$/D', '', substr($rest, $open + 1))));

            if ($id !== '' && $label !== '') {
                return [$id, $label];
            }
        }

        return [$rest, $rest];
    }

    /**
     * A chain of `node link node …`, each link fanning out over `&`. Keeps the prefix it could
     * read; the rest goes to `graph->warnings` — unless a cap was hit, whose truncation warning
     * already covers it.
     */
    private static function parseStatement(string $st, Graph $graph): void
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $i = 0;

        $head = self::parseNodeGroup($chars, $i, $graph);

        if ($head === null) {
            if ($graph->truncated === null) {
                $graph->warnings[] = "dropped, does not start with a node: \"{$st}\"";
            }

            return;
        }

        $prev = $head['group'];
        $i = $head['next'];

        for (;;) {
            $i = self::skipSpaces($chars, $i);

            if ($i >= $n) {
                break;
            }

            $link = self::parseLink($chars, $i);

            if ($link === null) {
                if ($graph->truncated === null) {
                    $graph->warnings[] = 'dropped, expected a link: "' . implode('', array_slice($chars, $i)) . '"';
                }

                break;
            }

            $i = self::skipSpaces($chars, $link['next']);
            $target = self::parseNodeGroup($chars, $i, $graph);

            if ($target === null) {
                if ($graph->truncated === null) {
                    $graph->warnings[] = "dropped, link has no target: \"{$st}\"";
                }

                break;
            }

            $i = $target['next'];

            foreach ($prev as $f) {
                foreach ($target['group'] as $t) {
                    // `A <-- B` reads right-to-left: swap the endpoints so the arrow written on
                    // the left becomes a normal forward head.
                    $reversed = $link['left'] === 'arrow' && $link['right'] !== 'arrow';
                    $pushed = $graph->pushEdge(new Edge(
                        $reversed ? $t : $f,
                        $reversed ? $f : $t,
                        $link['label'],
                        $reversed ? 'arrow' : $link['right'],
                        $reversed ? $link['right'] : $link['left'],
                        $link['line'],
                    ));

                    if (!$pushed) {
                        return;
                    }
                }
            }

            $prev = $target['group'];
        }
    }

    /**
     * One or more nodes joined by `&`, which fan out into a cross product.
     *
     * @param list<string> $chars
     * @return array{group: list<int>, next: int}|null
     */
    private static function parseNodeGroup(array $chars, int $start, Graph $graph): ?array
    {
        $first = self::parseNode($chars, $start, $graph);

        if ($first === null) {
            return null;
        }

        $group = [$first['index']];
        $i = $first['next'];

        for (;;) {
            $j = self::skipSpaces($chars, $i);

            if (($chars[$j] ?? null) !== '&') {
                break;
            }

            $next = self::parseNode($chars, $j + 1, $graph);

            if ($next === null) {
                return null;
            }

            $group[] = $next['index'];
            $i = $next['next'];
        }

        return ['group' => $group, 'next' => $i];
    }

    /** @param list<string> $chars */
    private static function skipSpaces(array $chars, int $i): int
    {
        $n = count($chars);

        while ($i < $n && ($chars[$i] === ' ' || $chars[$i] === "\t")) {
            $i++;
        }

        return $i;
    }

    /**
     * An id, its optional shape (bracket or `@{…}`) and `:::class` tag.
     *
     * @param list<string> $chars
     * @return array{index: int, next: int}|null
     */
    private static function parseNode(array $chars, int $start, Graph $graph): ?array
    {
        $n = count($chars);
        $i = self::skipSpaces($chars, $start);
        $idStart = $i;

        // `-` joins the id only when an id character follows, so kebab-case ids parse while
        // `-->` / `-.` / `--` still end it (mermaid lexes ids greedily).
        while ($i < $n && (Labels::isIdChar($chars[$i]) || ($chars[$i] === '-' && $i + 1 < $n && Labels::isIdChar($chars[$i + 1])))) {
            $i++;
        }

        if ($i === $idStart) {
            return null;
        }

        $id = implode('', array_slice($chars, $idStart, $i - $idStart));

        $shaped = ($chars[$i] ?? null) === '@' && ($chars[$i + 1] ?? null) === '{'
            ? self::readAtShape($chars, $i + 2)
            : self::readShapeAt($chars, $i);

        if ($shaped['unclosed'] !== null) {
            $graph->warnings[] = "node \"{$id}\": label is missing its closing `{$shaped['unclosed']}`";
        }

        $index = $graph->nodeIndex($id, $shaped['label'], $shaped['shape']);

        if ($index === null) {
            return null;
        }

        // `id:::name` (after any shape) attaches an author class to the node. pig also takes
        // spaces around the `:::` (`A["x"] ::: hot`), which upstream reads as a broken link:
        // see "Mermaid parser dropped spaced style class annotations" in docs/dev/traps/tui.md.
        $next = $shaped['after'];
        $tag = self::skipSpaces($chars, $next);

        if (($chars[$tag] ?? null) === ':' && ($chars[$tag + 1] ?? null) === ':' && ($chars[$tag + 2] ?? null) === ':') {
            $nameStart = self::skipSpaces($chars, $tag + 3);
            $k = $nameStart;

            while ($k < $n && (Labels::isIdChar($chars[$k]) || $chars[$k] === '-')) {
                $k++;
            }

            // A name never ends in `-`: back off so `A:::x-->B` keeps its link.
            while ($k > $nameStart && $chars[$k - 1] === '-') {
                $k--;
            }

            if ($k > $nameStart) {
                $graph->addClass($index, implode('', array_slice($chars, $nameStart, $k - $nameStart)));
                $next = $k;
            }
        }

        return ['index' => $index, 'next' => $next];
    }

    /**
     * Dispatch on the bracket following an id to pick shape and closing token.
     *
     * @param list<string> $chars
     * @return array{shape: string, label: ?string, after: int, unclosed: ?string}
     */
    private static function readShapeAt(array $chars, int $i): array
    {
        $c = $chars[$i] ?? null;
        $n = $chars[$i + 1] ?? null;

        if ($c === '[') {
            if ($n === '[') {
                return self::readShape($chars, $i + 2, ']]', 'rect');
            }

            if ($n === '(') {
                return self::readShape($chars, $i + 2, ')]', 'round');
            }

            return self::readShape($chars, $i + 1, ']', 'rect');
        }

        if ($c === '(') {
            if ($n === '(') {
                return self::readShape($chars, $i + 2, '))', 'round');
            }

            if ($n === '[') {
                return self::readShape($chars, $i + 2, '])', 'round');
            }

            return self::readShape($chars, $i + 1, ')', 'round');
        }

        if ($c === '{') {
            if ($n === '{') {
                return self::readShape($chars, $i + 2, '}}', 'diamond');
            }

            return self::readShape($chars, $i + 1, '}', 'diamond');
        }

        if ($c === '>') {
            return self::readShape($chars, $i + 1, ']', 'rect');
        }

        return ['shape' => 'rect', 'label' => null, 'after' => $i, 'unclosed' => null];
    }

    /**
     * Label text up to `closer`. Quoting is decided by the first non-space character: inside a
     * quoted label the closer is ignored until the quote closes, so `A["a] b"]` is one node; an
     * unquoted label ends at the first closer, so `A[5" pipe]` keeps its quote.
     *
     * @param list<string> $chars
     * @return array{shape: string, label: ?string, after: int, unclosed: ?string}
     */
    private static function readShape(array $chars, int $start, string $closer, string $shape): array
    {
        $n = count($chars);
        $j = $start;

        while (($chars[$j] ?? null) === ' ' || ($chars[$j] ?? null) === "\t") {
            $j++;
        }

        $quoted = ($chars[$j] ?? null) === '"';
        $closerLen = strlen($closer);

        $i = $start;
        $text = '';
        $inQuotes = false;

        while ($i < $n) {
            $c = $chars[$i];

            if ($quoted && $c === '"') {
                $inQuotes = !$inQuotes;
                $text .= $c;
                $i++;

                continue;
            }

            if (!$inQuotes && implode('', array_slice($chars, $i, $closerLen)) === $closer) {
                return ['shape' => $shape, 'label' => Labels::cleanLabel($text), 'after' => $i + $closerLen, 'unclosed' => null];
            }

            $text .= $c;
            $i++;
        }

        // Ran off the end still looking for the closer: any link operator was swallowed.
        return ['shape' => $shape, 'label' => Labels::cleanLabel($text), 'after' => $n, 'unclosed' => $closer];
    }

    /**
     * The v2 node syntax `id@{shape: cyl, label: "..."}`, the cursor past the `@{`. The body is
     * `key: value` pairs split on top-level commas; quoted values may hold commas and `}`.
     * Unknown keys are ignored, unknown shapes draw as a plain box. A body that never closes
     * reports itself like any unterminated label bracket.
     *
     * @param list<string> $chars
     * @return array{shape: string, label: ?string, after: int, unclosed: ?string}
     */
    private static function readAtShape(array $chars, int $start): array
    {
        $n = count($chars);
        $i = $start;
        $depth = 0;
        $inQuotes = false;

        for (; $i < $n; $i++) {
            $c = $chars[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    $inQuotes = false;
                }
            } elseif ($c === '"') {
                $inQuotes = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            }
        }

        $body = implode('', array_slice($chars, $start, $i - $start));
        $closed = ($chars[$i] ?? null) === '}';
        $shape = 'rect';
        $label = null;

        foreach (Statements::splitTop($body, static fn (string $c): bool => $c === ',') as $pair) {
            $kv = Statements::splitOnce($pair, ':');

            if ($kv === null) {
                continue;
            }

            $key = Labels::asciiLower(Labels::trim($kv[0]));

            if ($key === 'shape') {
                $shape = self::AT_SHAPES[Labels::asciiLower(Labels::trim($kv[1]))] ?? 'rect';
            } elseif ($key === 'label') {
                $label = Statements::nonEmpty(Labels::cleanLabel($kv[1]));
            }
        }

        return $closed
            ? ['shape' => $shape, 'label' => $label, 'after' => $i + 1, 'unclosed' => null]
            : ['shape' => $shape, 'label' => $label, 'after' => $n, 'unclosed' => '}'];
    }

    private static function isLinkChar(?string $c): bool
    {
        return $c === '-' || $c === '.' || $c === '=' || $c === '<' || $c === '>';
    }

    /**
     * A link operator and its label, `-->|text|` or the inline `-- text -->` (the latter only
     * when the first operator carried no head).
     *
     * @param list<string> $chars
     * @return array{left: string, right: string, line: string, label: ?string, next: int}|null
     */
    private static function parseLink(array $chars, int $start): ?array
    {
        $n = count($chars);
        $i = self::skipSpaces($chars, $start);
        $left = 'none';
        $at = $chars[$i] ?? null;
        $after = $chars[$i + 1] ?? null;

        // A leading `o`/`x` decorates the tail, but only directly before an operator.
        if (($at === 'o' || $at === 'x') && ($after === '-' || $after === '.' || $after === '=')) {
            $left = $at === 'o' ? 'circle' : 'cross';
            $i++;
        }

        $opStart = $i;

        while ($i < $n && self::isLinkChar($chars[$i])) {
            $i++;
        }

        if ($i === $opStart) {
            return null;
        }

        $op1 = implode('', array_slice($chars, $opStart, $i - $opStart));

        if ($left === 'none' && str_starts_with($op1, '<')) {
            $left = 'arrow';
        }

        $line = self::lineKind($op1);
        $right = str_contains($op1, '>') ? 'arrow' : 'none';

        if ($right === 'none') {
            $trailing = self::trailingHead($chars, $i);

            if ($trailing !== null) {
                $right = $trailing['head'];
                $i = $trailing['next'];
            }
        }

        if (($chars[$i] ?? null) === '|') {
            $i++;
            $lStart = $i;
            // A quoted stretch keeps its `|`s as text: `-->|"a|b"|`.
            $inQuotes = false;

            while ($i < $n && ($inQuotes || $chars[$i] !== '|')) {
                if ($chars[$i] === '"') {
                    $inQuotes = !$inQuotes;
                }

                $i++;
            }

            $label = Labels::cleanLabel(implode('', array_slice($chars, $lStart, $i - $lStart)));

            if (($chars[$i] ?? null) === '|') {
                $i++;
            }

            return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => Statements::nonEmpty($label), 'next' => $i];
        }

        if ($right === 'none') {
            $textStart = self::skipSpaces($chars, $i);
            $j = $textStart;

            // The label runs to the closing operator, which always starts with two link
            // characters (`--`, `-.`, `==`): a lone `=`/`.`/`-` is label text, and a quoted
            // stretch is label text throughout (`A --"a=b"--> B`).
            while ($j < $n) {
                if ($chars[$j] === '"') {
                    $j++;

                    while ($j < $n && $chars[$j] !== '"') {
                        $j++;
                    }

                    if ($j < $n) {
                        $j++;
                    }
                } elseif (self::isLinkChar($chars[$j]) && self::isLinkChar($chars[$j + 1] ?? null)) {
                    break;
                } else {
                    $j++;
                }
            }

            if ($j < $n && $j > $textStart && $chars[$j] !== '<') {
                $text = implode('', array_slice($chars, $textStart, $j - $textStart));
                $op2Start = $j;

                while ($j < $n && self::isLinkChar($chars[$j])) {
                    $j++;
                }

                $op2 = implode('', array_slice($chars, $op2Start, $j - $op2Start));

                if (str_contains($op2, '>')) {
                    $right = 'arrow';
                } else {
                    $trailing = self::trailingHead($chars, $j);

                    if ($trailing !== null) {
                        $right = $trailing['head'];
                        $j = $trailing['next'];
                    }
                }

                if ($line === 'solid') {
                    $line = self::lineKind($op2);
                }

                return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => Statements::nonEmpty(Labels::cleanLabel($text)), 'next' => $j];
            }
        }

        return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => null, 'next' => $i];
    }

    private static function lineKind(string $op): string
    {
        if (str_contains($op, '=')) {
            return 'thick';
        }

        if (str_contains($op, '.')) {
            return 'dotted';
        }

        return 'solid';
    }

    /**
     * A trailing `o`/`x` head, only when followed by a statement boundary.
     *
     * @param list<string> $chars
     * @return array{head: string, next: int}|null
     */
    private static function trailingHead(array $chars, int $i): ?array
    {
        $c = $chars[$i] ?? null;
        $head = $c === 'o' ? 'circle' : ($c === 'x' ? 'cross' : null);

        if ($head === null) {
            return null;
        }

        $after = $chars[$i + 1] ?? null;
        $boundary = $after === null || $after === ' ' || $after === "\t" || $after === '|' || $after === '&' || $after === ';';

        return $boundary ? ['head' => $head, 'next' => $i + 1] : null;
    }
}
