<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * The terminal, as everything above it needs to see it.
 *
 * Narrow on purpose: an interface this small can be implemented by a test double that
 * records its output, which is the only way to assert what the differential renderer
 * actually emitted.
 */
interface Terminal
{
    /**
     * Take over the terminal and start delivering input.
     *
     * @param Closure(string): void $onInput  raw bytes, escape sequences included
     * @param Closure(): void       $onResize the window changed size
     */
    public function start(Closure $onInput, Closure $onResize): void;

    /** Hand the terminal back in the state it was found in. */
    public function stop(): void;

    public function write(string $data): void;

    public function columns(): int;

    public function rows(): int;

    /** Move the cursor up (negative) or down (positive) from where it is. */
    public function moveBy(int $lines): void;

    public function hideCursor(): void;

    public function showCursor(): void;

    /** Clear the line the cursor is on. */
    public function clearLine(): void;

    /** Clear from the cursor to the end of the screen. */
    public function clearFromCursor(): void;

    /** Clear the screen and move to the top left. */
    public function clearScreen(): void;

    public function setTitle(string $title): void;
}
