<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * What every renderer offers — upstream's `TUI` interface in `tui.ts`.
 *
 * Two implementations, as upstream has them: `TuiMainScreen` draws into the normal scrollback
 * (`regular` mode) and `TuiAltScreen` owns the alternate screen with its own scrolling viewport
 * (`fullscreen` mode). Both extend `TuiBase`, which holds what they share. Upstream's `mode`,
 * `terminal` and `onDebug` are properties on `TuiBase`; an interface cannot declare them here.
 */
interface TUI extends Component
{
    public function addChild(Component $child): void;

    public function removeChild(Component $child): void;

    public function clear(): void;

    public function getFocusedComponent(): ?Component;

    public function setFocus(?Component $component): void;

    public function start(): void;

    public function stop(?TuiStopOptions $options = null): void;

    public function renderNow(bool $force = false): void;

    public function requestRender(bool $force = false): void;

    /**
     * @param Closure(string): (bool|array{consume?: bool, data?: string}|null) $listener
     * @return Closure(): void call it to stop listening
     */
    public function addInputListener(Closure $listener): Closure;

    /** @param Closure(string): (bool|array{consume?: bool, data?: string}|null) $listener */
    public function removeInputListener(Closure $listener): void;
}
