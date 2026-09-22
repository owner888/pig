<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Style;

/** How an Editor is painted: its rules, and the list its completions appear in. */
final readonly class EditorTheme
{
    /** @param Closure(string): string $border the rule above and below the text */
    public function __construct(
        public Closure $border,
        public SelectListTheme $selectList,
    ) {
    }

    public static function default(): self
    {
        return new self(Style::dim(...), SelectListTheme::default());
    }
}
