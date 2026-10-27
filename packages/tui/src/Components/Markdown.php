<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Latex;
use Pig\Tui\Markdown\Blank;
use Pig\Tui\Markdown\Blockquote;
use Pig\Tui\Markdown\BlockToken;
use Pig\Tui\Markdown\CodeBlock;
use Pig\Tui\Markdown\CodeSpan;
use Pig\Tui\Markdown\Emphasis;
use Pig\Tui\Markdown\Heading;
use Pig\Tui\Markdown\LatexBlock;
use Pig\Tui\Markdown\LatexSpan;
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

    /**
     * Upstream's `cachedTokens`: a parse depends on the text alone, so a new width or theme lays
     * the same tokens out again instead of parsing again. Upstream holds them in a `WeakRef` — a
     * token tree is several times its text, and a long transcript keeps a component per message —
     * so they live through a burst of re-renders and are collected after. A PHP object held only
     * weakly is freed at once, so here every component shares one cache with a cap instead: the
     * messages laid out most recently keep their tokens, and memory stays bounded however long the
     * session.
     *
     * @var array<string, list<\Pig\Tui\Markdown\BlockToken>> by text, least recently used first
     */
    private static array $tokens = [];

    /** How many texts' tokens the shared cache keeps. */
    private const int TOKEN_CACHE_SIZE = 512;

    /** The text this component last put in the shared cache, taken out again when it changes. */
    private ?string $tokensFor = null;

    private readonly MarkdownTheme $theme;

    public function __construct(
        private string $text = '',
        private int $paddingX = 1,
        private readonly int $paddingY = 0,
        ?MarkdownTheme $theme = null,
        private readonly ?DefaultTextStyle $defaultStyle = null,
        /** Render supported LaTeX math expressions as Unicode text — upstream's `renderLatex` option. */
        private readonly bool $renderLatex = true,
    ) {
        // Not a default parameter: a theme is made of closures, and a default value has
        // to be a constant expression.
        $this->theme = $theme ?? MarkdownTheme::default();
    }

    /**
     * Not the text it already has, which is most calls.
     *
     * A streamed answer is re-sent complete on every delta, so every block but the last one is
     * set to exactly what it is already showing — and invalidating there throws away the laid-out
     * lines that `render()` was about to hand back for nothing. Measured over a plain answer:
     * 20.6ms per delta at 2,000 lines, with the whole UI waiting on it.
     *
     * This is a cache, not a rule about what a setter may do: `invalidate()` still forces the
     * layout again for a caller that needs it, and the width is part of the cache key rather than
     * something a setter could know about.
     */
    public function setText(string $text): void
    {
        if ($text === $this->text) {
            return;
        }

        $this->text = $text;
        $this->invalidate();
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Upstream has no setter here — its components make a new `Markdown` when `outputPad`
     * changes. pig's keep theirs, so the pad is settable, and the cache goes with it.
     */
    public function setPaddingX(int $paddingX): void
    {
        if ($paddingX === $this->paddingX) {
            return;
        }

        $this->paddingX = max(0, $paddingX);
        $this->invalidate();
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedLines = null;
        $this->cachedText = null;
        $this->cachedWidth = null;
        // The default style's opening escapes are sampled from its closures, which read the theme
        // when called — so a theme change has to sample them again, or text after an inline span
        // goes back to the old theme's colour.
        $this->stylePrefix = null;
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
        $tokens = $this->tokens();
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
            // Image protocols (Kitty / iTerm2) put tens of kilobytes inline;
            // skip wrapping and padding them to avoid huge CPU overhead and broken sequences.
            if (TerminalImage::isImageLine($line)) {
                $lines[] = $line;

                continue;
            }

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

    /** @return list<\Pig\Tui\Markdown\BlockToken> */
    private function tokens(): array
    {
        $text = $this->text;

        if (isset(self::$tokens[$text])) {
            $tokens = self::$tokens[$text];
            // To the end: the most recently used are the last to go.
            unset(self::$tokens[$text]);

            return self::$tokens[$text] = $tokens;
        }

        // A streaming answer is a new text with every token: its last parse makes way for this one,
        // so one answer holds one place in the cache rather than pushing every other message out.
        if ($this->tokensFor !== null) {
            unset(self::$tokens[$this->tokensFor]);
        }

        if (count(self::$tokens) >= self::TOKEN_CACHE_SIZE) {
            unset(self::$tokens[array_key_first(self::$tokens)]);
        }

        $this->tokensFor = $text;

        return self::$tokens[$text] = Lexer::lex($text);
    }

    /** @internal for tests */
    public static function clearTokenCache(): void
    {
        self::$tokens = [];
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
     * The last block gets no blank: upstream guards every `lines.push("")` with
     * `nextTokenType && …`, and the gap after a message belongs to whoever placed it
     * (`Spacer`, or this component's own `paddingY`). Without that guard a user message
     * drew two empty rows under one line of text where pi draws one.
     *
     * @return list<string>
     */
    private function block(BlockToken $token, int $width, ?BlockToken $next): array
    {
        $lines = match (true) {
            $token instanceof Heading => [$this->heading($token)],
            $token instanceof Paragraph => [$this->inline($token->children)],
            $token instanceof CodeBlock => $this->code($token),
            $token instanceof LatexBlock => $this->latexBlock($token),
            $token instanceof ListBlock => $this->list($token, 0, $width),
            $token instanceof Table => $this->table($token, $width),
            $token instanceof Blockquote => $this->quote($token, $width),
            $token instanceof Rule => [($this->theme->rule)(str_repeat('─', min($width, self::RULE_MAX)))],
            $token instanceof Blank => [''],
            default => [],
        };

        if ($lines === [] || $next === null || $next instanceof Blank || $token instanceof Blank) {
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
                $lines[] = $this->theme->codeBlockIndent . $line;
            }
        } else {
            foreach (explode("\n", $token->code) as $line) {
                $lines[] = $this->theme->codeBlockIndent . ($this->theme->codeBlock)($line);
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

        // "With no room for another border (`│ ` plus at least one content column), render the
        // nested content without one. Otherwise each extra level re-wraps every child line at
        // width 1 and the output grows exponentially with nesting depth" — a line of 45 `>` was
        // 128 rows and 0.6s at 80 columns, and it tripled with every `>` after that.
        if ($width < 3) {
            foreach ($token->children as $index => $child) {
                array_push($lines, ...$this->block($child, $width, $token->children[$index + 1] ?? null));
            }

            return $lines;
        }

        $border = ($this->theme->quoteBorder)('│ ');
        $inner = $width - 2;

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
            // A blank line *between* items, which is what makes a loose list loose. Never before
            // the first or after the last: that spacing is the block's business, not the list's.
            if ($token->loose && $number > 0) {
                $lines[] = '';
            }

            // From the list's own first number, not from one: `3. three` is step three of
            // somebody's procedure, and renumbering it is rewriting what the model said.
            // **Upstream renumbers**: its `renderList` is `${i + 1}. ` and never reads the `start`
            // its own lexer hands it, so a list beginning at three is drawn beginning at one there.
            // Found by running pig's lexer against `marked` over a corpus; the other half of that
            // run is the loose-list note in `Lexer`.
            $bullet = $token->ordered ? ($token->start + $number) . '. ' : '- ';
            $body = $this->listItem($item, $depth, $width);

            if ($body === []) {
                $lines[] = $indent . ($this->theme->listBullet)($bullet);

                continue;
            }

            $lines[] = $indent . ($this->theme->listBullet)($bullet) . array_shift($body);

            foreach ($body as $line) {
                // A continuation lines up under the text, not under the bullet — unless
                // it is a nested list, which brought its own indent.
                //
                // Asked of the leading space, where upstream asks its rendered output
                // `/^\s+\x1b\[36m[-\d]/` — spaces, then **cyan**, then a bullet character. That
                // hard-codes one theme's colour into the layout: a `listBullet` that is not
                // cyan makes upstream miss every nested list and indent it twice. The indent is
                // the thing that was actually put there, so it is the thing to look for.
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
     * **The total is made to equal the budget in both directions**, which is the part upstream
     * gets half right. Flooring each share loses a column or two, and it hands those back; but
     * `max(1, …)` on a column whose share rounded to nothing *adds* to the total, and nothing
     * took that excess away again. So a table with one wide column beside two or three narrow
     * ones came out one or two columns too wide at every ordinary width — and every line of it
     * then met the wrap in `lines()` and broke onto a second row, which is a table that has
     * come apart rather than one that is slightly too wide. Three short columns and a prose
     * column is an everyday shape, and it was broken from width 17 to 115.
     *
     * The loops terminate because the caller has already refused to draw a table narrower than
     * one column per column, so an allocation of 1 each always fits.
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

        // And take back what the `max(1, …)` above added, from whichever column is widest —
        // a column that had to be rounded up to 1 has nothing to give, and the widest one
        // loses the least by giving.
        while (array_sum($widths) > $available) {
            $widest = array_keys($widths, max($widths), true)[0];

            if ($widths[$widest] <= 1) {
                break;
            }

            $widths[$widest]--;
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
                $token instanceof LatexSpan => $this->styledLines(
                    !$token->pending && $this->renderLatex ? (Latex::render($token->text) ?? $token->raw) : $token->raw,
                ),
                $token instanceof Link => $this->link($token) . $resume,
                $token instanceof LineBreak => "\n",
                default => '',
            };
        }

        return $text;
    }

    /**
     * Display math, one drawn line per rendered line; the source as written when it does not
     * render, has not closed yet, or rendering is off.
     *
     * @return list<string>
     */
    private function latexBlock(LatexBlock $token): array
    {
        $rendered = !$token->pending && $this->renderLatex
            ? (Latex::render($token->text, true) ?? self::trimRaw($token->raw))
            : self::trimRaw($token->raw);

        return array_map(fn (string $line): string => $this->styled($line), explode("\n", $rendered));
    }

    /** `raw.trim()` — JavaScript's, which knows more whitespace than PHP's `trim()`. */
    private static function trimRaw(string $raw): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/uD', '', $raw) ?? trim($raw);
    }

    /** Text that may hold line breaks, each line in the default style — upstream's `applyTextWithNewlines`. */
    private function styledLines(string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => $this->styled($line), explode("\n", $text)));
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
