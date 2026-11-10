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
 * lovely-mermaid's `diagrams/er.ts`: `erDiagram` — entities with attribute compartments and
 * crow's-foot relationships. Lenient: an unreadable statement is dropped and recorded in
 * `warnings`. Entities use `Node::$sections` as `[title, attrs]`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class Er implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'er';
    }

    #[\Override]
    public function headers(): array
    {
        return ['erdiagram'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $graph = $this->parseEr($src);

        if ($graph === null) {
            return null;
        }

        $canvas = Layout::layoutClass($graph);

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $graph->warnings, 'classDefs' => $graph->classDefs];
    }

    public function parseEr(string $src): ?Graph
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $graph = new Graph();
        // The open `{` body: an entity index, `skip` for a dropped one, or none.
        $curEntity = null;

        foreach (array_slice($statements, 1) as $st) {
            if ($curEntity !== null) {
                if ($st === '}') {
                    $curEntity = null;
                } elseif ($curEntity !== 'skip') {
                    self::pushErAttribute($graph->nodes[$curEntity], $st);
                }

                continue;
            }

            $rel = self::splitErRelationship($st);

            if ($rel !== null) {
                $tokens = self::erTokens($rel['rel']);
                $op = count($tokens) === 3 ? self::parseErOp($tokens[1]) : null;
                $f = $op === null ? null : self::erEntity($graph, $tokens[0]);
                $t = $f === null ? null : self::erEntity($graph, $tokens[2]);

                if ($op === null || $f === null || $t === null) {
                    $graph->drop($st);
                } else {
                    $relLabel = $rel['label'] === null ? '' : Labels::cleanLabel($rel['label']);
                    $graph->pushEdge(new Edge($f, $t, Statements::nonEmpty($relLabel), 'none', 'none', $op['line'], $op['cardL'], $op['cardR']));
                }
            } else {
                $open = str_ends_with($st, '{');
                $decl = $open ? Labels::trim(substr($st, 0, -1)) : $st;

                if ($decl === '' || count(self::erTokens($decl)) !== 1) {
                    // A bad declaration that opened a body swallows it whole; reading the
                    // attributes as top-level statements would misparse everything inside.
                    $graph->drop($st);

                    if ($open) {
                        $curEntity = 'skip';
                    }
                } else {
                    $idx = self::erEntity($graph, $decl);

                    if ($open) {
                        $curEntity = $idx ?? 'skip';
                    }
                }
            }

            if ($graph->truncated !== null) {
                $graph->warnings[] = "diagram truncated: {$graph->truncated}";

                break;
            }
        }

        return $graph->nodes === [] ? null : $graph;
    }

    /**
     * An entity token (`NAME`, `"Quoted Name"` or `id[Label]`) resolved, its title row kept fresh.
     * A quoted name is its own identity; the quotes are not part of the title.
     */
    private static function erEntity(Graph $graph, string $token): ?int
    {
        $open = str_starts_with($token, '"') ? false : strpos($token, '[');

        if ($open !== false) {
            $id = substr($token, 0, $open);

            if (!str_ends_with($token, ']')) {
                $graph->warnings[] = "entity \"{$id}\": alias is missing its closing `]`";
            }

            $label = Labels::cleanLabel((string) preg_replace('/\]+$/D', '', substr($token, $open + 1)));

            if ($id === '' || $label === '') {
                return null;
            }

            $idx = $graph->nodeLabel($id, $label);
        } elseif (str_starts_with($token, '"')) {
            $label = Labels::cleanLabel($token);

            if ($label === '') {
                return null;
            }

            $idx = $graph->nodeIndex($token, $label, 'rect');
        } else {
            $idx = $graph->nodeIndex($token, null, 'rect');
        }

        if ($idx === null) {
            return null;
        }

        $node = $graph->nodes[$idx];
        $sections = $node->sections ?? [[], []];
        $sections[0] = [Labels::displayGenerics($node->label)];
        $node->sections = $sections;

        return $idx;
    }

    /**
     * Whitespace tokens, quoted spans kept whole so aliases may hold spaces.
     *
     * @return list<string>
     */
    private static function erTokens(string $s): array
    {
        $out = [];
        $cur = '';
        $inQuotes = false;

        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            if ($c === '"') {
                $inQuotes = !$inQuotes;
                $cur .= $c;
            } elseif (!$inQuotes && preg_match('/^' . Labels::SPACE . '$/u', $c) === 1) {
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
            } else {
                $cur .= $c;
            }
        }

        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }

    /** @return array{rel: string, label: ?string}|null */
    private static function splitErRelationship(string $st): ?array
    {
        $split = Statements::splitOnce($st, ':');
        $rel = $split === null ? $st : $split[0];
        $label = $split === null ? null : Labels::trim($split[1]);

        foreach (self::erTokens($rel) as $t) {
            if (self::parseErOp($t) !== null) {
                return ['rel' => $rel, 'label' => $label];
            }
        }

        return null;
    }

    /**
     * A crow's-foot operator: two cardinality glyphs around `--` or `..`.
     *
     * @return array{cardL: string, cardR: string, line: string}|null
     */
    private static function parseErOp(string $tok): ?array
    {
        // Six ASCII characters: six bytes, none of them above 0x7F.
        if (strlen($tok) !== 6 || preg_match('/[\x80-\xff]/', $tok) === 1) {
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

    private static function erCard(string $tok): ?string
    {
        return match ($tok) {
            '|o', 'o|' => '0..1',
            '||' => '1',
            '}o', 'o{' => '*',
            '}|', '|{' => '1..*',
            default => null,
        };
    }

    /** ER attributes are `type name`; a trailing quoted comment is dropped. */
    public static function pushErAttribute(Node $node, string $raw): void
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

        $sections = $node->sections ?? [[], []];
        $line = Labels::decodeHtmlEntities(implode(' ', $parts));

        if (count($sections[1]) < Graph::MAX_MEMBERS) {
            $sections[1][] = $line;
        } elseif (count($sections[1]) === Graph::MAX_MEMBERS) {
            $sections[1][] = '…';
        }

        $node->sections = $sections;
    }
}
