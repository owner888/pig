<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `StackLayoutNode` in `layout-node.ts`. */
final readonly class StackLayoutNode
{
    /**
     * @param 'vstack'|'hstack' $type
     * @param list<StackLayoutEntry> $entries
     * @param 'stretch'|'start'|'center'|'end' $align
     */
    public function __construct(
        public string $type,
        public array $entries,
        public int $gap,
        public string $align,
    ) {
    }
}
