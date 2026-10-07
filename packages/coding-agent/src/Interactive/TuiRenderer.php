<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Component;
use Pig\Tui\OverlayHandle;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TUI;
use Pig\Tui\TuiStopOptions;
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

    /**
     * Stable reference for components while `InteractiveMode` replaces the active renderer —
     * upstream's `createInteractiveTuiReference()`, a `Proxy` there. Every call goes to whatever
     * `$getTui` answers at that moment; properties (`mode`, `terminal`, `onDebug`) and methods the
     * interface does not name are forwarded by the magic methods, as the proxy's `get`/`set` do.
     *
     * @param Closure(): TuiBase $getTui
     */
    public static function createInteractiveTuiReference(Closure $getTui): TUI
    {
        return new class ($getTui) implements TUI {
            /** @param Closure(): TuiBase $getTui */
            public function __construct(private readonly Closure $getTui)
            {
            }

            private function tui(): TuiBase
            {
                return ($this->getTui)();
            }

            public function __get(string $name): mixed
            {
                return $this->tui()->{$name};
            }

            public function __set(string $name, mixed $value): void
            {
                $this->tui()->{$name} = $value;
            }

            public function __isset(string $name): bool
            {
                return isset($this->tui()->{$name});
            }

            /** @param list<mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                return $this->tui()->{$name}(...$arguments);
            }

            #[\Override]
            public function render(int $width): array
            {
                return $this->tui()->render($width);
            }

            #[\Override]
            public function invalidate(): void
            {
                $this->tui()->invalidate();
            }

            #[\Override]
            public function addChild(Component $child): void
            {
                $this->tui()->addChild($child);
            }

            #[\Override]
            public function removeChild(Component $child): void
            {
                $this->tui()->removeChild($child);
            }

            #[\Override]
            public function clear(): void
            {
                $this->tui()->clear();
            }

            #[\Override]
            public function getFocusedComponent(): ?Component
            {
                return $this->tui()->getFocusedComponent();
            }

            #[\Override]
            public function setFocus(?Component $component): void
            {
                $this->tui()->setFocus($component);
            }

            #[\Override]
            public function showOverlay(Component $component, ?OverlayOptions $options = null): OverlayHandle
            {
                return $this->tui()->showOverlay($component, $options);
            }

            #[\Override]
            public function hideOverlay(): void
            {
                $this->tui()->hideOverlay();
            }

            #[\Override]
            public function hasOverlay(): bool
            {
                return $this->tui()->hasOverlay();
            }

            #[\Override]
            public function start(): void
            {
                $this->tui()->start();
            }

            #[\Override]
            public function stop(?TuiStopOptions $options = null): void
            {
                $this->tui()->stop($options);
            }

            #[\Override]
            public function renderNow(bool $force = false): void
            {
                $this->tui()->renderNow($force);
            }

            #[\Override]
            public function requestRender(bool $force = false): void
            {
                $this->tui()->requestRender($force);
            }

            #[\Override]
            public function getShowHardwareCursor(): bool
            {
                return $this->tui()->getShowHardwareCursor();
            }

            #[\Override]
            public function setShowHardwareCursor(bool $enabled): void
            {
                $this->tui()->setShowHardwareCursor($enabled);
            }

            #[\Override]
            public function getClearOnShrink(): bool
            {
                return $this->tui()->getClearOnShrink();
            }

            #[\Override]
            public function setClearOnShrink(bool $enabled): void
            {
                $this->tui()->setClearOnShrink($enabled);
            }

            #[\Override]
            public function addInputListener(Closure $listener): Closure
            {
                return $this->tui()->addInputListener($listener);
            }

            #[\Override]
            public function removeInputListener(Closure $listener): void
            {
                $this->tui()->removeInputListener($listener);
            }

            #[\Override]
            public function onTerminalColorSchemeChange(Closure $listener): Closure
            {
                return $this->tui()->onTerminalColorSchemeChange($listener);
            }

            #[\Override]
            public function setTerminalColorSchemeNotifications(bool $enabled): void
            {
                $this->tui()->setTerminalColorSchemeNotifications($enabled);
            }

            #[\Override]
            public function queryTerminalColors(int $timeoutMs, ?Closure $onLateReply = null): \Pig\Async\Future
            {
                return $this->tui()->queryTerminalColors($timeoutMs, $onLateReply);
            }
        };
    }
}
