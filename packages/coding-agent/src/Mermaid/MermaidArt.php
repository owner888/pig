<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `MermaidArt`: a rendered diagram. `plain[i]` and `styled[i]` are the same row —
 * `plain` right-trimmed, `styled` keeping the runs needed to colour it. `width` is the columns the
 * widest row needs.
 *
 * `classDefs` are the diagram's `classDef` declarations, parsed: `classDef warning
 * fill:#f96,stroke:#333` is `['warning' => ['fill' => '#f96', 'stroke' => '#333']]`.
 *
 * `warnings` lists source that could not be read and was dropped, and any size-cap truncation:
 * advisory, never a reason to withhold the art.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final readonly class MermaidArt
{
    /**
     * @param list<string> $plain
     * @param list<list<Span>> $styled
     * @param array<string, array<string, string>> $classDefs
     * @param list<string> $warnings
     */
    public function __construct(
        public array $plain,
        public array $styled,
        public int $width,
        public array $classDefs = [],
        public array $warnings = [],
    ) {
    }
}
