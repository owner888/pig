<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component that can take focus and show a hardware cursor — upstream's `Focusable` in `tui.ts`.
 *
 * The renderer sets a public `bool $focused` property on it as focus moves (an interface cannot
 * declare a property before PHP 8.4, so implementers declare `public bool $focused = false`
 * themselves). While focused, the component puts `TUI::CURSOR_MARKER` in its rendered output
 * where the cursor is; the renderer finds the marker, takes it out, and moves the terminal's
 * cursor there — which is where an input method draws what is being composed and its candidates.
 */
interface Focusable
{
}
