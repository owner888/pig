<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `LayoutRect`. Mutable: a scrolled box is translated after it is laid out. */
final class LayoutRect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }

    public function intersect(self $other): self
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);
        $right = min($this->x + $this->width, $other->x + $other->width);
        $bottom = min($this->y + $this->height, $other->y + $other->height);

        return new self($x, $y, max(0, $right - $x), max(0, $bottom - $y));
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->width && $y >= $this->y && $y < $this->y + $this->height;
    }
}
