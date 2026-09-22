<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * A component that can hold focus and read the keyboard.
 *
 * Separate from `Component` because most components never take input: upstream marks this
 * with an optional method, which PHP has no equivalent of, and a second interface says the
 * same thing without making every static text block claim it handles keys.
 */
interface InputHandler
{
    /** Raw bytes from the terminal — one keystroke, an escape sequence, or a whole paste. */
    public function handleInput(string $data): void;
}
