<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * Markdown, as much of it as a terminal can draw.
 *
 * Headings, paragraphs, fenced and indented code, lists with nesting, block quotes,
 * rules, and GFM tables and strikethrough. Not CommonMark: no reference links, no setext
 * headings, no HTML parsing, no loose/tight list distinction. Anything it does not
 * recognise stays a paragraph and is drawn as the text it was, which is the behaviour
 * that matters — this renders whatever a model wrote, and being wrong should look like
 * plain text rather than like mangled markup.
 */
final class Lexer
{
    /**
     * A tab becomes **four** spaces, and the number is load-bearing.
     *
     * Four is what CommonMark says a tab advances to, and it is what `indentedCode()` below
     * looks for — so a tab-indented line is an indented code block here, which is what it is
     * in every other markdown reader. Upstream writes three, and says why: it is normalising
     * for *display width*, because `marked` never sees the tab. Anyone aligning this with
     * upstream's three would quietly turn every tab-indented code block back into a paragraph,
     * which is why it says so here rather than in a commit message.
     *
     * @return list<BlockToken>
     */
    public static function lex(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown));

        return self::blocks($lines);
    }

    /**
     * @param list<string> $lines
     * @return list<BlockToken>
     */
    private static function blocks(array $lines): array
    {
        $tokens = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $tokens[] = new Blank();
                $index++;

                continue;
            }

            $token = self::fence($lines, $index)
                ?? self::heading($lines, $index)
                ?? self::rule($lines, $index)
                ?? self::quote($lines, $index)
                ?? self::table($lines, $index)
                ?? self::listBlock($lines, $index)
                ?? self::indentedCode($lines, $index)
                ?? self::paragraph($lines, $index);

            [$block, $consumed] = $token;
            $tokens[] = $block;
            $index += $consumed;
        }

        return $tokens;
    }

    /**
     * ``` or ~~~, with an optional language.
     *
     * An unterminated fence runs to the end of the input rather than falling back to a
     * paragraph: a model that stopped mid-block still wrote code, and showing it as code
     * is closer to the truth than showing the backticks.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function fence(array $lines, int $index): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([^\s`]*)/', $lines[$index], $match) !== 1) {
            return null;
        }

        $marker = $match[1][0];
        $length = strlen($match[1]);
        $code = [];
        $cursor = $index + 1;
        $count = count($lines);

        while ($cursor < $count) {
            if (preg_match('/^ {0,3}' . preg_quote($marker, '/') . '{' . $length . ',}\s*$/', $lines[$cursor]) === 1) {
                $cursor++;

                break;
            }

            $code[] = $lines[$cursor];
            $cursor++;
        }

        return [new CodeBlock(implode("\n", $code), $match[2]), $cursor - $index];
    }

    /**
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function heading(array $lines, int $index): ?array
    {
        if (preg_match('/^ {0,3}(#{1,6})(?:\s+(.*?))?\s*#*\s*$/', $lines[$index], $match) !== 1) {
            return null;
        }

        return [new Heading(strlen($match[1]), Inline::tokenize($match[2] ?? '')), 1];
    }

    /**
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function rule(array $lines, int $index): ?array
    {
        if (preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $lines[$index]) !== 1) {
            return null;
        }

        return [new Rule(), 1];
    }

    /**
     * `>` lines, lexed again as blocks so a quote can hold anything.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function quote(array $lines, int $index): ?array
    {
        if (preg_match('/^ {0,3}>/', $lines[$index]) !== 1) {
            return null;
        }

        $inner = [];
        $cursor = $index;
        $count = count($lines);

        while ($cursor < $count && preg_match('/^ {0,3}>/', $lines[$cursor]) === 1) {
            $inner[] = preg_replace('/^ {0,3}> ?/', '', $lines[$cursor]) ?? '';
            $cursor++;
        }

        return [new Blockquote(self::blocks($inner)), $cursor - $index];
    }

    /**
     * A GFM table: a header row, a row of dashes, then the body.
     *
     * The dashes are what identifies it. A line with pipes in it and no delimiter row
     * under it is prose about pipes.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function table(array $lines, int $index): ?array
    {
        $delimiter = $lines[$index + 1] ?? '';

        if (!str_contains($lines[$index], '|')) {
            return null;
        }

        if (preg_match('/^\s*\|?(\s*:?-+:?\s*\|)*\s*:?-+:?\s*\|?\s*$/', $delimiter) !== 1) {
            return null;
        }

        $header = self::cells($lines[$index]);
        $rows = [];
        $raw = [$lines[$index], $delimiter];
        $cursor = $index + 2;
        $count = count($lines);

        while ($cursor < $count && trim($lines[$cursor]) !== '' && str_contains($lines[$cursor], '|')) {
            $rows[] = self::cells($lines[$cursor], count($header));
            $raw[] = $lines[$cursor];
            $cursor++;
        }

        return [new Table($header, $rows, implode("\n", $raw)), $cursor - $index];
    }

    /**
     * @return list<TableCell>
     */
    private static function cells(string $line, ?int $columns = null): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\||\|$/', '', $trimmed) ?? $trimmed;
        $cells = array_map(
            static fn (string $cell): TableCell => new TableCell(Inline::tokenize(trim($cell))),
            explode('|', $trimmed),
        );

        if ($columns === null) {
            return $cells;
        }

        // A short row is padded and a long one cut: the header decides how wide the
        // table is, and a row that disagrees would otherwise break the borders.
        $cells = array_slice($cells, 0, $columns);

        while (count($cells) < $columns) {
            $cells[] = new TableCell([]);
        }

        return $cells;
    }

    /**
     * A run of list items, with nesting by indentation.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function listBlock(array $lines, int $index): ?array
    {
        $first = self::itemMarker($lines[$index]);

        if ($first === null) {
            return null;
        }

        $indent = $first[0];
        $ordered = $first[2];
        $items = [];
        $current = null;
        $cursor = $index;
        $count = count($lines);

        while ($cursor < $count) {
            $line = $lines[$cursor];
            $marker = self::itemMarker($line);

            if ($marker !== null && $marker[0] === $indent && $marker[2] === $ordered) {
                if ($current !== null) {
                    $items[] = new ListItem(self::blocks($current));
                }

                $current = [$marker[1]];
                $cursor++;

                continue;
            }

            if ($current === null) {
                break;
            }

            // A blank line only ends the list if the line after it is not indented under
            // the item — that is what lets an item hold two paragraphs.
            if (trim($line) === '') {
                $next = $lines[$cursor + 1] ?? '';

                if (trim($next) === '' || self::leadingSpaces($next) <= $indent) {
                    break;
                }

                $current[] = '';
                $cursor++;

                continue;
            }

            if (self::leadingSpaces($line) <= $indent && $marker === null) {
                // A less-indented line that is not an item ends the list. A lazy
                // continuation would be ambiguous with the next paragraph.
                break;
            }

            $current[] = substr($line, min(self::leadingSpaces($line), $indent + 2));
            $cursor++;
        }

        if ($current !== null) {
            $items[] = new ListItem(self::blocks($current));
        }

        return $items === [] ? null : [new ListBlock($items, $ordered), $cursor - $index];
    }

    /**
     * Whether a line starts an item, and with what.
     *
     * @return array{0: int, 1: string, 2: bool}|null indent, the text after the marker, ordered
     */
    private static function itemMarker(string $line): ?array
    {
        if (preg_match('/^(\s*)([-*+]|\d{1,9}[.)])(\s+)(.*)$/', $line, $match) !== 1) {
            return null;
        }

        // `- - -` is a rule, not a one-item list holding a rule.
        if (preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $line) === 1) {
            return null;
        }

        return [strlen($match[1]), $match[4], !in_array($match[2], ['-', '*', '+'], true)];
    }

    private static function leadingSpaces(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * Four spaces of indent, the old way of writing a code block.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}|null
     */
    private static function indentedCode(array $lines, int $index): ?array
    {
        if (!str_starts_with($lines[$index], '    ')) {
            return null;
        }

        $code = [];
        $cursor = $index;
        $count = count($lines);

        while ($cursor < $count && (str_starts_with($lines[$cursor], '    ') || trim($lines[$cursor]) === '')) {
            $code[] = substr($lines[$cursor], 4);
            $cursor++;
        }

        // Trailing blanks belong to the document, not to the block.
        while ($code !== [] && trim($code[count($code) - 1]) === '') {
            array_pop($code);
            $cursor--;
        }

        return [new CodeBlock(implode("\n", $code)), $cursor - $index];
    }

    /**
     * Everything else, up to the next blank line or the next block that starts one.
     *
     * @param list<string> $lines
     * @return array{0: BlockToken, 1: int}
     */
    private static function paragraph(array $lines, int $index): array
    {
        $text = [];
        $cursor = $index;
        $count = count($lines);

        while ($cursor < $count && trim($lines[$cursor]) !== '') {
            if ($cursor > $index && self::interrupts($lines, $cursor)) {
                break;
            }

            $text[] = $lines[$cursor];
            $cursor++;
        }

        return [new Paragraph(Inline::tokenize(implode("\n", $text))), $cursor - $index];
    }

    /**
     * Whether a line inside a paragraph is really the start of something else.
     *
     * @param list<string> $lines
     */
    private static function interrupts(array $lines, int $index): bool
    {
        $line = $lines[$index];

        return preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1
            || preg_match('/^ {0,3}#{1,6}(\s|$)/', $line) === 1
            || preg_match('/^ {0,3}>/', $line) === 1
            || preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $line) === 1
            || self::itemMarker($line) !== null;
    }
}
