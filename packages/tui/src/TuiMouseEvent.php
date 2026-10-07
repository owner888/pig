<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A normalized, cell-based mouse event; coordinates are zero-based — upstream's `TuiMouseEvent`. */
final readonly class TuiMouseEvent
{
    /**
     * @param 'press'|'release'|'move'|'drag'|'click'|'wheel' $type
     * @param 'left'|'middle'|'right'|'none' $button
     * @param int $x local to the receiving component
     * @param int $y local to the receiving component
     * @param int $screenX absolute terminal column
     * @param int $screenY absolute terminal row
     * @param int $width the receiving component's bounds
     * @param int $height the receiving component's bounds
     * @param int|null $wheelDelta logical lines; negative scrolls up
     * @param int|null $clickCount consecutive clicks, for a click
     */
    public function __construct(
        public string $type,
        public string $button,
        public int $x,
        public int $y,
        public int $screenX,
        public int $screenY,
        public int $width,
        public int $height,
        public bool $shift = false,
        public bool $alt = false,
        public bool $ctrl = false,
        public ?int $wheelDelta = null,
        public ?int $clickCount = null,
    ) {
    }

    /** The same event in another component's coordinates — upstream spreads `{...event, x, y, width, height}`. */
    public function at(int $x, int $y, int $width, int $height): self
    {
        return new self($this->type, $this->button, $x, $y, $this->screenX, $this->screenY, $width, $height, $this->shift, $this->alt, $this->ctrl, $this->wheelDelta, $this->clickCount);
    }
}
