<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Kanban;

/**
 * pig's `kanban` (see `Kanban`) as a registry entry; lovely-mermaid draws no kanban.
 */
final class KanbanDiagram implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'kanban';
    }

    #[\Override]
    public function headers(): array
    {
        return ['kanban'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $canvas = Kanban::parse($src)?->layout();

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => [], 'classDefs' => []];
    }
}
