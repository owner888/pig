<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Canvas;

/**
 * lovely-mermaid's `Diagram`: one supported diagram type — its kind, the header keywords that
 * declare it (lowercased, matched exactly) and its renderer.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
interface Diagram
{
    public function kind(): string;

    /** @return list<string> */
    public function headers(): array;

    /**
     * Parsed and laid out; null when nothing was drawn.
     *
     * @return array{canvas: Canvas, warnings: list<string>, classDefs: array<string, array<string, string>>}|null
     */
    public function render(string $src): ?array;
}
