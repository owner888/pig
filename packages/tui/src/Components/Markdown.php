<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Markdown\Blank;
use Pig\Tui\Markdown\Blockquote;
use Pig\Tui\Markdown\BlockToken;
use Pig\Tui\Markdown\CodeBlock;
use Pig\Tui\Markdown\CodeSpan;
use Pig\Tui\Markdown\Emphasis;
use Pig\Tui\Markdown\Heading;
use Pig\Tui\Markdown\InlineToken;
use Pig\Tui\Markdown\Lexer;
use Pig\Tui\Markdown\LineBreak;
use Pig\Tui\Markdown\Link;
use Pig\Tui\Markdown\ListBlock;
use Pig\Tui\Markdown\ListItem;
use Pig\Tui\Markdown\Paragraph;
use Pig\Tui\Markdown\Rule;
use Pig\Tui\Markdown\Strikethrough;
use Pig\Tui\Markdown\Strong;
use Pig\Tui\Markdown\Table;
use Pig\Tui\Markdown\TableCell;
use Pig\Tui\Markdown\Text;
use Pig\Tui\TextWrap;
use Pig\Tui\Width;

/**
 * Markdown, drawn.
 *
 * The whole document is laid out every time the text changes, which sounds wasteful and
 * is not: a streaming answer grows by a token at a time, the renderer only rewrites the
 * lines that differ, and the alternative — keeping a partial parse — would have to cope
 * with a fence that is open now and closed a token later.
 */
final class Markdown implements Component
{
    /** Widest a rule is drawn, however wide the terminal. */
    private const int RULE_MAX = 80;

    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?string $cachedText = null;

    private ?int $cachedWidth = null;

    private ?string $stylePrefix = null;

    private readonly MarkdownTheme $theme;

    public function __construct(
        private string $text = '',
        private readonly int $paddingX = 1,
        private readonly int $paddingY = 0,
        ?MarkdownTheme $theme = null,
        private readonly ?DefaultTextStyle $defaultStyle = null,
    ) {
        // Not a default parameter: a theme is made of closures, and a default value has
        // to be a constant expression.
        $this->theme = $theme ?? MarkdownTheme::default();
    }

    public function setText(string $text): void
    {
        $this->text = $text;
        $this->invalidate();
    }

    public function text(): string
    {
        return $this->text;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedLines = null;
        $this->cachedText = null;
        $this->cachedWidth = null;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->cachedLines !== null && $this->cachedText === $this->text && $this->cachedWidth === $width) {
            return $this->cachedLines;
        }

        $lines = trim($this->text) === '' ? [] : $this->lines($width);

        $this->cachedText = $this->text;
        $this->cachedWidth = $width;
        $this->cachedLines = $lines;

        return $lines;
    }

    /** @return list<string> */
    private function lines(int $width): array
    {
        $contentWidth = max(1, $width - $this->paddingX * 2);
        $tokens = Lexer::lex($this->text);
        $drawn = [];

        foreach ($tokens as $index => $token) {
            foreach ($this->block($token, $contentWidth, $tokens[$index + 1] ?? null) as $line) {
                $drawn[] = $line;
            }
        }

        $margin = str_repeat(' ', $this->paddingX);
        $blank = $this->pad('', $width);
        $lines = array_fill(0, $this->paddingY, $blank);

        foreach ($drawn as $line) {
            // Wrapped last, after styling: the wrapper carries escape codes across its
            // own breaks, and a line styled after wrapping would lose them.
            foreach (TextWrap::wrap($line, $contentWidth) as $wrapped) {
                $lines[] = $this->pad($margin . $wrapped . $margin, $width);
            }
        }

        for ($index = 0; $index < $this->paddingY; $index++) {
            $lines[] = $blank;
        }

        return $lines;
    }

    private function pad(string $line, int $width): string
    {
        $background = $this->defaultStyle?->background;

        if ($background !== null) {
            return Width::background($line, $width, $background);
        }

        return $line . str_repeat(' ', max(0, $width - Width::visible($line)));
    }

    /**
     * One block, and the blank line after it when the document has not got one.
     *
     * @return list<string>
     */
    private function block(BlockToken $token, int $width, ?BlockToken $next): array
    {
        $lines = match (true) {
            $token instanceof Heading => [$this->heading($token)],
            $token instanceof Paragraph => [$this->inline($token->children)],
            $token instanceof CodeBlock => $this->code($token),
            $token instanceof ListBlock => $this->list($token, 0, $width),
            $token instanceof Table => $this->table($token, $width),
            $token instanceof Blockquote => $this->quote($token, $width),
            $token instanceof Rule => [($this->theme->rule)(str_repeat('─', min($width, self::RULE_MAX)))],
            $token instanceof Blank => [''],
            default => [],
        };

        if ($lines === [] || $next instanceof Blank || $token instanceof Blank) {
            return $lines;
        }

        // Lists run into whatever follows them without a gap, the way they read on a page.
        if ($token instanceof ListBlock) {
            return $lines;
        }

        if ($token instanceof Paragraph && $next instanceof ListBlock) {
            return $lines;
        }

        $lines[] = '';

        return $lines;
    }

    private function heading(Heading $token): string
    {
        $text = $this->inline($token->children);
        $bold = $this->theme->bold;

        return match ($token->level) {
            1 => ($this->theme->heading)($bold(($this->theme->underline)($text))),
            2 => ($this->theme->heading)($bold($text)),
            default => ($this->theme->heading)($bold(str_repeat('#', $token->level) . ' ' . $text)),
        };
    }

    /** @return list<string> */
    private function code(CodeBlock $token): array
    {
        $border = $this->theme->codeBlockBorder;
        $lines = [$border('```' . $token->language)];

        if ($this->theme->highlightCode !== null) {
            foreach (($this->theme->highlightCode)($token->code, $token->language) as $line) {
                $lines[] = '  ' . $line;
            }
        } else {
            foreach (explode("\n", $token->code) as $line) {
                $lines[] = '  ' . ($this->theme->codeBlock)($line);
            }
        }

        $lines[] = $border('```');

        return $lines;
    }

    /**
     * A quote, with a rule down its left.
     *
     * Wrapped here rather than left to the caller: the border goes on each line, and a
     * line wrapped after the border was added would have the border on its first row
     * only, leaving the rest of the quote hanging in the margin.
     *
     * @return list<string>
     */
    private function quote(Blockquote $token, int $width): array
    {
        $lines = [];
        $border = ($this->theme->quoteBorder)('│ ');
        $inner = max(1, $width - 2);

        foreach ($token->children as $index => $child) {
            foreach ($this->block($child, $inner, $token->children[$index + 1] ?? null) as $line) {
                foreach (TextWrap::wrap($line, $inner) as $wrapped) {
                    $lines[] = $border . ($this->theme->quote)(($this->theme->italic)($wrapped));
                }
            }
        }

        // A quote's own trailing blank would be a bare `│` with nothing beside it.
        while ($lines !== [] && trim(Ansi::strip($lines[count($lines) - 1])) === '│') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * A list and everything nested under it.
     *
     * @return list<string>
     */
    private function list(ListBlock $token, int $depth, int $width): array
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);

        foreach ($token->items as $number => $item) {
            $bullet = $token->ordered ? ($number + 1) . '. ' : '- ';
            $body = $this->listItem($item, $depth, $width);

            if ($body === []) {
                $lines[] = $indent . ($this->theme->listBullet)($bullet);

                continue;
            }

            $lines[] = $indent . ($this->theme->listBullet)($bullet) . array_shift($body);

            foreach ($body as $line) {
                // A continuation lines up under the text, not under the bullet — unless
                // it is a nested list, which brought its own indent.
                $lines[] = str_starts_with($line, ' ') ? $line : $indent . '  ' . $line;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function listItem(ListItem $item, int $depth, int $width): array
    {
        $lines = [];

        foreach ($item->children as $index => $child) {
            if ($child instanceof ListBlock) {
                foreach ($this->list($child, $depth + 1, $width) as $line) {
                    $lines[] = $line;
                }

                continue;
            }

            if ($child instanceof Blank) {
                continue;
            }

            // The next child is passed so a paragraph immediately above a nested list
            // does not put a blank line between the item and the things under it.
            foreach ($this->block($child, $width, $item->children[$index + 1] ?? null) as $line) {
                $lines[] = $line;
            }
        }

        // The blank a paragraph adds after itself is not wanted inside an item.
        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * A table with borders, its columns shrunk to fit.
     *
     * Below the width where every column could hold one character, the borders would be
     * wider than the content; the source is shown instead, which at least stays readable.
     *
     * @return list<string>
     */
    private function table(Table $token, int $width): array
    {
        $columns = count($token->header);

        if ($columns === 0) {
            return [];
        }

        // "│ " + (n-1) × " │ " + " │"
        $overhead = 3 * $columns + 1;

        if ($width < $overhead + $columns) {
            return TextWrap::wrap($token->raw, $width);
        }

        $widths = $this->columnWidths($token, $width - $overhead);
        $lines = [$this->border('┌', '┬', '┐', $widths)];

        foreach ($this->row($token->header, $widths, bold: true) as $line) {
            $lines[] = $line;
        }

        $lines[] = $this->border('├', '┼', '┤', $widths);

        foreach ($token->rows as $cells) {
            foreach ($this->row($cells, $widths, bold: false) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = $this->border('└', '┴', '┘', $widths);

        return $lines;
    }

    /**
     * How wide each column gets: what it wants, or its share of what there is.
     *
     * @return list<int>
     */
    private function columnWidths(Table $token, int $available): array
    {
        $natural = [];

        foreach ($token->header as $index => $cell) {
            $natural[$index] = Width::visible($this->inline($cell->children));
        }

        foreach ($token->rows as $cells) {
            foreach ($cells as $index => $cell) {
                $natural[$index] = max($natural[$index] ?? 0, Width::visible($this->inline($cell->children)));
            }
        }

        $total = array_sum($natural);

        if ($total <= $available) {
            return array_values($natural);
        }

        $widths = array_map(
            static fn (int $want): int => max(1, (int) floor($want / $total * $available)),
            array_values($natural),
        );

        // Flooring loses a column or two of the budget; hand them back left to right.
        $remaining = $available - array_sum($widths);

        for ($index = 0; $remaining > 0 && $index < count($widths); $index++) {
            $widths[$index]++;
            $remaining--;
        }

        return $widths;
    }

    /**
     * One table row, which is several lines when a cell had to wrap.
     *
     * @param list<TableCell> $cells
     * @param list<int>       $widths
     * @return list<string>
     */
    private function row(array $cells, array $widths, bool $bold): array
    {
        $wrapped = [];
        $height = 1;

        foreach ($cells as $index => $cell) {
            $wrapped[$index] = TextWrap::wrap($this->inline($cell->children), max(1, $widths[$index] ?? 1));
            $height = max($height, count($wrapped[$index]));
        }

        $lines = [];

        for ($line = 0; $line < $height; $line++) {
            $parts = [];

            foreach ($widths as $index => $columnWidth) {
                $text = $wrapped[$index][$line] ?? '';
                $padded = $text . str_repeat(' ', max(0, $columnWidth - Width::visible($text)));
                $parts[] = $bold ? ($this->theme->bold)($padded) : $padded;
            }

            $lines[] = '│ ' . implode(' │ ', $parts) . ' │';
        }

        return $lines;
    }

    /** @param list<int> $widths */
    private function border(string $left, string $join, string $right, array $widths): string
    {
        $cells = array_map(static fn (int $width): string => str_repeat('─', $width), $widths);

        return $left . '─' . implode('─' . $join . '─', $cells) . '─' . $right;
    }

    /**
     * A run of inline tokens, as one styled string.
     *
     * Every styled span is followed by the default style again, because a theme function
     * closes with a reset and the reset takes the surrounding colour with it.
     *
     * @param list<InlineToken> $tokens
     */
    private function inline(array $tokens): string
    {
        $text = '';
        $resume = $this->defaultStylePrefix();

        foreach ($tokens as $token) {
            $text .= match (true) {
                $token instanceof Text => $this->styled($token->text),
                $token instanceof Strong => ($this->theme->bold)($this->inline($token->children)) . $resume,
                $token instanceof Emphasis => ($this->theme->italic)($this->inline($token->children)) . $resume,
                $token instanceof Strikethrough => ($this->theme->strikethrough)($this->inline($token->children)) . $resume,
                $token instanceof CodeSpan => ($this->theme->code)($token->code) . $resume,
                $token instanceof Link => $this->link($token) . $resume,
                $token instanceof LineBreak => "\n",
                default => '',
            };
        }

        return $text;
    }

    private function link(Link $token): string
    {
        $text = ($this->theme->link)(($this->theme->underline)($this->inline($token->children)));

        // A link whose text is its own URL would otherwise be printed twice.
        if ($token->label === $token->href) {
            return $text;
        }

        return $text . ($this->theme->linkUrl)(" ({$token->href})");
    }

    /** Plain text, wearing whatever the block's default style is. */
    private function styled(string $text): string
    {
        $style = $this->defaultStyle;

        if ($style === null) {
            return $text;
        }

        if ($style->colour !== null) {
            $text = ($style->colour)($text);
        }

        // The background is not applied here: it goes on at the padding stage, so it
        // reaches the end of the line instead of stopping where the words do.
        $text = $style->bold ? ($this->theme->bold)($text) : $text;
        $text = $style->italic ? ($this->theme->italic)($text) : $text;
        $text = $style->strikethrough ? ($this->theme->strikethrough)($text) : $text;

        return $style->underline ? ($this->theme->underline)($text) : $text;
    }

    /**
     * The escape codes that put the default style back on.
     *
     * Worked out by styling a marker and keeping whatever was put in front of it, which
     * is the only way to get the opening half of a closure that returns both halves.
     */
    private function defaultStylePrefix(): string
    {
        if ($this->stylePrefix !== null) {
            return $this->stylePrefix;
        }

        if ($this->defaultStyle === null) {
            $this->stylePrefix = '';

            return '';
        }

        $marker = "\x00";
        $styled = $this->styled($marker);
        $at = strpos($styled, $marker);
        $this->stylePrefix = $at === false ? '' : substr($styled, 0, $at);

        return $this->stylePrefix;
    }
}
