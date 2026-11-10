<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

use Pig\CodingAgent\Mermaid\Diagrams\ClassDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\Diagram;
use Pig\CodingAgent\Mermaid\Diagrams\Er;
use Pig\CodingAgent\Mermaid\Diagrams\Flowchart;
use Pig\CodingAgent\Mermaid\Diagrams\GitGraph;
use Pig\CodingAgent\Mermaid\Diagrams\JourneyDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\KanbanDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\Mindmap;
use Pig\CodingAgent\Mermaid\Diagrams\Pie;
use Pig\CodingAgent\Mermaid\Diagrams\SequenceDiagram;
use Pig\CodingAgent\Mermaid\Diagrams\State;
use Pig\CodingAgent\Mermaid\Diagrams\Timeline;

/**
 * lovely-mermaid's `registry.ts`: one entry per diagram type. `Mermaid::kind()` and
 * `Mermaid::render()` both resolve through this table, so the header test each parser gates on and
 * the one `kind()` reports are the same by construction. Headers match exactly: upstream's prefix
 * tests accepted junk like `stateDiagramFoo` that mermaid itself rejects.
 *
 * pig adds `journey` and `kanban` (`Journey`, `Kanban`), which lovely-mermaid does not draw.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Registry
{
    /** @var list<Diagram>|null */
    private static ?array $diagrams = null;

    /** @return list<Diagram> */
    public static function diagrams(): array
    {
        return self::$diagrams ??= [
            new Flowchart(),
            new State(),
            new ClassDiagram(),
            new Er(),
            new SequenceDiagram(),
            new Pie(),
            new Mindmap(),
            new Timeline(),
            new GitGraph(),
            new JourneyDiagram(),
            new KanbanDiagram(),
        ];
    }

    /** The entry `src`'s header declares, or null. */
    public static function diagramFor(string $src): ?Diagram
    {
        $header = Statements::headerKind(Statements::statementsOf($src));

        if ($header === null) {
            return null;
        }

        foreach (self::diagrams() as $diagram) {
            if (in_array($header, $diagram->headers(), true)) {
                return $diagram;
            }
        }

        return null;
    }

    /**
     * The kind of diagram `src` declares, or null when its header names no kind drawn here. Reads
     * the header only: `Mermaid::render($src) === null && kind($src) !== null` is a syntax error.
     */
    public static function diagramKind(string $src): ?string
    {
        // The same strip `render()` applies, so the two entry points agree on any source.
        return self::diagramFor(Labels::stripControls($src))?->kind();
    }
}
