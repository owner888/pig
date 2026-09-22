<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Anything that can draw itself as lines of text.
 *
 * Components return lines rather than writing to the terminal, which is what makes the
 * differential renderer possible: it can compare this frame's lines against the last and
 * redraw only what moved.
 */
interface Component
{
    /**
     * Draw at the given width.
     *
     * Every line returned must be at most $width columns wide once escape codes are
     * discounted. A wider line makes the terminal wrap where the renderer does not
     * expect it to, and every cursor move after that is off by a line.
     *
     * @return list<string>
     */
    public function render(int $width): array;

    /** Throw away anything cached about how this looked. */
    public function invalidate(): void;
}
