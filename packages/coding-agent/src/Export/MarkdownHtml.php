<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\CodingAgent\Theme\Grammar;
use Pig\CodingAgent\Theme\Highlight;
use Pig\CodingAgent\Theme\HighlightTheme;
use Pig\Tui\Markdown\Blockquote;
use Pig\Tui\Markdown\CodeBlock;
use Pig\Tui\Markdown\CodeSpan;
use Pig\Tui\Markdown\Emphasis;
use Pig\Tui\Markdown\Heading;
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

/**
 * The same markdown the terminal draws, as HTML.
 *
 * Upstream's export ships `marked.min.js` and `highlight.min.js` — 160KB of somebody
 * else's minified JavaScript — and renders in the browser. `marked` is the dependency
 * `Pig\Tui\Markdown` was written to replace, so bringing it back through the export would
 * be the same dependency through a side door. The lexer and the highlighter are already
 * here; this is the second thing that walks their output, and the file it produces needs
 * no JavaScript at all.
 *
 * Upstream: `core/export-html/template.js`, in the browser.
 */
final class MarkdownHtml
{
    /** A block of markdown, rendered. */
    public static function render(string $markdown): string
    {
        return self::blocks(Lexer::lex($markdown));
    }

    /** Text with no markdown in it, safe to put in a page. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * One code block, coloured.
     *
     * The colours come from `Highlight`, told to paint with spans instead of escapes —
     * the seam that already existed so the terminal could be themed.
     *
     * The grammar is checked first, and not for the colours: `Highlight` hands back the
     * code *unchanged* when it has no grammar for the language, which in a terminal is
     * exactly right and on a page is a `<script>` in someone's tool output. So an
     * unknown language is escaped here instead of being handed through.
     */
    public static function code(string $code, string $language): string
    {
        $lines = Grammar::for($language) === null
            ? array_map(self::escape(...), explode("\n", $code))
            : Highlight::lines($code, $language, self::spans());

        $class = $language === '' ? '' : ' class="lang-' . self::escape($language) . '"';

        return "<pre{$class}><code>" . implode("\n", $lines) . '</code></pre>';
    }

    /** A highlight theme that writes spans rather than escape sequences. */
    private static function spans(): HighlightTheme
    {
        $span = static fn (string $kind): \Closure => static fn (string $text): string
            => '<span class="hl-' . $kind . '">' . self::escape($text) . '</span>';

        return new HighlightTheme(
            comment: $span('comment'),
            string: $span('string'),
            number: $span('number'),
            keyword: $span('keyword'),
            type: $span('type'),
            function: $span('function'),
            variable: $span('variable'),
            // Escaped but not wrapped: a span around every bracket would triple the
            // size of the file for a colour the stylesheet already gives it.
            plain: self::escape(...),
        );
    }

    /** @param list<mixed> $tokens */
    private static function blocks(array $tokens): string
    {
        $html = '';

        foreach ($tokens as $token) {
            $html .= self::block($token);
        }

        return $html;
    }

    private static function block(mixed $token): string
    {
        return match (true) {
            $token instanceof Heading => self::heading($token),
            $token instanceof Paragraph => '<p>' . self::inlines($token->children) . "</p>\n",
            $token instanceof CodeBlock => self::code($token->code, $token->language) . "\n",
            $token instanceof ListBlock => self::list($token),
            $token instanceof Blockquote => '<blockquote>' . self::blocks($token->children) . "</blockquote>\n",
            $token instanceof Table => self::table($token),
            $token instanceof Rule => "<hr>\n",
            // A blank line is a separator the lexer kept; HTML has its own spacing.
            default => '',
        };
    }

    private static function heading(Heading $token): string
    {
        $level = max(1, min(6, $token->level));

        return "<h{$level}>" . self::inlines($token->children) . "</h{$level}>\n";
    }

    private static function list(ListBlock $token): string
    {
        $tag = $token->ordered ? 'ol' : 'ul';
        $html = "<{$tag}>\n";

        foreach ($token->items as $item) {
            $html .= '<li>' . ($item instanceof ListItem ? self::items($item) : '') . "</li>\n";
        }

        return $html . "</{$tag}>\n";
    }

    /**
     * A list item's blocks, with a lone paragraph unwrapped.
     *
     * `<li><p>one</p></li>` renders with a blank line above and below it in every
     * browser, which turns a tight list into a loose one for no reason anyone asked for.
     */
    private static function items(ListItem $item): string
    {
        if (count($item->children) === 1 && $item->children[0] instanceof Paragraph) {
            return self::inlines($item->children[0]->children);
        }

        return self::blocks($item->children);
    }

    private static function table(Table $token): string
    {
        $html = "<table>\n<thead>\n<tr>";

        foreach ($token->header as $cell) {
            $html .= '<th>' . ($cell instanceof TableCell ? self::inlines($cell->children) : '') . '</th>';
        }

        $html .= "</tr>\n</thead>\n<tbody>\n";

        foreach ($token->rows as $row) {
            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>' . ($cell instanceof TableCell ? self::inlines($cell->children) : '') . '</td>';
            }

            $html .= "</tr>\n";
        }

        return $html . "</tbody>\n</table>\n";
    }

    /** @param list<mixed> $tokens */
    private static function inlines(array $tokens): string
    {
        $html = '';

        foreach ($tokens as $token) {
            $html .= match (true) {
                $token instanceof Text => self::escape($token->text),
                $token instanceof CodeSpan => '<code>' . self::escape($token->code) . '</code>',
                $token instanceof Strong => '<strong>' . self::inlines($token->children) . '</strong>',
                $token instanceof Emphasis => '<em>' . self::inlines($token->children) . '</em>',
                $token instanceof Strikethrough => '<del>' . self::inlines($token->children) . '</del>',
                $token instanceof Link => self::link($token),
                $token instanceof LineBreak => "<br>\n",
                default => '',
            };
        }

        return $html;
    }

    private static function link(Link $token): string
    {
        // Only the schemes a document should be able to name. A `javascript:` href in an
        // exported transcript is a script the model wrote, running when someone opens it.
        $safe = preg_match('#^(https?|mailto|ftp)://|^mailto:#i', $token->href) === 1;
        $label = self::inlines($token->children);

        return $safe
            ? '<a href="' . self::escape($token->href) . '" rel="noreferrer noopener">' . $label . '</a>'
            : $label . ' <span class="href">(' . self::escape($token->href) . ')</span>';
    }
}
