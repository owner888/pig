<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

use Pig\Ai\Utils\JsJson;

/**
 * grok-mermaid's `parse.ts`: source text to diagram model.
 *
 * Every `parseX` returns null when the source is not that kind of diagram, or when it exceeds a
 * cap. Statements are walked by code point (`mb_str_split()`) where upstream walks `[...s]`;
 * where it slices strings by `indexOf`, the offsets are bytes from the same string.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Parse
{
    /** Relation operators, longest-first so `--|>` wins over `--`: [op, headFrom, headTo, line]. */
    private const array CLASS_OPS = [
        ['<|--', 'triangle', 'none', 'solid'],
        ['--|>', 'none', 'triangle', 'solid'],
        ['<|..', 'triangle', 'none', 'dotted'],
        ['..|>', 'none', 'triangle', 'dotted'],
        ['*--', 'diamondFill', 'none', 'solid'],
        ['--*', 'none', 'diamondFill', 'solid'],
        ['o--', 'diamondOpen', 'none', 'solid'],
        ['--o', 'none', 'diamondOpen', 'solid'],
        ['<--', 'arrow', 'none', 'solid'],
        ['-->', 'none', 'arrow', 'solid'],
        ['<..', 'arrow', 'none', 'dotted'],
        ['..>', 'none', 'arrow', 'dotted'],
        ['--', 'none', 'none', 'solid'],
        ['..', 'none', 'none', 'dotted'],
    ];

    private const int MAX_CLASS_OP = 4;

    /** Message operators, longest-first so `-->>` wins over `-->`: [op, dashed, head]. */
    private const array SEQ_OPS = [
        ['-->>', true, 'arrow'],
        ['->>', false, 'arrow'],
        ['--x', true, 'cross'],
        ['-x', false, 'cross'],
        ['--)', true, 'arrow'],
        ['-)', false, 'arrow'],
        ['-->', true, 'arrow'],
        ['->', false, 'arrow'],
    ];

    private const int MAX_SEQ_OP = 4;

    // ------------------------------------------------------------ statements

    /** @param list<string> $out */
    private static function flushStatement(string $cur, array &$out): string
    {
        $trimmed = Labels::trim($cur);

        if ($trimmed !== '') {
            $out[] = $trimmed;
        }

        return '';
    }

    /**
     * grok-mermaid's `splitStatements()`: one source line split into statements on `;`, stopping
     * at a `%%` comment. Quoted spans are opaque, so a label may contain `;` and `%%`.
     *
     * @param list<string> $out
     */
    public static function splitStatements(string $line, array &$out): void
    {
        $chars = mb_str_split($line, 1, 'UTF-8');
        $n = count($chars);
        $cur = '';
        $inQuotes = false;

        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    $inQuotes = false;
                }

                $cur .= $c;
            } elseif ($c === '"') {
                $inQuotes = true;
                $cur .= $c;
            } elseif ($c === '%' && ($chars[$i + 1] ?? null) === '%') {
                break;
            } elseif ($c === ';') {
                $cur = self::flushStatement($cur, $out);
            } else {
                $cur .= $c;
            }
        }

        self::flushStatement($cur, $out);
    }

    /**
     * grok-mermaid's `statementsOf()`: all statements in a source block, in order.
     *
     * @return list<string>
     */
    public static function statementsOf(string $src): array
    {
        $out = [];

        foreach (Labels::srcLines($src) as $line) {
            self::splitStatements($line, $out);
        }

        return $out;
    }

    /** grok-mermaid's `firstWord()`. */
    private static function firstWord(string $s): string
    {
        return Labels::words($s)[0] ?? '';
    }

    /**
     * grok-mermaid's `splitOnce()`: split on the first occurrence of `sep`, Rust's `split_once`.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function splitOnce(string $s, string $sep): ?array
    {
        $i = strpos($s, $sep);

        return $i === false ? null : [substr($s, 0, $i), substr($s, $i + strlen($sep))];
    }

    /** grok-mermaid's `nonEmpty()`. */
    private static function nonEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    /** grok-mermaid's `/\s/.test(s)`. */
    private static function hasSpace(string $s): bool
    {
        return preg_match('/' . Labels::SPACE . '/u', $s) === 1;
    }

    /**
     * grok-mermaid's `headerKind()`: diagram kind from the header statement, lowercased.
     *
     * @param list<string> $statements
     */
    private static function headerKind(array $statements): ?string
    {
        $header = $statements[0] ?? null;

        if ($header === null) {
            return null;
        }

        $kind = self::firstWord($header);

        return $kind === '' ? null : Labels::asciiLower($kind);
    }

    /**
     * grok-mermaid's `diagramKind()`: the kind `src` declares from its header alone, or null.
     * Each branch mirrors the header test in the matching `parseX`.
     */
    public static function diagramKind(string $src): ?string
    {
        $kind = self::headerKind(self::statementsOf($src));

        if ($kind === null) {
            return null;
        }

        if ($kind === 'graph' || $kind === 'flowchart') {
            return 'flowchart';
        }

        if (str_starts_with($kind, 'statediagram')) {
            return 'state';
        }

        if (str_starts_with($kind, 'classdiagram')) {
            return 'class';
        }

        if ($kind === 'erdiagram') {
            return 'er';
        }

        if ($kind === 'sequencediagram') {
            return 'sequence';
        }

        return null;
    }

    // ------------------------------------------------------------- flowchart

    /** grok-mermaid's `parseGraph()`: a `graph`/`flowchart` source. */
    public static function parseGraph(string $src): ?Graph
    {
        $statements = self::statementsOf($src);
        $kind = self::headerKind($statements);

        if ($kind !== 'graph' && $kind !== 'flowchart') {
            return null;
        }

        $graph = new Graph(Graph::parseDir(Labels::words($statements[0])[1] ?? 'TB'));
        $stack = [];

        foreach (array_slice($statements, 1) as $st) {
            $first = Labels::asciiLower(self::firstWord($st));

            if ($first === 'subgraph') {
                if (count($graph->groups) >= Graph::MAX_GROUPS || count($stack) >= Graph::MAX_GROUP_DEPTH) {
                    return null;
                }

                [$id, $label] = self::parseSubgraphDecl(Labels::trim(substr($st, strlen('subgraph'))));
                $graph->groups[] = new Group($id, $label, $stack === [] ? null : $stack[count($stack) - 1]);
                $stack[] = count($graph->groups) - 1;
                $graph->curGroup = $stack[count($stack) - 1];

                continue;
            }

            if ($first === 'end') {
                array_pop($stack);
                $graph->curGroup = $stack === [] ? null : $stack[count($stack) - 1];

                continue;
            }

            if (in_array($first, ['classdef', 'class', 'style', 'linkstyle', 'click', 'direction'], true)) {
                continue;
            }

            self::parseStatement($st, $graph);

            if ($graph->overCap) {
                return null;
            }
        }

        return $graph->nodes === [] ? null : $graph;
    }

    /**
     * grok-mermaid's `parseSubgraphDecl()`: `subgraph id[Title]`, `subgraph "Title"`, or a bare title.
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
     * grok-mermaid's `parseStatement()`: a chain of `node link node …`, each link fanning out over
     * `&`. Keeps the prefix it could read; the rest goes to `graph.warnings`.
     */
    private static function parseStatement(string $st, Graph $graph): void
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $i = 0;

        $head = self::parseNodeGroup($chars, $i, $graph);

        if ($head === null) {
            $graph->warnings[] = "dropped, does not start with a node: \"{$st}\"";

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
                $graph->warnings[] = 'dropped, expected a link: "' . implode('', array_slice($chars, $i)) . '"';

                break;
            }

            $i = self::skipSpaces($chars, $link['next']);
            $target = self::parseNodeGroup($chars, $i, $graph);

            if ($target === null) {
                $graph->warnings[] = "dropped, link has no target: \"{$st}\"";

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
     * grok-mermaid's `parseNodeGroup()`: one or more nodes joined by `&`, which fan out into a
     * cross product.
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

    /**
     * grok-mermaid's `skipSpaces()`.
     *
     * @param list<string> $chars
     */
    private static function skipSpaces(array $chars, int $i): int
    {
        $n = count($chars);

        while ($i < $n && ($chars[$i] === ' ' || $chars[$i] === "\t")) {
            $i++;
        }

        return $i;
    }

    /**
     * grok-mermaid's `parseNode()`: an id, its optional shape bracket and `:::class` tag.
     *
     * @param list<string> $chars
     * @return array{index: int, next: int}|null
     */
    private static function parseNode(array $chars, int $start, Graph $graph): ?array
    {
        $n = count($chars);
        $i = self::skipSpaces($chars, $start);
        $idStart = $i;

        while ($i < $n && Labels::isIdChar($chars[$i])) {
            $i++;
        }

        if ($i === $idStart) {
            return null;
        }

        $id = implode('', array_slice($chars, $idStart, $i - $idStart));

        $shaped = self::readShapeAt($chars, $i);

        if ($shaped['unclosed'] !== null) {
            $graph->warnings[] = "node \"{$id}\": label is missing its closing `{$shaped['unclosed']}`";
        }

        $index = $graph->nodeIndex($id, $shaped['label'], $shaped['shape']);

        if ($index === null) {
            return null;
        }

        // `id:::name` (after any shape) attaches a style class; swallow it so the statement keeps
        // parsing.
        $next = $shaped['after'];

        if (($chars[$next] ?? null) === ':' && ($chars[$next + 1] ?? null) === ':' && ($chars[$next + 2] ?? null) === ':') {
            $k = $next + 3;

            while ($k < $n && (Labels::isIdChar($chars[$k]) || $chars[$k] === '-')) {
                $k++;
            }

            // A name never ends in `-`: back off so `A:::x-->B` keeps its link.
            while ($k > $next + 3 && $chars[$k - 1] === '-') {
                $k--;
            }

            if ($k > $next + 3) {
                $next = $k;
            }
        }

        return ['index' => $index, 'next' => $next];
    }

    /**
     * grok-mermaid's `readShapeAt()`: dispatch on the bracket following an id to pick shape and
     * closing token.
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
     * grok-mermaid's `readShape()`: label text up to `closer`. Quoting is decided by the first
     * non-space character: inside a quoted label the closer is ignored until the quote closes.
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

    /** grok-mermaid's `isLinkChar()`. */
    private static function isLinkChar(?string $c): bool
    {
        return $c === '-' || $c === '.' || $c === '=' || $c === '<' || $c === '>';
    }

    /**
     * grok-mermaid's `parseLink()`: a link operator and its label, `-->|text|` or the inline
     * `-- text -->` (the latter only when the first operator carried no head).
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

            while ($i < $n && $chars[$i] !== '|') {
                $i++;
            }

            $label = Labels::cleanLabel(implode('', array_slice($chars, $lStart, $i - $lStart)));

            if (($chars[$i] ?? null) === '|') {
                $i++;
            }

            return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => self::nonEmpty($label), 'next' => $i];
        }

        if ($right === 'none') {
            $textStart = self::skipSpaces($chars, $i);
            $j = $textStart;

            while ($j < $n && !self::isLinkChar($chars[$j])) {
                $j++;
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

                return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => self::nonEmpty(Labels::cleanLabel($text)), 'next' => $j];
            }
        }

        return ['left' => $left, 'right' => $right, 'line' => $line, 'label' => null, 'next' => $i];
    }

    /** grok-mermaid's `lineKind()`. */
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
     * grok-mermaid's `trailingHead()`: a trailing `o`/`x` head, only when followed by a statement
     * boundary.
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

    // ----------------------------------------------------------------- state

    /** grok-mermaid's `parseState()`: a `stateDiagram` source. */
    public static function parseState(string $src): ?Graph
    {
        $statements = self::statementsOf($src);
        $kind = self::headerKind($statements);

        if ($kind === null || !str_starts_with($kind, 'statediagram')) {
            return null;
        }

        $graph = new Graph();
        $inNote = false;

        foreach (array_slice($statements, 1) as $st) {
            if ($inNote) {
                if (Labels::asciiLower($st) === 'end note') {
                    $inNote = false;
                }

                continue;
            }

            $st = self::dropStyleTags($st);
            $first = Labels::asciiLower(self::firstWord($st));

            if ($first === 'direction') {
                $graph->dir = Graph::parseDir(Labels::words($st)[1] ?? '');
            } elseif ($first === 'note') {
                // A single-line `note ... : text` needs no terminator.
                if (!str_contains($st, ':')) {
                    $inNote = true;
                }
            } elseif ($first === 'state') {
                if (self::parseStateDecl($st, $graph) === null) {
                    return null;
                }
            } elseif (in_array($first, ['classdef', 'class', 'hide', 'scale', '}', '--'], true)) {
                // Styling and composite-state punctuation carry no layout meaning.
            } elseif (str_contains($st, '-->')) {
                if (self::parseTransition($st, $graph) === null) {
                    return null;
                }
            } elseif (self::parseStateDesc($st, $graph) === null) {
                return null;
            }

            if ($graph->overCap) {
                return null;
            }
        }

        return $graph->nodes === [] ? null : $graph;
    }

    /** grok-mermaid's `parseStateDecl()`: `state "Label" as id`, `state id <<choice>>`, or `state id {`. */
    private static function parseStateDecl(string $st, Graph $graph): ?true
    {
        $rest = Labels::trim((string) preg_replace('/\{$/D', '', Labels::trim(substr($st, strlen('state')))));

        if ($rest === '') {
            return true;
        }

        if (str_starts_with($rest, '"')) {
            $close = strpos($rest, '"', 1);

            if ($close === false) {
                return null;
            }

            $label = substr($rest, 1, $close - 1);
            $after = Labels::trim(substr($rest, $close + 1));
            $id = str_starts_with($after, 'as') ? Labels::trim(substr($after, 2)) : $label;

            return $graph->nodeLabel($id, Labels::decodeHtmlEntities($label)) === null ? null : true;
        }

        $shape = 'round';
        $id = $rest;
        $stereotyped = false;
        $pos = strpos($rest, '<<');

        if ($pos !== false) {
            $stereo = Labels::trim((string) preg_replace('/>>$/D', '', substr($rest, $pos + 2)));

            if ($stereo === 'choice') {
                $shape = 'diamond';
            }

            $id = Labels::trim(substr($rest, 0, $pos));
            $stereotyped = true;
        }

        if ($id === '' || self::hasSpace($id)) {
            return null;
        }

        return $graph->nodeIndex($id, $stereotyped ? $id : null, $shape) === null ? null : true;
    }

    /** grok-mermaid's `parseTransition()`: `A --> B: label`, including chains `A --> B --> C`. */
    private static function parseTransition(string $st, Graph $graph): ?true
    {
        $rest = $st;
        $prev = null;

        for (;;) {
            $split = self::splitOnce($rest, '-->');

            if ($split === null) {
                break;
            }

            [$lhs, $rhs] = $split;

            $fromId = Labels::trim((string) preg_replace('/-+$/D', '', Labels::trimEnd($lhs)));

            if ($prev !== null) {
                // Mid-chain: the source is the previous target, so nothing may precede.
                if ($fromId !== '') {
                    return null;
                }

                $from = $prev;
            } else {
                if ($fromId === '') {
                    return null;
                }

                $f = self::stateEndpoint($graph, $fromId, true);

                if ($f === null) {
                    return null;
                }

                $from = $f;
            }

            $nextArrow = strpos($rhs, '-->');
            $toPartRaw = $nextArrow === false ? $rhs : substr($rhs, 0, $nextArrow);
            $tail = $nextArrow === false ? '' : substr($rhs, $nextArrow);

            $colon = self::splitOnce($toPartRaw, ':');
            $toPart = $colon !== null ? $colon[0] : $toPartRaw;
            $label = $colon !== null ? self::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($colon[1]))) : null;

            $toId = Labels::trim((string) preg_replace('/-+$/D', '', Labels::trimEnd((string) preg_replace('/^>+/', '', JsJson::trimStart($toPart)))));

            if ($toId === '') {
                return null;
            }

            $to = self::stateEndpoint($graph, $toId, false);

            if ($to === null) {
                return null;
            }

            if (!$graph->pushEdge(new Edge($from, $to, $label, 'arrow', 'none', 'solid'))) {
                return true;
            }

            $prev = $to;
            $rest = $tail;
        }

        return true;
    }

    /**
     * grok-mermaid's `dropStyleTags()`: every `:::name` style tag removed, with the flowchart
     * shorthand's name scan, before the `:` label split could cut inside one.
     */
    private static function dropStyleTags(string $st): string
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $out = '';

        for ($i = 0; $i < $n;) {
            if ($chars[$i] === ':' && ($chars[$i + 1] ?? null) === ':' && ($chars[$i + 2] ?? null) === ':') {
                $k = $i + 3;

                while ($k < $n && (Labels::isIdChar($chars[$k]) || $chars[$k] === '-')) {
                    $k++;
                }

                while ($k > $i + 3 && $chars[$k - 1] === '-') {
                    $k--;
                }

                if ($k > $i + 3) {
                    $i = $k;

                    continue;
                }
            }

            $out .= $chars[$i];
            $i++;
        }

        return $out;
    }

    /** grok-mermaid's `stateEndpoint()`: `[*]` is start or end depending on its side of the arrow. */
    private static function stateEndpoint(Graph $graph, string $id, bool $isSource): ?int
    {
        if ($id === '[*]') {
            return $graph->nodeIndex($isSource ? '[*]start' : '[*]end', '●', 'round');
        }

        return $graph->nodeIndex($id, null, 'round');
    }

    /** grok-mermaid's `parseStateDesc()`: `id: description`, or a bare state name. */
    private static function parseStateDesc(string $st, Graph $graph): ?true
    {
        $split = self::splitOnce($st, ':');

        if ($split !== null) {
            $id = Labels::trim($split[0]);
            $desc = Labels::trim($split[1]);

            if ($id === '' || self::hasSpace($id) || $desc === '') {
                return null;
            }

            return $graph->nodeLabel($id, Labels::decodeHtmlEntities($desc)) === null ? null : true;
        }

        if (self::hasSpace($st)) {
            return null;
        }

        return $graph->nodeIndex($st, null, 'round') === null ? null : true;
    }

    // ----------------------------------------------------------------- class

    /**
     * grok-mermaid's `parseClass()`: a `classDiagram` source, with each class's compartments
     * aligned to `graph.nodes`.
     *
     * @return array{graph: Graph, infos: list<ClassInfo>}|null
     */
    public static function parseClass(string $src): ?array
    {
        $statements = self::statementsOf($src);
        $kind = self::headerKind($statements);

        if ($kind === null || !str_starts_with($kind, 'classdiagram')) {
            return null;
        }

        $graph = new Graph();
        $infos = [];
        $sync = static function () use (&$infos, $graph): void {
            while (count($infos) < count($graph->nodes)) {
                $infos[] = new ClassInfo();
            }
        };
        // Declare a class, keeping `infos` aligned with `graph.nodes`.
        $declare = static function (string $name) use ($graph, $sync): ?int {
            $idx = $graph->nodeIndex($name, null, 'rect');
            $sync();

            return $idx;
        };
        $curClass = null;

        foreach (array_slice($statements, 1) as $st) {
            if ($curClass !== null) {
                if ($st === '}') {
                    $curClass = null;
                } else {
                    self::pushMember($infos[$curClass], $st);
                }

                continue;
            }

            $st = self::dropStyleTags($st);

            $first = Labels::asciiLower(self::firstWord($st));

            if ($first === 'direction') {
                $graph->dir = Graph::parseDir(Labels::words($st)[1] ?? '');

                continue;
            }

            if (in_array($first, ['note', 'callback', 'click', 'link', 'style', 'cssclass', 'classdef', 'namespace', '}'], true)) {
                continue;
            }

            if ($first === 'class') {
                $rest = Labels::trim(substr($st, strlen('class')));
                $open = str_ends_with($rest, '{');
                $name = $open ? Labels::trim(substr($rest, 0, -1)) : $rest;

                if ($name === '' || self::hasSpace($name)) {
                    return null;
                }

                $idx = $declare($name);

                if ($idx === null) {
                    return null;
                }

                if ($open) {
                    $curClass = $idx;
                }

                continue;
            }

            if (str_starts_with($st, '<<')) {
                $split = self::splitOnce(substr($st, 2), '>>');

                if ($split === null) {
                    return null;
                }

                $name = Labels::trim($split[1]);

                if ($name === '' || self::hasSpace($name)) {
                    return null;
                }

                $idx = $declare($name);

                if ($idx === null) {
                    return null;
                }

                $infos[$idx]->annotation = Labels::trim($split[0]);

                continue;
            }

            $rel = self::parseClassRelation($st);

            if ($rel !== null) {
                $f = $declare($rel['from']);

                if ($f === null) {
                    return null;
                }

                $t = $declare($rel['to']);

                if ($t === null) {
                    return null;
                }

                if (count($graph->edges) >= Graph::MAX_EDGES) {
                    return null;
                }

                $graph->edges[] = new Edge($f, $t, $rel['label'], $rel['headTo'], $rel['headFrom'], $rel['line']);

                continue;
            }

            $member = self::splitOnce($st, ':');

            if ($member !== null) {
                $id = Labels::trim($member[0]);
                $text = Labels::trim($member[1]);

                if ($id === '' || self::hasSpace($id) || $text === '') {
                    return null;
                }

                $idx = $declare($id);

                if ($idx === null) {
                    return null;
                }

                self::pushMember($infos[$idx], $text);

                continue;
            }

            return null;
        }

        if ($graph->nodes === []) {
            return null;
        }

        $sync();

        return ['graph' => $graph, 'infos' => $infos];
    }

    /** grok-mermaid's `pushMember()`: a member added to the attribute or method compartment, eliding past the cap. */
    public static function pushMember(ClassInfo $info, string $raw): void
    {
        if (str_starts_with($raw, '<<')) {
            $split = self::splitOnce(substr($raw, 2), '>>');

            if ($split !== null) {
                $info->annotation = Labels::trim($split[0]);
            }

            return;
        }

        $member = Labels::decodeHtmlEntities(self::displayGenerics(Labels::trim($raw)));

        if (str_contains($member, '(')) {
            $list = &$info->methods;
        } else {
            $list = &$info->attrs;
        }

        if (count($list) < Graph::MAX_MEMBERS) {
            $list[] = $member;
        } elseif (count($list) === Graph::MAX_MEMBERS) {
            $list[] = '…';
        }
    }

    /**
     * grok-mermaid's `parseClassRelation()`: `A <|-- B : label`, with optional quoted cardinalities.
     *
     * @return array{from: string, to: string, headFrom: string, headTo: string, line: string, label: ?string}|null
     */
    private static function parseClassRelation(string $st): ?array
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $found = null;

        for ($pos = 0; $pos < $n; $pos++) {
            $tail = implode('', array_slice($chars, $pos, self::MAX_CLASS_OP));

            foreach (self::CLASS_OPS as [$op, $headFrom, $headTo, $line]) {
                if (!str_starts_with($tail, $op)) {
                    continue;
                }

                // `o` is also an identifier character: skip a match glued to a name.
                if (str_starts_with($op, 'o') && $pos > 0 && Labels::isIdChar($chars[$pos - 1])) {
                    continue;
                }

                $after = $chars[$pos + strlen($op)] ?? null;

                if (str_ends_with($op, 'o') && $after !== null && Labels::isIdChar($after)) {
                    continue;
                }

                $found = ['pos' => $pos, 'op' => $op, 'headFrom' => $headFrom, 'headTo' => $headTo, 'line' => $line];

                break 2;
            }
        }

        if ($found === null) {
            return null;
        }

        $lhsRaw = Labels::trim(implode('', array_slice($chars, 0, $found['pos'])));
        $rhsRaw = Labels::trim(implode('', array_slice($chars, $found['pos'] + strlen($found['op']))));

        [$lhs, $cardFrom] = self::stripCardinalitySuffix($lhsRaw);
        [$rhs, $cardTo] = self::stripCardinalityPrefix($rhsRaw);

        $split = self::splitOnce($rhs, ':');
        $toId = Labels::trim($split !== null ? $split[0] : $rhs);
        $relLabel = $split !== null ? self::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($split[1]))) : null;

        if ($lhs === '' || $toId === '' || self::hasSpace($lhs) || self::hasSpace($toId)) {
            return null;
        }

        $label = self::nonEmpty(implode(' ', array_filter([$cardFrom, $relLabel ?? '', $cardTo], static fn (string $s): bool => $s !== '')));

        return [
            'from' => $lhs,
            'to' => $toId,
            'headFrom' => $found['headFrom'],
            'headTo' => $found['headTo'],
            'line' => $found['line'],
            'label' => $label,
        ];
    }

    /**
     * grok-mermaid's `stripCardinalitySuffix()`: `Class "1"`, a quoted cardinality trailing the
     * left-hand name.
     *
     * @return array{0: string, 1: string}
     */
    private static function stripCardinalitySuffix(string $s): array
    {
        $t = Labels::trimEnd($s);

        if (str_ends_with($t, '"')) {
            $rest = substr($t, 0, -1);
            $q = strrpos($rest, '"');

            if ($q !== false) {
                return [Labels::trimEnd(substr($rest, 0, $q)), substr($rest, $q + 1)];
            }
        }

        return [$t, ''];
    }

    /**
     * grok-mermaid's `stripCardinalityPrefix()`: `"0..*" Class`, a quoted cardinality leading the
     * right-hand name.
     *
     * @return array{0: string, 1: string}
     */
    private static function stripCardinalityPrefix(string $s): array
    {
        $t = JsJson::trimStart($s);

        if (str_starts_with($t, '"')) {
            $rest = substr($t, 1);
            $q = strpos($rest, '"');

            if ($q !== false) {
                return [JsJson::trimStart(substr($rest, $q + 1)), substr($rest, 0, $q)];
            }
        }

        return [$t, ''];
    }

    /** grok-mermaid's `displayGenerics()`: Mermaid's `List~T~` shown as `List<T>`. */
    private static function displayGenerics(string $s): string
    {
        $out = '';
        $open = false;

        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            if ($c === '~') {
                $out .= $open ? '>' : '<';
                $open = !$open;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------- ER

    /**
     * grok-mermaid's `parseEr()`: an `erDiagram` source, entities as boxes with attribute lists.
     *
     * @return array{graph: Graph, infos: list<ClassInfo>}|null
     */
    public static function parseEr(string $src): ?array
    {
        $statements = self::statementsOf($src);

        if (self::headerKind($statements) !== 'erdiagram') {
            return null;
        }

        $graph = new Graph();
        $infos = [];
        $curEntity = null;

        foreach (array_slice($statements, 1) as $st) {
            if ($curEntity !== null) {
                if ($st === '}') {
                    $curEntity = null;
                } else {
                    self::pushErAttribute($infos[$curEntity], $st);
                }

                continue;
            }

            $rel = self::splitErRelationship($st);

            if ($rel !== null) {
                $tokens = Labels::words($rel['rel']);

                if (count($tokens) !== 3) {
                    return null;
                }

                $op = self::parseErOp($tokens[1]);

                if ($op === null) {
                    return null;
                }

                $f = self::erEntity($graph, $infos, $tokens[0]);

                if ($f === null) {
                    return null;
                }

                $t = self::erEntity($graph, $infos, $tokens[2]);

                if ($t === null) {
                    return null;
                }

                if (count($graph->edges) >= Graph::MAX_EDGES) {
                    return null;
                }

                $relLabel = $rel['label'] === null ? '' : Labels::cleanLabel($rel['label']);
                $graph->edges[] = new Edge(
                    $f,
                    $t,
                    self::nonEmpty(implode(' ', array_filter([$op['cardL'], $relLabel, $op['cardR']], static fn (string $s): bool => $s !== ''))),
                    'none',
                    'none',
                    $op['line'],
                );

                continue;
            }

            $open = str_ends_with($st, '{');
            $decl = $open ? Labels::trim(substr($st, 0, -1)) : $st;

            if ($decl === '' || count(Labels::words($decl)) !== 1) {
                return null;
            }

            $idx = self::erEntity($graph, $infos, $decl);

            if ($idx === null) {
                return null;
            }

            if ($open) {
                $curEntity = $idx;
            }
        }

        if ($graph->nodes === []) {
            return null;
        }

        while (count($infos) < count($graph->nodes)) {
            $infos[] = new ClassInfo();
        }

        return ['graph' => $graph, 'infos' => $infos];
    }

    /**
     * grok-mermaid's `erEntity()`: an entity token, `name` or `name[Label]`, declared.
     *
     * @param list<ClassInfo> $infos
     */
    private static function erEntity(Graph $graph, array &$infos, string $token): ?int
    {
        $open = strpos($token, '[');

        if ($open !== false) {
            $id = substr($token, 0, $open);
            $label = Labels::cleanLabel((string) preg_replace('/\]+$/D', '', substr($token, $open + 1)));

            if ($id === '' || $label === '') {
                return null;
            }

            $idx = $graph->nodeLabel($id, $label);
        } else {
            $idx = $graph->nodeIndex($token, null, 'rect');
        }

        if ($idx === null) {
            return null;
        }

        while (count($infos) < count($graph->nodes)) {
            $infos[] = new ClassInfo();
        }

        return $idx;
    }

    /**
     * grok-mermaid's `splitErRelationship()`: the relation part and `: label` of a statement
     * carrying a crow's-foot operator.
     *
     * @return array{rel: string, label: ?string}|null
     */
    private static function splitErRelationship(string $st): ?array
    {
        $split = self::splitOnce($st, ':');
        $rel = $split !== null ? $split[0] : $st;
        $label = $split !== null ? Labels::trim($split[1]) : null;

        foreach (Labels::words($rel) as $t) {
            if (self::parseErOp($t) !== null) {
                return ['rel' => $rel, 'label' => $label];
            }
        }

        return null;
    }

    /** grok-mermaid's `isAscii()`. */
    private static function isAscii(string $s): bool
    {
        return preg_match('/[^\x00-\x7F]/', $s) !== 1;
    }

    /**
     * grok-mermaid's `parseErOp()`: a crow's-foot operator, two cardinality glyphs around `--` or
     * `..`. Upstream's UTF-16 length test reads as bytes once the token is known to be ASCII.
     *
     * @return array{cardL: string, cardR: string, line: string}|null
     */
    private static function parseErOp(string $tok): ?array
    {
        if (strlen($tok) !== 6 || !self::isAscii($tok)) {
            return null;
        }

        $mid = substr($tok, 2, 2);
        $line = $mid === '--' ? 'solid' : ($mid === '..' ? 'dotted' : null);

        if ($line === null) {
            return null;
        }

        $cardL = self::erCard(substr($tok, 0, 2));
        $cardR = self::erCard(substr($tok, 4, 2));

        return $cardL === null || $cardR === null ? null : ['cardL' => $cardL, 'cardR' => $cardR, 'line' => $line];
    }

    /** grok-mermaid's `erCard()`: one cardinality glyph pair as text. */
    private static function erCard(string $tok): ?string
    {
        return match ($tok) {
            '|o', 'o|' => '0..1',
            '||' => '1',
            '}o', 'o{' => '0..*',
            '}|', '|{' => '1..*',
            default => null,
        };
    }

    /** grok-mermaid's `pushErAttribute()`: ER attributes are `type name`; a trailing quoted comment is dropped. */
    public static function pushErAttribute(ClassInfo $info, string $raw): void
    {
        $parts = [];

        foreach (Labels::words($raw) as $tok) {
            if (str_starts_with($tok, '"')) {
                break;
            }

            $parts[] = $tok;
        }

        if ($parts === []) {
            return;
        }

        $line = Labels::decodeHtmlEntities(implode(' ', $parts));

        if (count($info->attrs) < Graph::MAX_MEMBERS) {
            $info->attrs[] = $line;
        } elseif (count($info->attrs) === Graph::MAX_MEMBERS) {
            $info->attrs[] = '…';
        }
    }

    // -------------------------------------------------------------- sequence

    /** grok-mermaid's `parseSequence()`: a `sequenceDiagram` source. */
    public static function parseSequence(string $src): ?Sequence
    {
        $statements = self::statementsOf($src);

        if (self::headerKind($statements) !== 'sequencediagram') {
            return null;
        }

        $seq = new Sequence();
        $autonumber = false;
        $msgCount = 0;
        // One entry per open block; true when it draws a divider on `end`.
        $blocks = [];

        foreach (array_slice($statements, 1) as $st) {
            $first = self::firstWord($st);
            $lower = Labels::asciiLower($first);

            if ($lower === 'participant' || $lower === 'actor') {
                $rest = Labels::trim(substr($st, strlen($first)));

                if ($rest === '') {
                    return null;
                }

                $as = self::splitOnce($rest, ' as ');

                if ($seq->participant($as !== null ? Labels::trim($as[0]) : $rest, $as !== null ? Labels::cleanLabel($as[1]) : null) === null) {
                    return null;
                }

                continue;
            }

            if ($lower === 'autonumber') {
                $autonumber = true;

                continue;
            }

            if (in_array($lower, ['activate', 'deactivate', 'create', 'destroy', 'title', 'acctitle', 'accdescr', 'links', 'link', 'properties'], true)) {
                continue;
            }

            if ($lower === 'note') {
                $note = self::parseNoteAnchor(Labels::trim(substr($st, strlen($first))), $seq);

                if ($note === null) {
                    return null;
                }

                if (count($seq->items) >= Graph::MAX_EDGES) {
                    return null;
                }

                $seq->items[] = ['kind' => 'note', 'anchor' => $note['anchor'], 'text' => $note['text']];

                continue;
            }

            if (in_array($lower, ['loop', 'alt', 'opt', 'par', 'critical', 'break', 'else', 'and', 'option'], true)) {
                if (in_array($lower, ['else', 'and', 'option'], true)) {
                    // A continuation only divides a block that opened one.
                    if ($blocks === [] || $blocks[count($blocks) - 1] !== true) {
                        continue;
                    }
                } else {
                    $blocks[] = true;
                }

                if (count($seq->items) >= Graph::MAX_EDGES) {
                    return null;
                }

                $seq->items[] = ['kind' => 'divider', 'text' => Labels::decodeHtmlEntities($st)];

                continue;
            }

            if ($lower === 'rect' || $lower === 'box') {
                $blocks[] = false;

                continue;
            }

            if ($lower === 'end') {
                if (array_pop($blocks) === true) {
                    if (count($seq->items) >= Graph::MAX_EDGES) {
                        return null;
                    }

                    $seq->items[] = ['kind' => 'divider', 'text' => 'end'];
                }

                continue;
            }

            $msg = self::parseSeqMessage($st, $seq);

            if ($msg === null) {
                return null;
            }

            $text = $msg['text'];

            if ($autonumber) {
                $msgCount++;
                $text = $text === null ? "{$msgCount}." : "{$msgCount}. {$text}";
            }

            if (count($seq->items) >= Graph::MAX_EDGES) {
                return null;
            }

            $seq->items[] = [
                'kind' => 'message',
                'from' => $msg['from'],
                'to' => $msg['to'],
                'text' => $text,
                'dashed' => $msg['dashed'],
                'head' => $msg['head'],
            ];
        }

        return $seq->labels === [] ? null : $seq;
    }

    /**
     * grok-mermaid's `parseNoteAnchor()`: `over A[,B]`, `left of A` or `right of A`, then `: text`.
     *
     * @return array{text: string, anchor: array<string, mixed>}|null
     */
    private static function parseNoteAnchor(string $rest, Sequence $seq): ?array
    {
        $lower = Labels::asciiLower($rest);

        if (str_starts_with($lower, 'over ')) {
            $kind = 'over';
            $idsAndText = substr($rest, strlen('over '));
        } elseif (str_starts_with($lower, 'left of ')) {
            $kind = 'left';
            $idsAndText = substr($rest, strlen('left of '));
        } elseif (str_starts_with($lower, 'right of ')) {
            $kind = 'right';
            $idsAndText = substr($rest, strlen('right of '));
        } else {
            return null;
        }

        $split = self::splitOnce($idsAndText, ':');

        if ($split === null) {
            return null;
        }

        $text = Labels::decodeHtmlEntities(Labels::trim($split[1]));
        $parts = array_values(array_filter(
            array_map(static fn (string $s): string => Labels::trim($s), explode(',', $split[0])),
            static fn (string $s): bool => $s !== '',
        ));

        if ($parts === []) {
            return null;
        }

        $a = $seq->participant($parts[0], null);

        if ($a === null) {
            return null;
        }

        if ($kind !== 'over') {
            return ['text' => $text, 'anchor' => ['kind' => $kind, 'at' => $a]];
        }

        $b = $a;

        if (isset($parts[1])) {
            $second = $seq->participant($parts[1], null);

            if ($second === null) {
                return null;
            }

            $b = $second;
        }

        return ['text' => $text, 'anchor' => ['kind' => 'over', 'from' => min($a, $b), 'to' => max($a, $b)]];
    }

    /**
     * grok-mermaid's `parseSeqMessage()`: `A->>B: text` and the other message operators.
     *
     * @return array{from: int, to: int, text: ?string, dashed: bool, head: string}|null
     */
    private static function parseSeqMessage(string $st, Sequence $seq): ?array
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $found = null;

        for ($pos = 0; $pos < $n; $pos++) {
            $tail = implode('', array_slice($chars, $pos, self::MAX_SEQ_OP));

            foreach (self::SEQ_OPS as [$op, $dashed, $head]) {
                if (str_starts_with($tail, $op)) {
                    $found = ['pos' => $pos, 'op' => $op, 'dashed' => $dashed, 'head' => $head];

                    break 2;
                }
            }
        }

        if ($found === null) {
            return null;
        }

        $fromId = Labels::trim(implode('', array_slice($chars, 0, $found['pos'])));

        if ($fromId === '') {
            return null;
        }

        // `+`/`-` activate and deactivate the target; they carry no layout meaning.
        $rest = (string) preg_replace('/^[+-]+/', '', JsJson::trimStart(implode('', array_slice($chars, $found['pos'] + strlen($found['op'])))));

        $split = self::splitOnce($rest, ':');
        $toId = Labels::trim($split !== null ? $split[0] : $rest);
        $text = $split !== null ? self::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($split[1]))) : null;

        if ($toId === '') {
            return null;
        }

        $from = $seq->participant($fromId, null);

        if ($from === null) {
            return null;
        }

        $to = $seq->participant($toId, null);

        if ($to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'text' => $text, 'dashed' => $found['dashed'], 'head' => $found['head']];
    }
}
