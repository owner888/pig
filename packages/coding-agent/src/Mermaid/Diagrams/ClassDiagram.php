<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Edge;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Layout;
use Pig\CodingAgent\Mermaid\Node;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/class.ts`: `classDiagram` — classes with member compartments and UML
 * relations. Lenient: an unreadable statement is dropped and recorded in `warnings`.
 *
 * Compartments live on `Node::$sections`: `[title, attrs, methods]`, the title being the optional
 * `«annotation»` line followed by the class name.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class ClassDiagram implements Diagram
{
    /** Relation operators, longest first so `--|>` wins over `--`: [op, headFrom, headTo, line]. */
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

    #[\Override]
    public function kind(): string
    {
        return 'class';
    }

    #[\Override]
    public function headers(): array
    {
        return ['classdiagram', 'classdiagram-v2'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $graph = $this->parseClass($src);

        if ($graph === null) {
            return null;
        }

        $canvas = Layout::layoutClass($graph);

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $graph->warnings, 'classDefs' => $graph->classDefs];
    }

    private static function hasSpace(string $s): bool
    {
        return preg_match('/' . Labels::SPACE . '/u', $s) === 1;
    }

    public function parseClass(string $src): ?Graph
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $graph = new Graph();
        // A class declared from an id token, with any `:::` tags it carries attached.
        $declare = static function (string $token) use ($graph): ?int {
            ['id' => $id, 'classes' => $classes] = Statements::takeTags($token);
            $idx = $graph->nodeIndex($id, null, 'rect');

            if ($idx !== null) {
                $graph->nodes[$idx]->sections ??= [[Labels::displayGenerics($id)], [], []];

                foreach ($classes as $cls) {
                    $graph->addClass($idx, $cls);
                }
            }

            return $idx;
        };
        // The open `{` body: a class index, `skip` for a dropped class, or none.
        $curClass = null;
        $classAssignments = [];
        $hrefs = [];

        foreach (array_slice($statements, 1) as $st) {
            if ($curClass !== null) {
                if ($st === '}') {
                    $curClass = null;
                } elseif ($curClass !== 'skip') {
                    self::pushMember($graph->nodes[$curClass], $st);
                }

                continue;
            }

            $firstWord = Statements::firstWord($st);
            $first = Labels::asciiLower($firstWord);

            if ($first === 'direction') {
                $graph->dir = Graph::parseDir(Labels::words($st)[1] ?? '');

                continue;
            }

            if ($first === 'classdef') {
                $def = Statements::parseClassDef(substr($st, strlen($firstWord)));

                if ($def !== null) {
                    foreach ($def['names'] as $name) {
                        $graph->classDefs[$name] = $def['props'];
                    }
                }

                continue;
            }

            if (in_array($first, ['note', 'callback', 'style', 'namespace', '}'], true)) {
                continue;
            }

            if ($first === 'link' || $first === 'click') {
                $target = Statements::parseHref(substr($st, strlen($firstWord)));

                if ($target !== null) {
                    $hrefs[] = $target;
                }

                continue;
            }

            if ($first === 'cssclass') {
                $assign = Statements::parseClassAssign(str_replace('"', '', substr($st, strlen($firstWord))));

                if ($assign !== null) {
                    $classAssignments[] = $assign;
                } else {
                    $graph->drop($st);
                }

                continue;
            }

            if ($first === 'class') {
                $rest = Labels::trim(substr($st, strlen('class')));
                $open = str_ends_with($rest, '{');
                $name = $open ? Labels::trim(substr($rest, 0, -1)) : $rest;
                // `class A["Label"]` (mermaid 10.1 and later): the label titles the box, the id
                // keys relations. Peeled before the space test — labels carry spaces.
                $label = null;
                $any = '[^\n\r\x{2028}\x{2029}]';

                if (preg_match('/^(' . Labels::NONSPACE . '+?)\[(' . $any . '+)\](:::' . Labels::NONSPACE . '+)?$/Du', $name, $labeled) === 1) {
                    $name = $labeled[1] . ($labeled[3] ?? '');
                    $label = Statements::nonEmpty(Labels::cleanLabel($labeled[2]));
                }

                if (!$open && self::hasSpace($name)) {
                    // Class names carry no spaces, so `class Agent focus` is the assignment form,
                    // as in flowcharts.
                    $assign = Statements::parseClassAssign($name);

                    if ($assign !== null) {
                        $classAssignments[] = $assign;
                    } else {
                        $graph->drop($st);
                    }
                } elseif ($name === '' || self::hasSpace($name)) {
                    // A bad declaration that opened a body swallows it whole; reading the members
                    // as top-level statements would misparse everything inside.
                    $graph->drop($st);

                    if ($open) {
                        $curClass = 'skip';
                    }
                } else {
                    $idx = $declare($name);

                    if ($idx !== null && $label !== null) {
                        $node = $graph->nodes[$idx];
                        $node->label = $label;

                        // The name is the last title line (an annotation may come before it).
                        if ($node->sections !== null && $node->sections[0] !== []) {
                            $node->sections[0][count($node->sections[0]) - 1] = $label;
                        }
                    }

                    if ($open) {
                        $curClass = $idx ?? 'skip';
                    }
                }
            } elseif (str_starts_with($st, '<<')) {
                $split = Statements::splitOnce(substr($st, 2), '>>');
                $name = $split === null ? '' : Labels::trim($split[1]);

                if ($split === null || $name === '' || self::hasSpace($name)) {
                    $graph->drop($st);
                } else {
                    $idx = $declare($name);

                    if ($idx !== null) {
                        self::setAnnotation($graph->nodes[$idx], Labels::trim($split[0]));
                    }
                }
            } else {
                $rel = self::parseClassRelation($st);

                if ($rel !== null) {
                    $f = $declare($rel['from']);
                    $t = $f === null ? null : $declare($rel['to']);

                    if ($f !== null && $t !== null) {
                        $graph->pushEdge(new Edge($f, $t, $rel['label'], $rel['headTo'], $rel['headFrom'], $rel['line'], $rel['cardFrom'], $rel['cardTo']));
                    }
                } else {
                    $member = Statements::splitColon($st);
                    $id = $member === null ? '' : Labels::trim($member[0]);
                    $text = $member === null ? '' : Labels::trim($member[1]);

                    if ($member === null || $id === '' || self::hasSpace($id) || $text === '') {
                        $graph->drop($st);
                    } else {
                        $idx = $declare($id);

                        if ($idx !== null) {
                            self::pushMember($graph->nodes[$idx], $text);
                        }
                    }
                }
            }

            if ($graph->truncated !== null) {
                $graph->warnings[] = "diagram truncated: {$graph->truncated}";

                break;
            }
        }

        $graph->applyClasses($classAssignments);
        $graph->applyHrefs($hrefs);

        return $graph->nodes === [] ? null : $graph;
    }

    /** The title compartment rewritten as `«annotation»` over the class name. */
    private static function setAnnotation(Node $node, string $annotation): void
    {
        $sections = $node->sections ?? [[], [], []];
        $sections[0] = ["«{$annotation}»", Labels::displayGenerics($node->label)];
        $node->sections = $sections;
    }

    /** A member added to the attribute or method compartment, elided past the cap. */
    public static function pushMember(Node $node, string $raw): void
    {
        if (str_starts_with($raw, '<<')) {
            $split = Statements::splitOnce(substr($raw, 2), '>>');

            if ($split !== null) {
                self::setAnnotation($node, Labels::trim($split[0]));
            }

            return;
        }

        $sections = $node->sections ?? [[], [], []];
        $member = Labels::decodeHtmlEntities(Labels::displayGenerics(Labels::trim($raw)));
        $k = str_contains($member, '(') ? 2 : 1;

        if (count($sections[$k]) < Graph::MAX_MEMBERS) {
            $sections[$k][] = $member;
        } elseif (count($sections[$k]) === Graph::MAX_MEMBERS) {
            $sections[$k][] = '…';
        }

        $node->sections = $sections;
    }

    /** @return array{from: string, to: string, headFrom: string, headTo: string, line: string, label: ?string, cardFrom: ?string, cardTo: ?string}|null */
    private static function parseClassRelation(string $st): ?array
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        // Quoted spans are skipped, or the `..` inside a cardinality like `"0..*"` would match
        // the dotted-link operator.
        $quoted = Statements::quoteMask($chars);
        $found = null;

        for ($pos = 0; $pos < $n && $found === null; $pos++) {
            if ($quoted[$pos]) {
                continue;
            }

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

                break;
            }
        }

        if ($found === null) {
            return null;
        }

        $lhsRaw = Labels::trim(implode('', array_slice($chars, 0, $found['pos'])));
        $rhsRaw = Labels::trim(implode('', array_slice($chars, $found['pos'] + strlen($found['op']))));

        [$lhs, $cardFrom] = self::stripCardinalitySuffix($lhsRaw);
        [$rhs, $cardTo] = self::stripCardinalityPrefix($rhsRaw);

        $split = Statements::splitColon($rhs);
        $toId = Labels::trim($split === null ? $rhs : $split[0]);
        $relLabel = $split === null ? null : Statements::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($split[1])));

        if ($lhs === '' || $toId === '' || self::hasSpace($lhs) || self::hasSpace($toId)) {
            return null;
        }

        return [
            'from' => $lhs,
            'to' => $toId,
            'headFrom' => $found['headFrom'],
            'headTo' => $found['headTo'],
            'line' => $found['line'],
            'label' => $relLabel,
            'cardFrom' => $cardFrom === '' ? null : $cardFrom,
            'cardTo' => $cardTo === '' ? null : $cardTo,
        ];
    }

    /**
     * `Class "1"` — a quoted cardinality trailing the left-hand name.
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
     * `"0..*" Class` — a quoted cardinality leading the right-hand name.
     *
     * @return array{0: string, 1: string}
     */
    private static function stripCardinalityPrefix(string $s): array
    {
        $t = (string) preg_replace('/^' . Labels::SPACE . '+/u', '', $s);

        if (str_starts_with($t, '"')) {
            $rest = substr($t, 1);
            $q = strpos($rest, '"');

            if ($q !== false) {
                return [(string) preg_replace('/^' . Labels::SPACE . '+/u', '', substr($rest, $q + 1)), substr($rest, 0, $q)];
            }
        }

        return [$t, ''];
    }
}
