<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `Edge`. A head is `none`, `arrow`, `circle`, `cross`, `triangle`, `diamondFill`
 * or `diamondOpen`; a line `solid`, `dotted` or `thick`. `cardFrom` and `cardTo` are the
 * cardinalities (ER crow's feet, class multiplicities), painted at their own end.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
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
        public ?string $cardFrom = null,
        public ?string $cardTo = null,
    ) {
    }
}
