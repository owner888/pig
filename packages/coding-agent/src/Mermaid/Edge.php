<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `Edge`. A head is `none`, `arrow`, `circle`, `cross`, `triangle`, `diamondFill`
 * or `diamondOpen`; a line `solid`, `dotted` or `thick`.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Edge
{
    public function __construct(
        public int $from,
        public int $to,
        public ?string $label,
        public string $headTo,
        public string $headFrom,
        public string $line,
    ) {
    }
}
