<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

/**
 * Syntax colouring for a fenced code block.
 *
 * A left-to-right scanner, not a chain of replacements. That distinction is the whole
 * design: a highlighter built from `preg_replace` over a keyword list paints the `if`
 * inside `"if you like"` blue and the `#` inside a URL grey, which is worse than no
 * colour at all, because the reader now has to distrust it. Here the scanner asks, at
 * each position, "does a comment start here? a string? a number? a word?" — so once it
 * is inside a string, nothing inside that string is anything else.
 *
 * It still guesses in two places, and says so: a Capitalised word is taken for a type,
 * and a word followed by `(` for a function call. Both are wrong sometimes. Colour is
 * cosmetic, so being wrong costs a wrong colour and nothing else — which is the reason
 * a guess is acceptable here and nowhere else in this codebase.
 *
 * Replaces upstream's `cli-highlight` (which wraps highlight.js). See `Grammar`.
 */
final class Highlight
{
    /**
     * Colour $code, one entry per line.
     *
     * Per line, and not one string with newlines in it, because the renderer compares
     * lines: a style left open at the end of one line would be a style the next line
     * did not ask for. So a token spanning a newline is styled once per line it covers.
     *
     * @return list<string> plain lines when the language has no grammar here
     */
    public static function lines(string $code, string $language, ?HighlightTheme $theme = null): array
    {
        $grammar = Grammar::for($language);

        if ($grammar === null) {
            return explode("\n", $code);
        }

        $theme ??= HighlightTheme::default();
        $lines = [''];

        foreach (self::scan($code, $grammar) as [$kind, $text]) {
            $paint = match ($kind) {
                'comment' => $theme->comment,
                'string' => $theme->string,
                'number' => $theme->number,
                'keyword' => $theme->keyword,
                'type' => $theme->type,
                'function' => $theme->function,
                default => $theme->plain,
            };

            foreach (explode("\n", $text) as $index => $part) {
                if ($index > 0) {
                    $lines[] = '';
                }

                if ($part !== '') {
                    $lines[count($lines) - 1] .= $paint($part);
                }
            }
        }

        return $lines;
    }

    /** The language a file is in, going by its extension. */
    public static function languageFromPath(string $path): ?string
    {
        return Grammar::fromPath($path);
    }

    /**
     * @return list<array{0: string, 1: string}> kind and text, in order, covering $code
     *         exactly — concatenating the texts gives $code back
     */
    private static function scan(string $code, Grammar $grammar): array
    {
        $tokens = [];
        $length = strlen($code);
        $at = 0;
        $plain = '';

        $flush = static function () use (&$plain, &$tokens): void {
            if ($plain !== '') {
                $tokens[] = ['plain', $plain];
                $plain = '';
            }
        };

        while ($at < $length) {
            $token = self::comment($code, $at, $grammar)
                ?? self::string($code, $at, $grammar)
                ?? self::number($code, $at)
                ?? self::word($code, $at, $grammar);

            if ($token === null) {
                // Anything else — punctuation, spaces, the bytes of a multibyte
                // character — is gathered up so it stays one token and cannot be split
                // mid-codepoint by a style.
                $plain .= $code[$at];
                $at++;

                continue;
            }

            $flush();
            $tokens[] = [$token[0], $token[1]];
            $at += strlen($token[1]);
        }

        $flush();

        return $tokens;
    }

    /** @return array{0: string, 1: string}|null */
    private static function comment(string $code, int $at, Grammar $grammar): ?array
    {
        foreach ($grammar->lineComment as $marker) {
            if (substr_compare($code, $marker, $at, strlen($marker)) !== 0) {
                continue;
            }

            // `#[Override]` is an attribute, not a comment that happens to start with a
            // bracket — and PHP 8 code is full of them, so reading one as a comment
            // greys out a whole line of real code.
            if ($grammar->hashStartsAttributes && $marker === '#' && ($code[$at + 1] ?? '') === '[') {
                continue;
            }

            $end = strpos($code, "\n", $at);

            return ['comment', substr($code, $at, ($end === false ? strlen($code) : $end) - $at)];
        }

        if ($grammar->blockComment === null) {
            return null;
        }

        [$open, $close] = $grammar->blockComment;

        if (substr_compare($code, $open, $at, strlen($open)) !== 0) {
            return null;
        }

        $end = strpos($code, $close, $at + strlen($open));

        // An unclosed block comment runs to the end, which is what a compiler would say
        // about it too.
        $length = $end === false ? strlen($code) - $at : $end + strlen($close) - $at;

        return ['comment', substr($code, $at, $length)];
    }

    /** @return array{0: string, 1: string}|null */
    private static function string(string $code, int $at, Grammar $grammar): ?array
    {
        foreach ($grammar->strings as [$quote, $escapes]) {
            if (substr_compare($code, $quote, $at, strlen($quote)) !== 0) {
                continue;
            }

            return ['string', self::untilClosed($code, $at, $quote, $escapes)];
        }

        return null;
    }

    /**
     * From the opening quote to the closing one.
     *
     * A single-character quote that does not close on its own line ends there. Without
     * that, one apostrophe in an English sentence — `don't` in a shell script, `it's` in
     * a Python identifier's neighbourhood — turns the whole rest of the file into a
     * string. Multi-character openers (`"""`, and backticks, which are template
     * literals) really do span lines, so those run to the close or to the end.
     */
    private static function untilClosed(string $code, int $at, string $quote, bool $escapes): string
    {
        $multiline = strlen($quote) > 1 || $quote === '`';
        $length = strlen($code);
        $index = $at + strlen($quote);

        while ($index < $length) {
            if ($escapes && $code[$index] === '\\') {
                $index += 2;

                continue;
            }

            if (!$multiline && $code[$index] === "\n") {
                return substr($code, $at, $index - $at);
            }

            if (substr_compare($code, $quote, $index, strlen($quote)) === 0) {
                return substr($code, $at, $index + strlen($quote) - $at);
            }

            $index++;
        }

        return substr($code, $at);
    }

    /** @return array{0: string, 1: string}|null */
    private static function number(string $code, int $at): ?array
    {
        if (!ctype_digit($code[$at])) {
            return null;
        }

        // 0x1f and 0b1010, or digits with an optional fraction and exponent. The dot has
        // to have a digit after it, so `1..5` is one and a range, and `2.toString()` is
        // two and a call — a looser "digits and dots" pattern swallows both whole.
        $number = '/\G(?:0[xXoObB][0-9a-fA-F_]+|[0-9][0-9_]*(?:\.[0-9][0-9_]*)?(?:[eE][+-]?[0-9]+)?)/';

        if (preg_match($number, $code, $match, 0, $at) !== 1) {
            return null;
        }

        return ['number', $match[0]];
    }

    /** @return array{0: string, 1: string}|null */
    private static function word(string $code, int $at, Grammar $grammar): ?array
    {
        if (preg_match('/\G[A-Za-z_$\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $code, $match, 0, $at) !== 1) {
            return null;
        }

        $word = $match[0];
        $lookup = $grammar->caseInsensitiveKeywords ? strtolower($word) : $word;

        if (in_array($lookup, $grammar->keywords, true)) {
            return ['keyword', $word];
        }

        if (in_array($lookup, $grammar->types, true)) {
            return ['type', $word];
        }

        // A word with a bracket after it is being called. Whitespace between the two is
        // allowed because plenty of code is written that way. Checked *before* the
        // Capitalised guess below, because a call is the stronger signal of the two:
        // Go's exported functions are all capitalised, and `fmt.Println(` is a call
        // whatever its first letter looks like.
        if (str_starts_with(ltrim(substr($code, $at + strlen($word), 32), " \t"), '(')) {
            return ['function', $word];
        }

        return $grammar->capitalisedIsAType && preg_match('/^[A-Z][a-z]/', $word) === 1
            ? ['type', $word]
            : ['plain', $word];
    }
}
