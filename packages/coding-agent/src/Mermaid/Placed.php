<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `Placed`: a box's position and size on the canvas, its centre, and its rank.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Placed
{
    public function __construct(
        public int $x,
        public int $y,
        public int $w,
        public int $h,
        public int $cx,
        public int $cy,
        public int $rank,
    ) {
    }
}
