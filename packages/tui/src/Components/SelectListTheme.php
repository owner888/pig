<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Style;

/**
 * How a SelectList is painted.
 *
 * Passed in rather than chosen inside the component, so the same list can be a command
 * palette in one place and a file picker in another without either one knowing about a
 * global theme.
 */
final readonly class SelectListTheme
{
    /**
     * @param Closure(string): string $selectedText the whole highlighted row
     * @param Closure(string): string $description  the second column on unselected rows
     * @param Closure(string): string $scrollInfo   the "(3/40)" line
     * @param Closure(string): string $noMatch      shown when the filter matches nothing
     */
    public function __construct(
        public Closure $selectedText,
        public Closure $description,
        public Closure $scrollInfo,
        public Closure $noMatch,
    ) {
    }

    /** A theme that reads on any terminal with sixteen colours. */
    public static function default(): self
    {
        return new self(
            selectedText: Style::cyan(...),
            description: Style::dim(...),
            scrollInfo: Style::dim(...),
            noMatch: Style::dim(...),
        );
    }
}
