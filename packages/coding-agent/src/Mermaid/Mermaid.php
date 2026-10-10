<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `render()`: a Mermaid source block as Unicode box-drawing art — `graph` and
 * `flowchart` (subgraphs included), `stateDiagram`, `classDiagram`, `erDiagram` and
 * `sequenceDiagram`, laid out at whatever size they need (`MermaidArt::$width`).
 *
 * Null is no art to show: blank input, a syntax error, a kind it does not draw, or one too large
 * to lay out. Rendering is best-effort: a flowchart keeps whatever parsed, and the stricter
 * grammars get one retry without their last line — what keeps a streaming diagram on screen while
 * its last statement is half-typed. What was given up on is in `warnings`.
 *
 * A PHP port of grok-mermaid 0.2.3 by Alexey Zaytsev, itself a TypeScript port of the terminal
 * Mermaid renderer in xai-org/grok-build. Apache-2.0: see LICENSE in this directory. Ported
 * file for file; the changes are PHP's — the shared width measure (`Measure`) and arrays for the
 * sequence items.
 */
final class Mermaid
{
    public static function render(string $src): ?MermaidArt
    {
        $src = Labels::stripControls($src);

        if (Labels::trim($src) === '') {
            return null;
        }

        $drawn = self::attempt($src);

        if ($drawn === null) {
            return null;
        }

        $lines = $drawn['canvas']->toLines();

        return new MermaidArt($lines['plain'], $lines['styled'], $lines['width'], $drawn['warnings']);
    }

    /** grok-mermaid's `diagramKind()`: `flowchart`, `state`, `class`, `er`, `sequence`, or null. */
    public static function kind(string $src): ?string
    {
        return Parse::diagramKind($src);
    }

    /**
     * Drawn, or drawn again without the last line when the grammar refused it — and that drop
     * always reported.
     *
     * @return array{canvas: Canvas, warnings: list<string>}|null
     */
    private static function attempt(string $src): ?array
    {
        $drawn = self::draw($src);

        if ($drawn !== null) {
            return $drawn;
        }

        $body = Labels::trimEnd($src);
        $cut = strrpos($body, "\n");

        if ($cut === false) {
            return null;
        }

        $salvaged = self::draw(substr($body, 0, $cut));

        if ($salvaged === null) {
            return null;
        }

        $dropped = Labels::trim(substr($body, $cut + 1));

        return [
            'canvas' => $salvaged['canvas'],
            'warnings' => [...$salvaged['warnings'], "dropped, unreadable final line: \"{$dropped}\""],
        ];
    }

    /** @return array{canvas: Canvas, warnings: list<string>}|null */
    private static function draw(string $src): ?array
    {
        $plain = static fn (?Canvas $canvas): ?array => $canvas === null ? null : ['canvas' => $canvas, 'warnings' => []];

        switch (Parse::diagramKind($src)) {
            case 'flowchart':
                $graph = Parse::parseGraph($src);

                if ($graph === null) {
                    return null;
                }

                $canvas = $graph->groups === [] ? Layout::layoutFlowchart($graph) : Layout::layoutGrouped($graph);

                return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $graph->warnings];
            case 'state':
                $state = Parse::parseState($src);

                return $state === null ? null : $plain(Layout::layoutFlowchart($state));
            case 'class':
                $class = Parse::parseClass($src);

                return $class === null ? null : $plain(Layout::layoutClass($class['graph'], $class['infos']));
            case 'er':
                $er = Parse::parseEr($src);

                return $er === null ? null : $plain(Layout::layoutClass($er['graph'], $er['infos']));
            case 'sequence':
                $sequence = Parse::parseSequence($src);

                return $sequence === null ? null : $plain(SequenceLayout::layoutSequence($sequence));
            default:
                return null;
        }
    }
}
