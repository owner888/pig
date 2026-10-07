<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/** Upstream's `TuiAltScreenOptions` in `tui-alt-screen.ts`, the parts pig has so far. */
final readonly class TuiAltScreenOptions
{
    /**
     * @param int|'auto' $wheelScrollLines logical lines moved for each wheel event; `'auto'` accelerates fast spins. Alt+wheel moves five times as far.
     * @param bool $mouse capture mouse events for viewport scrolling and application-owned text selection
     * @param (Closure(): string)|null $scrollToEndIndicator a clickable jump-to-end label, centred on the last row of a follow-end primary scroll view while it is scrolled away from its end
     * @param bool $copyOnSelect copy selected text to the clipboard on mouse release
     * @param (Closure(string): (bool|string))|null $copySelection copy to the system clipboard: true on success, an error message, or false; without one, OSC 52
     */
    public function __construct(
        public int|string $wheelScrollLines = 1,
        public bool $mouse = true,
        public ?Closure $scrollToEndIndicator = null,
        public bool $copyOnSelect = true,
        public ?Closure $copySelection = null,
    ) {
    }
}
