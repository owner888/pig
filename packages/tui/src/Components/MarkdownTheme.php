<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Style;

/** How each kind of markdown is painted. */
final readonly class MarkdownTheme
{
    /**
     * @param Closure(string): string             $heading
     * @param Closure(string): string             $link
     * @param Closure(string): string             $linkUrl
     * @param Closure(string): string             $code            an inline span
     * @param Closure(string): string             $codeBlock
     * @param Closure(string): string             $codeBlockBorder the ``` lines
     * @param Closure(string): string             $quote
     * @param Closure(string): string             $quoteBorder     the │ down the left
     * @param Closure(string): string             $rule
     * @param Closure(string): string             $listBullet
     * @param Closure(string): string             $bold
     * @param Closure(string): string             $italic
     * @param Closure(string): string             $strikethrough
     * @param Closure(string): string             $underline
     * @param Closure(string, string): list<string>|null $highlightCode
     *        given the code and its language, the lines to draw instead of plain ones
     */
    public function __construct(
        public Closure $heading,
        public Closure $link,
        public Closure $linkUrl,
        public Closure $code,
        public Closure $codeBlock,
        public Closure $codeBlockBorder,
        public Closure $quote,
        public Closure $quoteBorder,
        public Closure $rule,
        public Closure $listBullet,
        public Closure $bold,
        public Closure $italic,
        public Closure $strikethrough,
        public Closure $underline,
        public ?Closure $highlightCode = null,
    ) {
    }

    /** A theme that reads on any terminal with sixteen colours. */
    public static function default(): self
    {
        return new self(
            heading: Style::cyan(...),
            link: Style::blue(...),
            linkUrl: Style::dim(...),
            code: Style::yellow(...),
            codeBlock: Style::gray(...),
            codeBlockBorder: Style::dim(...),
            quote: Style::gray(...),
            quoteBorder: Style::dim(...),
            rule: Style::dim(...),
            listBullet: Style::cyan(...),
            bold: Style::bold(...),
            italic: Style::italic(...),
            strikethrough: Style::strikethrough(...),
            underline: Style::underline(...),
        );
    }
}
