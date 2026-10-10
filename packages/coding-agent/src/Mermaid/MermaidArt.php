<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `MermaidArt`: a rendered diagram. `plain[i]` and `styled[i]` are the same row —
 * `plain` right-trimmed, `styled` keeping the runs needed to colour it. `width` is the columns the
 * widest row needs. `warnings` lists flowchart source that could not be read and was dropped:
 * advisory, never a reason to withhold the art.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final readonly class MermaidArt
{
    /**
     * @param list<string> $plain
     * @param list<list<Span>> $styled
     * @param list<string> $warnings
     */
    public function __construct(
        public array $plain,
        public array $styled,
        public int $width,
        public array $warnings = [],
    ) {
    }
}
