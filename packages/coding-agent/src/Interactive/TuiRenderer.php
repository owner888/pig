<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Terminal;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\TuiBase;
use Pig\Tui\TuiMainScreen;

/**
 * Which renderer the interactive mode draws with — upstream's `modes/interactive/tui-renderer.ts`.
 */
final class TuiRenderer
{
    /**
     * @param 'regular'|'fullscreen'|string $tuiMode
     * @param Closure(): string $scrollToEndIndicator
     */
    public static function createInteractiveTui(Terminal $terminal, string $tuiMode, Clipboard $clipboard, Closure $scrollToEndIndicator): TuiBase
    {
        if ($tuiMode !== 'fullscreen') {
            return new TuiMainScreen($terminal);
        }

        return new TuiAltScreen($terminal, new TuiAltScreenOptions(
            wheelScrollLines: 'auto',
            scrollToEndIndicator: $scrollToEndIndicator,
            copySelection: static fn (string $text): bool => $clipboard->write($text),
        ));
    }
}
