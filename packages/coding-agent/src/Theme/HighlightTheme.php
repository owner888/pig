<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Closure;
use Pig\Tui\Style;

/** What colour each kind of token is drawn in. */
final readonly class HighlightTheme
{
    /**
     * @param Closure(string): string $comment
     * @param Closure(string): string $string
     * @param Closure(string): string $number
     * @param Closure(string): string $keyword
     * @param Closure(string): string $type
     * @param Closure(string): string $function called before its opening bracket
     * @param Closure(string): string $plain    everything else, punctuation included
     */
    public function __construct(
        public Closure $comment,
        public Closure $string,
        public Closure $number,
        public Closure $keyword,
        public Closure $type,
        public Closure $function,
        public Closure $plain,
    ) {
    }

    /** Six of the sixteen colours every terminal has. */
    public static function default(): self
    {
        return new self(
            comment: Style::dim(...),
            string: Style::green(...),
            number: Style::magenta(...),
            keyword: Style::blue(...),
            type: Style::cyan(...),
            function: Style::yellow(...),
            plain: static fn (string $text): string => $text,
        );
    }
}
