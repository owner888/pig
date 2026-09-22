<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

/**
 * One drawn row of the editor.
 *
 * A logical line wider than the terminal becomes several of these, and at most one of them
 * carries the cursor — hence `cursorPos` being null on the rest rather than a sentinel
 * column that some later arithmetic could mistake for a real one.
 *
 * @internal
 */
final readonly class LayoutLine
{
    /** @param int|null $cursorPos byte offset into $text, or null when the cursor is elsewhere */
    public function __construct(
        public string $text,
        public ?int $cursorPos = null,
    ) {
    }
}
