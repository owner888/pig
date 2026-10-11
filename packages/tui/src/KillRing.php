<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Ring buffer for Emacs-style kill and yank — upstream's `kill-ring.ts`.
 *
 * What `ctrl+k`, `ctrl+u`, `ctrl+w` and `alt+d` delete lands here; `ctrl+y` (`tui.editor.yank`)
 * puts the newest entry back and `alt+y` (`tui.editor.yankPop`) swaps it for the one before.
 * Consecutive kills accumulate into one entry, prepended for a backward deletion and appended
 * for a forward one, so `ctrl+w ctrl+w` yanks back as the two words in their order.
 */
final class KillRing
{
    /** @var list<string> oldest first */
    private array $ring = [];

    public function push(string $text, bool $prepend, bool $accumulate = false): void
    {
        if ($text === '') {
            return;
        }

        if ($accumulate && $this->ring !== []) {
            $last = array_pop($this->ring);
            $this->ring[] = $prepend ? $text . $last : $last . $text;

            return;
        }

        $this->ring[] = $text;
    }

    /** The most recent entry, without taking it. */
    public function peek(): ?string
    {
        return $this->ring === [] ? null : $this->ring[count($this->ring) - 1];
    }

    /** Move the last entry to the front, for yank-pop. */
    public function rotate(): void
    {
        if (count($this->ring) > 1) {
            array_unshift($this->ring, array_pop($this->ring));
        }
    }

    public function length(): int
    {
        return count($this->ring);
    }
}
