<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Theme\Palette;
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
     * Upstream's `createInteractiveTui()`. `onRightClickPaste` is not passed: upstream enables it
     * on Windows only, where pig's terminal does not run.
     *
     * @param 'regular'|'fullscreen'|string $tuiMode
     * @param Closure(): string $scrollToEndIndicator
     * @param Closure(): Palette $palette the current one; `/theme` replaces it while the renderer lives
     * @param Closure(string): void $openUrl
     * @param int|'auto' $fullscreenWheelScrollLines
     */
    public static function createInteractiveTui(
        Terminal $terminal,
        string $tuiMode,
        bool $showHardwareCursor,
        Clipboard $clipboard,
        Closure $scrollToEndIndicator,
        Closure $palette,
        Closure $openUrl,
        bool $fullscreenCopyOnSelect = true,
        int|string $fullscreenWheelScrollLines = 'auto',
    ): TuiBase {
        if ($tuiMode !== 'fullscreen') {
            return new TuiMainScreen($terminal, $showHardwareCursor);
        }

        $styleSearchMatch = static fn (string $text): string => $palette()->bg('searchMatchBg', $palette()->fg('searchMatchText', $text));

        return new TuiAltScreen($terminal, $showHardwareCursor, options: new TuiAltScreenOptions(
            wheelScrollLines: $fullscreenWheelScrollLines,
            scrollToEndIndicator: $scrollToEndIndicator,
            copyOnSelect: $fullscreenCopyOnSelect,
            copySelection: static fn (string $text): bool => $clipboard->write($text),
            searchMatchStyle: static fn (string $text): string => "\x1b[4m" . $styleSearchMatch($text) . "\x1b[24m",
            searchCurrentMatchStyle: static fn (string $text): string => "\x1b[1m\x1b[7m" . $styleSearchMatch($text) . "\x1b[27m\x1b[22m",
            searchNavigationButtonStyle: static fn (string $text, bool $hovered): string => $hovered ? "\x1b[4m{$text}\x1b[24m" : $text,
            openUrl: $openUrl,
        ));
    }
}
