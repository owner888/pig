<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Where a dispatched event landed and how to map later events to it — upstream's `TuiMouseDispatchTarget`. */
final readonly class TuiMouseDispatchTarget
{
    public function __construct(
        public Component $component,
        public int $originX,
        public int $originY,
        public int $width,
        public int $height,
    ) {
    }
}
