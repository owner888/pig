<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Journey;

/**
 * pig's `journey` (see `Journey`) as a registry entry; lovely-mermaid draws no journey.
 */
final class JourneyDiagram implements Diagram
{
    #[\Override]
    public function kind(): string
    {
        return 'journey';
    }

    #[\Override]
    public function headers(): array
    {
        return ['journey'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $canvas = Journey::parse($src)?->layout();

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => [], 'classDefs' => []];
    }
}
