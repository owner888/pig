<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `render()`: a Mermaid source block as Unicode box-drawing art — `graph` and
 * `flowchart` (subgraphs included), `stateDiagram` (composite states as frames), `classDiagram`,
 * `erDiagram`, `sequenceDiagram`, `pie`, `mindmap`, `timeline` and `gitGraph` — laid out at
 * whatever size it needs (`MermaidArt::$width`). pig adds `journey` and `kanban` (`Journey`,
 * `Kanban`), which lovely-mermaid does not draw.
 *
 * Null is no art to show: blank input, a kind not drawn here, a source in which not one statement
 * parsed, or a diagram large enough that laying it out is refused. Rendering is best-effort in
 * every grammar: a statement either contributes what parsed or is dropped, and a diagram over a
 * size cap draws its prefix. What was given up on is in `warnings`. A frontmatter `title:` is
 * centred above the art, in the `title` role.
 *
 * A PHP port of lovely-mermaid 0.3.3 by Alexey Zaytsev, which grew out of grok-mermaid, a
 * TypeScript port of the terminal Mermaid renderer in xai-org/grok-build. Apache-2.0: see LICENSE
 * in this directory. Ported file for file (`diagrams/*.ts` in `Diagrams\`); the changes are PHP's
 * — the shared width measure (`Measure`) and arrays for the plain records.
 */
final class Mermaid
{
    public static function render(string $src): ?MermaidArt
    {
        $src = Labels::stripControls($src);

        if (Labels::trim($src) === '') {
            return null;
        }

        $drawn = Registry::diagramFor($src)?->render($src);

        if ($drawn === null) {
            return null;
        }

        ['plain' => $plain, 'styled' => $styled, 'width' => $width] = $drawn['canvas']->toLines();
        $title = Statements::frontmatterTitle($src);

        if ($title !== null) {
            $tw = Measure::width($title);
            $width = max($width, $tw);
            $pad = str_repeat(' ', intdiv($width - $tw, 2));
            array_unshift($plain, $pad . $title, '');
            array_unshift(
                $styled,
                $pad === '' ? [new Span($title, 'title')] : [new Span($pad, 'none'), new Span($title, 'title')],
                [],
            );
        }

        return new MermaidArt($plain, $styled, $width, $drawn['classDefs'], $drawn['warnings']);
    }

    /**
     * The kind `src` declares — `flowchart`, `state`, `class`, `er`, `sequence`, `pie`, `mindmap`,
     * `timeline`, `gitgraph`, or pig's `journey` and `kanban` — or null. Reads the header only.
     */
    public static function kind(string $src): ?string
    {
        return Registry::diagramKind($src);
    }
}
