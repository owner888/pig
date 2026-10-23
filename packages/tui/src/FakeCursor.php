<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Upstream's fake cursor from `tui.ts`: `FAKE_CURSOR_START`, `FAKE_CURSOR_END` and
 * `renderFakeCursor()`.
 *
 * A component wraps the text it draws as its cursor in two zero-width APC markers instead of
 * inverting it itself; the renderer (`TuiBase::resolveFakeCursors()`) turns them into reverse
 * video, or drops a focused one when the terminal cursor is shown there (`showHardwareCursor`).
 * Code that renders such a component outside a TUI strips them, as it does `TUI::CURSOR_MARKER`.
 */
final class FakeCursor
{
    public const string START = "\x1b_pi:fc\x07";

    public const string END = "\x1b_pi:/fc\x07";

    /** Wrap text in fake cursor markers. */
    public static function renderFakeCursor(string $text): string
    {
        return self::START . $text . self::END;
    }
}
