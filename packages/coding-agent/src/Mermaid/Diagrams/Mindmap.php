<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Canvas;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/mindmap.ts`: `mindmap` — an indentation tree, drawn the way every TUI
 * draws trees (`├──`/`└──` guides). Reads raw lines rather than statements: the indentation is the
 * grammar, and `Statements::statementsOf()` trims it away. A node is `['text' => string,
 * 'children' => list<node>]`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class Mindmap implements Diagram
{
    /** Shape brackets around a mindmap node all mean text in a terminal. */
    private const array SHAPES = [
        ['((', '))'],
        ['))', '(('],
        ['(-', '-)'],
        ['{{', '}}'],
        ['[', ']'],
        ['(', ')'],
    ];

    #[\Override]
    public function kind(): string
    {
        return 'mindmap';
    }

    #[\Override]
    public function headers(): array
    {
        return ['mindmap'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $parsed = self::parseMindmap($src);

        if ($parsed === null) {
            return null;
        }

        // [prefix, text] per row; prefixes carry the `│ ├ └` guides.
        $rows = [];
        $walk = static function (array $node, string $prefix, string $childPrefix) use (&$walk, &$rows): void {
            $rows[] = [$prefix, $node['text']];
            $last = count($node['children']) - 1;

            foreach ($node['children'] as $i => $child) {
                $walk($child, $childPrefix . ($i === $last ? '└── ' : '├── '), $childPrefix . ($i === $last ? '    ' : '│   '));
            }
        };

        foreach ($parsed['roots'] as $root) {
            $walk($root, '', '');
        }

        $width = max(array_map(static fn (array $r): int => Measure::width($r[0]) + Measure::width($r[1]), $rows));
        $canvas = new Canvas($width, count($rows));

        foreach ($rows as $y => [$prefix, $text]) {
            Canvas::drawText($canvas, $prefix, 0, $y, 'edge');
            Canvas::drawText($canvas, $text, Measure::width($prefix), $y, 'text');
        }

        return ['canvas' => $canvas, 'warnings' => $parsed['warnings'], 'classDefs' => []];
    }

    /** @return array{roots: list<array<string, mixed>>, warnings: list<string>}|null */
    private static function parseMindmap(string $src): ?array
    {
        $all = Labels::srcLines($src);
        $lines = array_slice($all, Statements::frontmatterEnd($all));
        $headerAt = -1;

        foreach ($lines as $i => $l) {
            if (Labels::trim($l) !== '') {
                $headerAt = $i;

                break;
            }
        }

        if ($headerAt === -1 || mb_strtolower(Labels::trim($lines[$headerAt])) !== 'mindmap') {
            return null;
        }

        // Nodes are built in a flat table and linked by index, then assembled into trees.
        $nodes = [];
        $children = [];
        $roots = [];
        $warnings = [];
        // Ancestors of the next node: the node at each indent level seen so far.
        $stack = [];
        $truncated = false;

        foreach (array_slice($lines, $headerAt + 1) as $raw) {
            $noComment = explode('%%', $raw)[0];

            if (Labels::trim($noComment) === '') {
                continue;
            }

            // Indentation in UTF-16 units, as upstream counts it; leading white space is BMP.
            $indent = mb_strlen($noComment, 'UTF-8') - mb_strlen((string) preg_replace('/^' . Labels::SPACE . '+/u', '', $noComment), 'UTF-8');
            $body = Labels::trim($noComment);

            // Decoration lines attach to the previous node and draw nothing.
            if (str_starts_with($body, '::icon') || str_starts_with($body, ':::')) {
                continue;
            }

            $text = self::nodeText($body);

            if ($text === '') {
                $warnings[] = "dropped, unreadable statement: \"{$body}\"";

                continue;
            }

            if (count($nodes) >= Graph::MAX_NODES) {
                $truncated = true;

                break;
            }

            $id = count($nodes);
            $nodes[] = Labels::fitLabel($text, Labels::WRAP_WIDTH);
            $children[] = [];

            while ($stack !== [] && $stack[count($stack) - 1]['indent'] >= $indent) {
                array_pop($stack);
            }

            if ($stack === []) {
                $roots[] = $id;
            } else {
                $children[$stack[count($stack) - 1]['node']][] = $id;
            }

            $stack[] = ['indent' => $indent, 'node' => $id];
        }

        if ($truncated) {
            $warnings[] = 'diagram truncated: node cap (' . Graph::MAX_NODES . ') reached';
        }

        $tree = static function (int $id) use (&$tree, $nodes, $children): array {
            return ['text' => $nodes[$id], 'children' => array_map($tree, $children[$id])];
        };

        return $roots === [] ? null : ['roots' => array_map($tree, $roots), 'warnings' => $warnings];
    }

    private static function nodeText(string $body): string
    {
        // `id((text))` — an optional id may come before the bracket.
        $length = mb_strlen($body, 'UTF-8');

        foreach (self::SHAPES as [$open, $close]) {
            $at = mb_strpos($body, $open, 0, 'UTF-8');

            if ($at !== false && str_ends_with($body, $close) && $length > $at + strlen($open) + strlen($close) - 1) {
                return Labels::cleanLabel(mb_substr($body, $at + strlen($open), $length - strlen($close) - $at - strlen($open), 'UTF-8'));
            }
        }

        return Labels::cleanLabel($body);
    }
}
