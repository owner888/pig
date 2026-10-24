<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * Where math starts and ends in Markdown — the tokenizers of upstream's `markdown.ts`
 * (`tokenizeInlineLatex`, `tokenizeBlockLatex` and their helpers), which it hands to `marked` as
 * extensions. `Lexer` tries the block one before anything else at the start of a block, and lets
 * it end a paragraph; `Inline` tries the inline one at every `$`, `\(` and `\[`, before escapes —
 * the places `marked` tries an extension.
 *
 * Offsets are bytes where upstream's are UTF-16 code units; every delimiter is ASCII, so they cut
 * the same places. `\s` is JavaScript's, and `$` is the end of the input as JavaScript's is.
 */
final class LatexSyntax
{
    /** JavaScript's `\s`. */
    private const string WS = '\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const string TABLE_DELIMITER_ROW = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/D';

    /** Upstream's block extension `start`: a line that could open display math. */
    public static function startsBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(?:\$\$|\\\\\[)/', $line) === 1;
    }

    public static function tokenizeInline(string $source): ?LatexSpan
    {
        if (str_starts_with($source, '$$')) {
            $opening = '$$';
            $closing = '$$';
        } elseif (str_starts_with($source, '\\(')) {
            $opening = '\\(';
            $closing = '\\)';
        } elseif (str_starts_with($source, '\\[')) {
            $opening = '\\[';
            $closing = '\\]';
        } elseif (str_starts_with($source, '$') && preg_match('/^\$[' . self::WS . ']/u', $source) !== 1) {
            $opening = '$';
            $closing = '$';
        } else {
            return null;
        }

        $openingLength = strlen($opening);
        $closingIndex = self::findClosingDelimiter($source, $closing, $openingLength);
        if ($closingIndex >= 0 && $opening === '$') {
            $inner = substr($source, $openingLength, $closingIndex - $openingLength);
            $after = substr($source, $closingIndex + 1);
            if (
                preg_match('/[' . self::WS . ']$/uD', $inner) === 1
                || preg_match('/^[0-9]/', $after) === 1
                || (preg_match('/^[A-Z_][A-Z0-9_]*(?:[^A-Za-z0-9_' . self::WS . '])?$/uD', $inner) === 1
                    && preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $after) === 1)
                || str_contains($inner, '`')
            ) {
                return null;
            }
        }

        if ($closingIndex < 0) {
            $pendingSource = substr($source, $openingLength);
            if (str_starts_with($opening, '\\') || self::looksLikePendingDollarMath($pendingSource)) {
                return new LatexSpan($pendingSource, $source, true);
            }

            return null;
        }

        $text = substr($source, $openingLength, $closingIndex - $openingLength);
        if ($text === '' || str_contains($text, "\n")) {
            return null;
        }

        return new LatexSpan($text, substr($source, 0, $closingIndex + strlen($closing)));
    }

    /**
     * Display math never spans a table. Without this check, an unclosed "$$" or "\[" pairs with a
     * later opener or stays pending, swallowing the table (upstream's #11159).
     */
    public static function tokenizeBlock(string $source): ?LatexBlock
    {
        if (preg_match('/^ {0,3}\$\$[ \t]*(?:\n)?([\s\S]*?)\$\$[ \t]*(?:\n|\z)/', $source, $match) === 1
            && $match[1] !== '' && !self::containsTableStart($match[1])) {
            return new LatexBlock(self::jsTrim($match[1]), $match[0]);
        }

        if (preg_match('/^ {0,3}\\\\\[[ \t]*(?:\n)?([\s\S]*?)\\\\\][ \t]*(?:\n|\z)/', $source, $match) === 1
            && $match[1] !== '' && !self::containsTableStart($match[1])) {
            return new LatexBlock(self::jsTrim($match[1]), $match[0]);
        }

        if (preg_match('/^ {0,3}\\\\\[[ \t]*(?:\n)?([\s\S]*)\z/', $source, $match) === 1
            && $match[1] !== '' && !self::containsTableStart($match[1])) {
            return new LatexBlock($match[1], $match[0], true);
        }

        if (preg_match('/^ {0,3}\$\$[ \t]*(?:\n)?([\s\S]*)\z/', $source, $match) === 1
            && $match[1] !== '' && self::looksLikePendingDollarMath($match[1]) && !self::containsTableStart($match[1])) {
            return new LatexBlock($match[1], $match[0], true);
        }

        return null;
    }

    private static function isEscaped(string $source, int $index): bool
    {
        $backslashes = 0;
        for ($position = $index - 1; $position >= 0 && $source[$position] === '\\'; $position--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    private static function findClosingDelimiter(string $source, string $closing, int $start): int
    {
        $index = $start > strlen($source) ? false : strpos($source, $closing, $start);
        while ($index !== false && self::isEscaped($source, $index)) {
            $index = strpos($source, $closing, $index + strlen($closing));
        }

        return $index === false ? -1 : $index;
    }

    private static function looksLikePendingDollarMath(string $source): bool
    {
        return preg_match('/\\\\[A-Za-z]+|[_^=+*\/<>()\[\]|±≤≥≠≈∈→⇒∞∫∑√-]/u', $source) === 1;
    }

    /**
     * A GFM table starts with a header row followed by a delimiter row such as "|---|---|" or
     * "| --- |". The header needs a "|" only when the delimiter has none: a pipe-free delimiter
     * without a pipe in the header is a thematic break, not a table.
     */
    private static function containsTableStart(string $source): bool
    {
        $lines = explode("\n", $source);
        for ($index = 0; $index < count($lines) - 1; $index++) {
            $delimiterRow = $lines[$index + 1];
            if ((str_contains($delimiterRow, '|') || str_contains($lines[$index], '|')) && preg_match(self::TABLE_DELIMITER_ROW, $delimiterRow) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function jsTrim(string $value): string
    {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/uD', '', $value) ?? $value;
    }
}
