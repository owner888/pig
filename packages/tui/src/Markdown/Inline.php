<?php

declare(strict_types=1);

namespace Pig\Tui\Markdown;

/**
 * Markup inside a line: emphasis, code spans, links.
 *
 * A single left-to-right scan. Where a marker has no partner — an unmatched `*`, a `[`
 * with no `](`, a backtick that never closes — the marker is kept as the literal
 * character it is, which is what a reader meant if they were not writing markup at all.
 * That matters more here than strict conformance: this renders whatever a model said,
 * and a model says `2 * 3` and `a_b_c` without meaning either as emphasis.
 */
final class Inline
{
    /** Characters a backslash may escape, from the CommonMark set. */
    private const string ESCAPABLE = '\\`*_{}[]()#+-.!|~<>"\'';

    /** @return list<InlineToken> */
    public static function tokenize(string $text): array
    {
        $tokens = [];
        $buffer = '';
        $length = strlen($text);
        $pos = 0;

        while ($pos < $length) {
            $char = $text[$pos];

            if ($char === '\\' && $pos + 1 < $length && str_contains(self::ESCAPABLE, $text[$pos + 1])) {
                $buffer .= $text[$pos + 1];
                $pos += 2;

                continue;
            }

            $taken = match ($char) {
                '`' => self::codeSpan($text, $pos),
                '[' => self::link($text, $pos),
                '<' => self::autolink($text, $pos),
                '*', '_' => self::emphasis($text, $pos),
                '~' => self::strikethrough($text, $pos),
                "\n" => self::newline($text, $pos, $buffer),
                'h' => self::bareUrl($text, $pos),
                default => null,
            };

            if ($taken === null) {
                $buffer .= $char;
                $pos++;

                continue;
            }

            [$token, $consumed, $trimBuffer] = $taken;
            $buffer = substr($buffer, 0, strlen($buffer) - $trimBuffer);

            if ($buffer !== '') {
                $tokens[] = new Text($buffer);
                $buffer = '';
            }

            $tokens[] = $token;
            $pos += $consumed;
        }

        if ($buffer !== '') {
            $tokens[] = new Text($buffer);
        }

        return $tokens;
    }

    /** The same text with every marker removed — what a link's label compares as. */
    public static function plain(string $text): string
    {
        return self::flatten(self::tokenize($text));
    }

    /** @param list<InlineToken> $tokens */
    private static function flatten(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            $text .= match (true) {
                $token instanceof Text => $token->text,
                $token instanceof CodeSpan => $token->code,
                $token instanceof LineBreak => "\n",
                $token instanceof Strong, $token instanceof Emphasis, $token instanceof Strikethrough,
                    $token instanceof Link => self::flatten($token->children),
                default => '',
            };
        }

        return $text;
    }

    /**
     * A run of backticks, closed by a run of the same length.
     *
     * Everything between is literal, which is the whole point: `` `**not bold**` `` has
     * to come out with its asterisks.
     *
     * @return array{0: InlineToken, 1: int, 2: int}|null
     */
    private static function codeSpan(string $text, int $pos): ?array
    {
        $fence = strspn($text, '`', $pos);
        $close = strpos($text, str_repeat('`', $fence), $pos + $fence);

        if ($close === false) {
            return null;
        }

        $code = substr($text, $pos + $fence, $close - $pos - $fence);

        // One space either side is stripping, so `` ` `` can hold a backtick.
        if (str_starts_with($code, ' ') && str_ends_with($code, ' ') && trim($code) !== '') {
            $code = substr($code, 1, -1);
        }

        return [new CodeSpan($code), $close + $fence - $pos, 0];
    }

    /** @return array{0: InlineToken, 1: int, 2: int}|null */
    private static function link(string $text, int $pos): ?array
    {
        $close = self::matchBracket($text, $pos);

        if ($close === false || ($text[$close + 1] ?? '') !== '(') {
            return null;
        }

        $end = strpos($text, ')', $close + 2);

        if ($end === false) {
            return null;
        }

        $label = substr($text, $pos + 1, $close - $pos - 1);
        $href = trim(substr($text, $close + 2, $end - $close - 2));

        return [new Link(self::tokenize($label), $href, self::plain($label)), $end + 1 - $pos, 0];
    }

    /**
     * The `]` that closes the `[` at $pos, counting nested pairs.
     *
     * @return int|false
     */
    private static function matchBracket(string $text, int $pos): int|false
    {
        $depth = 0;
        $length = strlen($text);

        for ($index = $pos; $index < $length; $index++) {
            $char = $text[$index];

            if ($char === '\\') {
                $index++;

                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return false;
    }

    /** `<https://example.com>`. @return array{0: InlineToken, 1: int, 2: int}|null */
    private static function autolink(string $text, int $pos): ?array
    {
        if (preg_match('#\G<((?:https?|mailto|ftp)://[^\s<>]+|[^\s<>@]+@[^\s<>]+)>#', $text, $match, 0, $pos) !== 1) {
            return null;
        }

        $href = str_contains($match[1], '://') ? $match[1] : 'mailto:' . $match[1];

        return [new Link([new Text($match[1])], $href, $match[1]), strlen($match[0]), 0];
    }

    /**
     * A URL written on its own, which is how anyone actually writes one.
     *
     * Trailing punctuation is left out of the link: a URL at the end of a sentence is
     * followed by a full stop that is not part of it.
     *
     * @return array{0: InlineToken, 1: int, 2: int}|null
     */
    private static function bareUrl(string $text, int $pos): ?array
    {
        if (preg_match('#\Ghttps?://[^\s<>()\[\]]+#', $text, $match, 0, $pos) !== 1) {
            return null;
        }

        $url = rtrim($match[0], '.,;:!?');

        if ($url === '' || !str_contains($url, '://')) {
            return null;
        }

        // Not a link if it is the tail of a word, or already inside `](...)`.
        if ($pos > 0 && !preg_match('/[\s(<]/', $text[$pos - 1])) {
            return null;
        }

        return [new Link([new Text($url)], $url, $url), strlen($url), 0];
    }

    /**
     * `*em*`, `**strong**`, and the underscore spellings.
     *
     * Underscores inside a word do not open or close, so `snake_case_name` survives.
     * Asterisks have no such rule in CommonMark, but a lone `*` with no partner falls
     * through to being literal anyway, which covers `2 * 3`.
     *
     * @return array{0: InlineToken, 1: int, 2: int}|null
     */
    private static function emphasis(string $text, int $pos): ?array
    {
        $marker = $text[$pos];
        $run = min(2, strspn($text, $marker, $pos));
        $delimiter = str_repeat($marker, $run);
        $before = $pos > 0 ? $text[$pos - 1] : ' ';
        $after = $text[$pos + $run] ?? ' ';

        if ($after === ' ' || $after === "\n" || $after === '') {
            return null;
        }

        if ($marker === '_' && preg_match('/[\p{L}\p{N}]/u', $before) === 1) {
            return null;
        }

        $close = self::findCloser($text, $pos + $run, $delimiter, $marker);

        if ($close === null) {
            return null;
        }

        $children = self::tokenize(substr($text, $pos + $run, $close - $pos - $run));
        $token = $run === 2 ? new Strong($children) : new Emphasis($children);

        return [$token, $close + $run - $pos, 0];
    }

    /** Where the matching closing run starts, or null when there is none. */
    private static function findCloser(string $text, int $from, string $delimiter, string $marker): ?int
    {
        $length = strlen($text);
        $run = strlen($delimiter);

        for ($index = $from; $index + $run <= $length; $index++) {
            if ($text[$index] === '\\') {
                $index++;

                continue;
            }

            if (substr($text, $index, $run) !== $delimiter) {
                continue;
            }

            // A closer cannot follow a space, and one more marker means a longer run
            // that is not this delimiter.
            if ($text[$index - 1] === ' ' || ($text[$index + $run] ?? '') === $marker) {
                continue;
            }

            if ($marker === '_' && preg_match('/[\p{L}\p{N}]/u', $text[$index + $run] ?? ' ') === 1) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /** @return array{0: InlineToken, 1: int, 2: int}|null */
    private static function strikethrough(string $text, int $pos): ?array
    {
        if (substr($text, $pos, 2) !== '~~') {
            return null;
        }

        $close = strpos($text, '~~', $pos + 2);

        if ($close === false) {
            return null;
        }

        return [new Strikethrough(self::tokenize(substr($text, $pos + 2, $close - $pos - 2))), $close + 2 - $pos, 0];
    }

    /**
     * A newline inside a block.
     *
     * Two trailing spaces or a trailing backslash mean the author wanted the break kept;
     * anything else is a soft wrap, and the renderer does its own wrapping, so it becomes
     * a space. The third element of the result is how much of the buffer to drop — the
     * trailing spaces or backslash that were the marker.
     *
     * @return array{0: InlineToken, 1: int, 2: int}
     */
    private static function newline(string $text, int $pos, string $buffer): array
    {
        if (str_ends_with($buffer, '  ')) {
            return [new LineBreak(), 1, strlen($buffer) - strlen(rtrim($buffer, ' '))];
        }

        if (str_ends_with($buffer, '\\')) {
            return [new LineBreak(), 1, 1];
        }

        return [new Text(' '), 1, strlen($buffer) - strlen(rtrim($buffer, ' '))];
    }
}
