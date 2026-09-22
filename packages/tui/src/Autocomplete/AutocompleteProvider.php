<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

/**
 * Where the editor gets its suggestions.
 *
 * Deliberately not given the editor: a provider is handed the buffer and told where the
 * cursor is, and hands back a new buffer. That keeps file system access, command tables
 * and anything else an application knows out of the editor entirely.
 */
interface AutocompleteProvider
{
    /**
     * What could be typed here, or null for nothing to offer.
     *
     * @param list<string> $lines
     * @param int          $cursorCol byte offset into $lines[$cursorLine]
     */
    public function suggestions(array $lines, int $cursorLine, int $cursorCol): ?Suggestions;

    /**
     * Put $item into the buffer in place of $prefix.
     *
     * @param list<string> $lines
     */
    public function apply(
        array $lines,
        int $cursorLine,
        int $cursorCol,
        AutocompleteItem $item,
        string $prefix,
    ): Completion;
}
