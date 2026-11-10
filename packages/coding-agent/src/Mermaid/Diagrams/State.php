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
 * lovely-mermaid's `diagrams/state.ts`: `stateDiagram` / `stateDiagram-v2` — states, transitions,
 * descriptions and composite states. Lenient: an unreadable statement is dropped and recorded in
 * `warnings`.
 *
 * A composite (`state X { ... }`) becomes a `Group`, drawn as a titled frame by the machinery
 * flowchart subgraphs use; `--` splits a composite into unlabelled sibling region groups. `[*]`
 * is scoped per group, so an inner start dot is a different node from the outer one.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class State implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'state';
    }

    #[\Override]
    public function headers(): array
    {
        return ['statediagram', 'statediagram-v2'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $graph = $this->parseState($src);

        if ($graph === null) {
            return null;
        }

        $canvas = $graph->groups === [] ? Layout::layoutFlowchart($graph) : Layout::layoutGrouped($graph);

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $graph->warnings, 'classDefs' => $graph->classDefs];
    }

    public function parseState(string $src): ?Graph
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $graph = new Graph();
        $inNote = false;
        $classAssignments = [];
        // Open composites: the group itself and its current `--` region, if any.
        $stack = [];

        // A new group under the current scope, or null once a cap is hit.
        $newGroup = static function (string $id, string $label) use ($graph, &$stack): ?int {
            if (count($graph->groups) >= Graph::MAX_GROUPS || count($stack) >= Graph::MAX_GROUP_DEPTH) {
                $graph->truncated ??= 'subgraph cap (' . Graph::MAX_GROUPS . ' groups, depth ' . Graph::MAX_GROUP_DEPTH . ') reached';

                return null;
            }

            $graph->groups[] = new Group($id, $label, $graph->curGroup);

            return count($graph->groups) - 1;
        };
        $scopeOf = static function () use (&$stack): ?int {
            if ($stack === []) {
                return null;
            }

            $top = $stack[count($stack) - 1];

            return $top['region'] ?? $top['base'];
        };

        foreach (array_slice($statements, 1) as $st) {
            if ($inNote) {
                if (Labels::asciiLower($st) === 'end note') {
                    $inNote = false;
                }

                continue;
            }

            $firstWord = Statements::firstWord($st);
            $first = Labels::asciiLower($firstWord);

            if ($first === 'direction') {
                $graph->dir = Graph::parseDir(Labels::words($st)[1] ?? '');
            } elseif ($first === 'note') {
                // A single-line `note ... : text` needs no terminator.
                if (!str_contains($st, ':')) {
                    $inNote = true;
                }
            } elseif ($first === 'state') {
                $rest = Labels::trim(substr($st, strlen($firstWord)));
                $open = str_ends_with($rest, '{');
                $body = $open ? Labels::trim(substr($rest, 0, -1)) : $rest;

                if (!$open) {
                    if (!self::parseStateDecl($body, $graph)) {
                        $graph->drop($st);
                    }
                } else {
                    // A composite. An unreadable declaration still opens an anonymous frame: the
                    // `{` was consumed, so the `}` balance must hold.
                    $named = self::compositeName($body);

                    if ($named === null) {
                        $graph->drop($st);
                    }

                    $gi = $newGroup($named['id'] ?? 'anon ' . count($graph->groups), $named['label'] ?? '');

                    if ($gi !== null) {
                        $stack[] = ['base' => $gi, 'region' => null];
                        $graph->curGroup = $gi;
                    }
                }
            } elseif ($first === '}') {
                array_pop($stack);
                $graph->curGroup = $scopeOf();
            } elseif ($first === '--') {
                // Region divider: members so far move into region 1 on the first `--`; each
                // divider opens the next unlabelled sibling region.
                if ($stack !== []) {
                    $top = count($stack) - 1;
                    $base = $stack[$top]['base'];

                    if ($stack[$top]['region'] === null) {
                        $r1 = $newGroup('region ' . count($graph->groups), '');

                        if ($r1 !== null) {
                            foreach ($graph->nodeGroup as $i => $g) {
                                if ($g === $base) {
                                    $graph->nodeGroup[$i] = $r1;
                                }
                            }

                            foreach ($graph->groups as $i => $g) {
                                if ($i !== $r1 && $g->parent === $base) {
                                    $g->parent = $r1;
                                }
                            }
                        }
                    }

                    $graph->curGroup = $base;
                    $next = $newGroup('region ' . count($graph->groups), '');
                    $stack[$top]['region'] = $next;
                    $graph->curGroup = $next ?? $base;
                }
            } elseif ($first === 'classdef') {
                $def = Statements::parseClassDef(substr($st, strlen($firstWord)));

                if ($def !== null) {
                    foreach ($def['names'] as $name) {
                        $graph->classDefs[$name] = $def['props'];
                    }
                }
            } elseif ($first === 'class') {
                $assign = Statements::parseClassAssign(substr($st, strlen($firstWord)));

                if ($assign !== null) {
                    $classAssignments[] = $assign;
                }
            } elseif ($first === 'hide' || $first === 'scale') {
                // Styling directives carry no layout meaning.
            } elseif (str_contains($st, '-->')) {
                if (!self::parseTransition($st, $graph)) {
                    $graph->drop($st);
                }
            } elseif (!self::parseStateDesc($st, $graph)) {
                $graph->drop($st);
            }

            if ($graph->truncated !== null) {
                $graph->warnings[] = "diagram truncated: {$graph->truncated}";

                break;
            }
        }

        $graph->applyClasses($classAssignments);

        return $graph->nodes === [] ? null : $graph;
    }

    private static function hasSpace(string $s): bool
    {
        return preg_match('/' . Labels::SPACE . '/u', $s) === 1;
    }

    /** @return array{id: string, label: string}|null the id and label of a composite declaration body */
    private static function compositeName(string $body): ?array
    {
        if (str_starts_with($body, '"')) {
            $close = strpos($body, '"', 1);

            if ($close === false) {
                return null;
            }

            $label = Labels::decodeHtmlEntities(substr($body, 1, $close - 1));
            $after = Labels::trim(substr($body, $close + 1));
            // A `:::` tag on a composite is dropped: groups paint no classed cells.
            $id = Statements::takeTags(str_starts_with($after, 'as') ? Labels::trim(substr($after, 2)) : $label)['id'];

            return $id === '' ? null : ['id' => $id, 'label' => $label];
        }

        // A stereotype on a composite carries no drawing of its own; keep the name.
        $id = Statements::takeTags(Labels::trim(explode('<<', $body)[0]))['id'];

        return $id === '' || self::hasSpace($id) ? null : ['id' => $id, 'label' => $id];
    }

    /** `state "Label" as id` or `state id <<choice>>` — the non-composite forms. */
    private static function parseStateDecl(string $rest, Graph $graph): bool
    {
        if ($rest === '') {
            return true;
        }

        if (str_starts_with($rest, '"')) {
            $close = strpos($rest, '"', 1);

            if ($close === false) {
                return false;
            }

            $label = substr($rest, 1, $close - 1);
            $after = Labels::trim(substr($rest, $close + 1));
            ['id' => $id, 'classes' => $classes] = Statements::takeTags(str_starts_with($after, 'as') ? Labels::trim(substr($after, 2)) : $label);
            $idx = $graph->nodeLabel($id, Labels::decodeHtmlEntities($label));

            if ($idx === null) {
                return false;
            }

            foreach ($classes as $cls) {
                $graph->addClass($idx, $cls);
            }

            return true;
        }

        $shape = 'round';
        $token = $rest;
        $stereotyped = false;
        $pos = strpos($rest, '<<');

        if ($pos !== false) {
            $stereo = Labels::trim((string) preg_replace('/>>$/D', '', substr($rest, $pos + 2)));

            if ($stereo === 'choice') {
                $shape = 'diamond';
            }

            $token = Labels::trim(substr($rest, 0, $pos));
            $stereotyped = true;
        }

        ['id' => $id, 'classes' => $classes] = Statements::takeTags($token);

        if ($id === '' || self::hasSpace($id)) {
            return false;
        }

        $idx = $graph->nodeIndex($id, $stereotyped ? $id : null, $shape);

        if ($idx === null) {
            return false;
        }

        foreach ($classes as $cls) {
            $graph->addClass($idx, $cls);
        }

        return true;
    }

    /** `A --> B: label`, including chains `A --> B --> C`. */
    private static function parseTransition(string $st, Graph $graph): bool
    {
        $rest = $st;
        $prev = null;

        for (;;) {
            $split = Statements::splitOnce($rest, '-->');

            if ($split === null) {
                break;
            }

            [$lhs, $rhs] = $split;
            $fromTok = Statements::takeTags(Labels::trim((string) preg_replace('/-+$/D', '', Labels::trimEnd($lhs))));

            if ($prev !== null) {
                // Mid-chain: the source is the previous target, so nothing may precede.
                if ($fromTok['id'] !== '') {
                    return false;
                }

                $from = $prev;
            } else {
                if ($fromTok['id'] === '') {
                    return false;
                }

                $f = self::stateEndpoint($graph, $fromTok['id'], true);

                if ($f === null) {
                    return false;
                }

                foreach ($fromTok['classes'] as $cls) {
                    $graph->addClass($f, $cls);
                }

                $from = $f;
            }

            // After the label colon it is all label — mermaid never chains past a label, so an
            // arrow inside one (`: go "x --> y"`) is text, not a link.
            $nextArrow = strpos($rhs, '-->');
            $colon = Statements::splitColon($rhs);
            $labelFirst = $colon !== null && ($nextArrow === false || strlen($colon[0]) < $nextArrow);
            $toPart = $labelFirst ? $colon[0] : ($nextArrow === false ? $rhs : substr($rhs, 0, $nextArrow));
            $label = $labelFirst ? Statements::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($colon[1]))) : null;
            $tail = $labelFirst || $nextArrow === false ? '' : substr($rhs, $nextArrow);

            $toTok = Statements::takeTags(Labels::trim((string) preg_replace('/-+$/D', '', Labels::trimEnd((string) preg_replace('/^>+/', '', self::trimStart($toPart))))));

            if ($toTok['id'] === '') {
                return false;
            }

            $to = self::stateEndpoint($graph, $toTok['id'], false);

            if ($to === null) {
                return false;
            }

            foreach ($toTok['classes'] as $cls) {
                $graph->addClass($to, $cls);
            }

            if (!$graph->pushEdge(new Edge($from, $to, $label, 'arrow', 'none', 'solid'))) {
                return true;
            }

            $prev = $to;
            $rest = $tail;
        }

        return true;
    }

    private static function trimStart(string $s): string
    {
        return (string) preg_replace('/^' . Labels::SPACE . '+/u', '', $s);
    }

    /**
     * `[*]` is start or end depending on which side of the arrow it sits, and is scoped to the
     * enclosing composite — each frame has its own start and end.
     */
    private static function stateEndpoint(Graph $graph, string $id, bool $isSource): ?int
    {
        if ($id === '[*]') {
            $scope = $graph->curGroup === null ? '' : " g{$graph->curGroup}";

            return $graph->nodeIndex('[*]' . ($isSource ? 'start' : 'end') . $scope, '●', 'round');
        }

        return $graph->nodeIndex($id, null, 'round');
    }

    /** `id: description`, or a bare state name; either may carry `:::` tags. */
    private static function parseStateDesc(string $st, Graph $graph): bool
    {
        $split = Statements::splitColon($st);
        ['id' => $id, 'classes' => $classes] = Statements::takeTags(Labels::trim($split === null ? $st : $split[0]));

        if ($split !== null) {
            $desc = Labels::trim($split[1]);

            if ($id === '' || self::hasSpace($id) || $desc === '') {
                return false;
            }

            // Repeated descriptions accumulate (mermaid stacks them as lines; here they join and
            // wrap). A bare node's label is its id — that one replaces.
            $prev = $graph->find($id);
            $before = $prev === null ? null : $graph->nodes[$prev]->label;
            $text = Labels::decodeHtmlEntities($desc);
            $idx = $graph->nodeLabel($id, $before !== null && $before !== $id ? "{$before} {$text}" : $text);
        } else {
            if ($id === '' || self::hasSpace($id)) {
                return false;
            }

            $idx = $graph->nodeIndex($id, null, 'round');
        }

        if ($idx === null) {
            return false;
        }

        foreach ($classes as $cls) {
            $graph->addClass($idx, $cls);
        }

        return true;
    }
}
