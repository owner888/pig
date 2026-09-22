<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component that knows where its text cursor is.
 *
 * Drawing a cursor and *having* one are different things. A component paints its own
 * caret as an inverted cell, which is what you see; the terminal has a cursor of its own,
 * which is what an input method follows. Type Chinese or Japanese into a terminal and the
 * composing text and the candidate list appear wherever that cursor is — so if it is left
 * at the bottom of the frame, which is where writing a frame leaves it, the candidates
 * come up over the footer and the text being composed is nowhere near the box it is
 * going into.
 *
 * So a focused component says where its caret is, and `Tui` puts the terminal's cursor
 * there at the end of every frame.
 */
interface Caret
{
    /**
     * Where the caret sits inside this component's own rendered lines.
     *
     * @param int $width the width it would be rendered at
     * @return array{0: int, 1: int}|null row then column, both zero-based and counted in
     *         screen columns, or null when the component has no caret right now
     */
    public function caret(int $width): ?array;
}
