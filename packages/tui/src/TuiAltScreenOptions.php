<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/** Upstream's `TuiAltScreenOptions` in `tui-alt-screen.ts`, except `copySelection`, which answers synchronously here. */
final readonly class TuiAltScreenOptions
{
    /**
     * @param int|'auto' $wheelScrollLines logical lines moved for each wheel event; `'auto'` accelerates fast spins. Alt+wheel moves five times as far.
     * @param bool $mouse capture mouse events for viewport scrolling and application-owned text selection
     * @param (Closure(): string)|null $scrollToEndIndicator a clickable jump-to-end label, centred on the last row of a follow-end primary scroll view while it is scrolled away from its end
     * @param bool $copyOnSelect copy selected text to the clipboard on mouse release
     * @param (Closure(string): (bool|string))|null $copySelection copy to the system clipboard: true on success, an error message, or false; without one, OSC 52
     * @param (Closure(string): string)|null $searchMatchStyle style a non-current transcript search match
     * @param (Closure(string): string)|null $searchCurrentMatchStyle style the current transcript search match
     * @param (Closure(string, bool): string)|null $searchNavigationButtonStyle style a transcript search navigation button
     * @param (Closure(string): void)|null $openUrl open an OSC 8 hyperlink activated with a primary-button click; reports its own failures
     * @param (Closure(): void)|null $onRightClickPaste an unmodified secondary-button press pastes the clipboard (Windows only, as upstream)
     */
    public function __construct(
        public int|string $wheelScrollLines = 1,
        public bool $mouse = true,
        public ?Closure $scrollToEndIndicator = null,
        public bool $copyOnSelect = true,
        public ?Closure $copySelection = null,
        public ?Closure $searchMatchStyle = null,
        public ?Closure $searchCurrentMatchStyle = null,
        public ?Closure $searchNavigationButtonStyle = null,
        public ?Closure $openUrl = null,
        public ?Closure $onRightClickPaste = null,
    ) {
    }
}
