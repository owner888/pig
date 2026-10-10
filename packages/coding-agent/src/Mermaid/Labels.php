<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

use Pig\Ai\Utils\JsJson;

/**
 * grok-mermaid's `labels.ts`: label text cleaned of markup and entities, wrapped and fitted.
 *
 * Strings are walked by code point (`mb_str_split()`), as upstream walks them with `[...s]`.
 * White space is JavaScript's (`\s`, `trim()`), which takes in the no-break and other Unicode
 * spaces that PHP's own functions leave alone.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Labels
{
    /** Node labels wrap to at most this many display columns per line… */
    public const int WRAP_WIDTH = 24;

    /** …and at most this many lines; overflow is truncated with an ellipsis. */
    public const int MAX_LINES = 4;

    /** Edge labels are truncated to this many columns. */
    public const int MAX_LABEL = 28;

    /** Identifier-boundary characters preferred as break points for a word too wide to fit. */
    public const array LABEL_BREAK_CHARS = ['_', '-', '.', '/'];

    /** JavaScript's `\s`, as a character class. */
    public const string SPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const int ENTITY_LOOKAHEAD = 10;

    private const array NAMED_ENTITIES = ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'"];

    private const array HTML_FORMAT_TAGS = [
        'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ins', 'mark', 'small', 'big', 'sub',
        'sup', 'code', 'kbd', 'samp', 'var', 'tt', 'span', 'font', 'q', 'abbr', 'cite', 'pre',
    ];

    /** ASCII-only case folding, as Rust's `to_ascii_lowercase`. */
    public static function asciiLower(string $s): string
    {
        return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    public static function asciiUpper(string $s): string
    {
        return strtr($s, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /**
     * C0 and C1 controls, less the `\t\n\r` the parsers read: they measure one column and paint
     * none, and ESC would put escape codes into the screen. Applied by every entry point.
     */
    public static function stripControls(string $src): string
    {
        return (string) preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u', '', $src);
    }

    /** JavaScript's `trim()`. */
    public static function trim(string $s): string
    {
        return JsJson::trim($s);
    }

    /** JavaScript's `trimEnd()`. */
    public static function trimEnd(string $s): string
    {
        return (string) preg_replace('/' . self::SPACE . '+$/u', '', $s);
    }

    /** `s.split(/\s+/).filter(w => w !== '')`. @return list<string> */
    public static function words(string $s): array
    {
        return array_values(array_filter(preg_split('/' . self::SPACE . '+/u', $s) ?: [], static fn (string $w): bool => $w !== ''));
    }

    /**
     * Rust's `str::lines()`: split on `\n`, a trailing `\r` stripped, and no final empty line when
     * the input ends in a newline.
     *
     * @return list<string>
     */
    public static function srcLines(string $src): array
    {
        $out = array_map(static fn (string $l): string => str_ends_with($l, "\r") ? substr($l, 0, -1) : $l, explode("\n", $src));

        if ($out !== [] && $out[count($out) - 1] === '') {
            array_pop($out);
        }

        return $out;
    }

    /** Rust's `char::is_alphanumeric`. */
    public static function isAlphanumeric(?string $c): bool
    {
        return $c !== null && $c !== '' && preg_match('/^[\p{Alphabetic}\p{N}]$/u', $c) === 1;
    }

    /** Characters allowed in a bare node, state or class identifier. */
    public static function isIdChar(?string $c): bool
    {
        return self::isAlphanumeric($c) || $c === '_';
    }

    private static function decodeEntityBody(string $body): ?string
    {
        if (isset(self::NAMED_ENTITIES[$body])) {
            return self::NAMED_ENTITIES[$body];
        }

        if (!str_starts_with($body, '#')) {
            return null;
        }

        $num = substr($body, 1);
        $hex = preg_match('/^[xX]/', $num) === 1;
        $digits = $hex ? substr($num, 1) : $num;

        if (preg_match($hex ? '/^[0-9a-fA-F]+$/' : '/^[0-9]+$/', $digits) !== 1) {
            return null;
        }

        // `Number.parseInt` of a long run is a float past 2^53; anything that long is out of range.
        $code = strlen(ltrim($digits, '0')) > 8 ? PHP_INT_MAX : (int) ($hex ? hexdec($digits) : $digits);

        if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return null;
        }

        // Control characters are refused: NUL collides with the CONT sentinel and ESC would
        // inject escape codes.
        if ($code < 0x20 || ($code >= 0x7F && $code <= 0x9F)) {
            return null;
        }

        return mb_chr($code, 'UTF-8');
    }

    /** Decode HTML entities in label text, once — `&amp;lt;` is the literal `&lt;`. */
    public static function decodeHtmlEntities(string $s): string
    {
        if (!str_contains($s, '&')) {
            return $s;
        }

        $chars = mb_str_split($s, 1, 'UTF-8');
        $n = count($chars);
        $out = '';
        $i = 0;

        while ($i < $n) {
            if ($chars[$i] !== '&') {
                $out .= $chars[$i];
                $i++;

                continue;
            }

            $hi = min($i + 1 + self::ENTITY_LOOKAHEAD, $n);
            $semi = -1;

            for ($j = $i + 1; $j < $hi; $j++) {
                if ($chars[$j] === ';') {
                    $semi = $j;

                    break;
                }
            }

            $decoded = $semi === -1 ? null : self::decodeEntityBody(implode('', array_slice($chars, $i + 1, $semi - $i - 1)));

            if ($decoded === null) {
                $out .= '&';
                $i++;
            } else {
                $out .= $decoded;
                $i = $semi + 1;
            }
        }

        return $out;
    }

    /** Strip markdown emphasis from a `` `backtick` `` label string. */
    public static function stripMarkdown(string $s): string
    {
        $noStrong = str_replace(['**', '__'], '', str_replace('`', '', $s));
        $chars = mb_str_split($noStrong, 1, 'UTF-8');
        $out = '';

        foreach ($chars as $i => $c) {
            // `*` and `_` are kept only inside a word, so snake_case survives.
            $inWord = $i > 0 && self::isAlphanumeric($chars[$i - 1]) && self::isAlphanumeric($chars[$i + 1] ?? null);

            if (($c === '*' || $c === '_') && !$inWord) {
                continue;
            }

            $out .= $c;
        }

        return self::trim($out);
    }

    /**
     * @param list<string> $chars
     * @return array{name: string, end: int}|null
     */
    private static function htmlTagAt(array $chars, int $start): ?array
    {
        $n = count($chars);
        $i = $start + 1;

        if (($chars[$i] ?? null) === '/') {
            $i++;
        }

        $nameStart = $i;

        while ($i < $n && preg_match('/^[0-9A-Za-z]$/', $chars[$i]) === 1) {
            $i++;
        }

        if ($i === $nameStart) {
            return null;
        }

        $name = implode('', array_slice($chars, $nameStart, $i - $nameStart));

        while ($i < $n && $chars[$i] !== '>') {
            if ($chars[$i] === '<') {
                return null;
            }

            $i++;
        }

        return ($chars[$i] ?? null) === '>' ? ['name' => $name, 'end' => $i + 1] : null;
    }

    /** Inline formatting tags out, `<br>` as a space; `Vec<String>` and `<id>` left alone. */
    public static function stripHtmlTags(string $s): string
    {
        $chars = mb_str_split($s, 1, 'UTF-8');
        $n = count($chars);
        $out = '';
        $i = 0;

        while ($i < $n) {
            if ($chars[$i] === '<') {
                $tag = self::htmlTagAt($chars, $i);

                if ($tag !== null) {
                    $lower = strtolower($tag['name']);

                    if ($lower === 'br') {
                        $out .= ' ';
                        $i = $tag['end'];

                        continue;
                    }

                    if (in_array($lower, self::HTML_FORMAT_TAGS, true)) {
                        $i = $tag['end'];

                        continue;
                    }
                }
            }

            $out .= $chars[$i];
            $i++;
        }

        return $out;
    }

    /** One matching pair of wrapping delimiters stripped, or null. The delimiters are ASCII, so byte lengths answer as upstream's do. */
    private static function unwrap(string $s, string $open, string $close): ?string
    {
        return strlen($s) >= strlen($open) + strlen($close) && str_starts_with($s, $open) && str_ends_with($s, $close)
            ? substr($s, strlen($open), strlen($s) - strlen($open) - strlen($close))
            : null;
    }

    /** Raw label text normalised: markup stripped, unquoted, entities decoded — in that order. */
    public static function cleanLabel(string $raw): string
    {
        $trimmed = self::trim(self::stripHtmlTags(self::trim($raw)));
        $unquoted = self::trim(self::unwrap($trimmed, '"', '"') ?? self::unwrap($trimmed, "'", "'") ?? $trimmed);
        $md = self::unwrap($unquoted, '`', '`');

        return self::decodeHtmlEntities($md === null ? $unquoted : self::stripMarkdown(self::trim($md)));
    }

    /** Code-point index of the last identifier-boundary character, or -1. */
    private static function lastBreak(string $s): int
    {
        $best = -1;

        foreach (self::LABEL_BREAK_CHARS as $c) {
            $at = mb_strrpos($s, $c, 0, 'UTF-8');
            $best = max($best, $at === false ? -1 : $at);
        }

        return $best;
    }

    /**
     * A label wrapped to `width` columns over at most `maxLines` lines, the last truncated with an
     * ellipsis if it overflows. A word too wide to fit breaks after its last `_-./` that fits,
     * else per character.
     *
     * @return list<string>
     */
    public static function wrapLabel(string $label, int $width, int $maxLines): array
    {
        $width = max(1, $width);
        $lines = [];
        $cur = '';
        $curW = 0;

        foreach (self::words($label) as $word) {
            $ww = Measure::width($word);

            if ($ww > $width) {
                if ($cur !== '') {
                    $lines[] = $cur;
                    $cur = '';
                }

                $chunk = '';
                $chunkW = 0;

                foreach (Measure::measured($word) as [$ch, $cw]) {
                    if ($chunkW + $cw > $width && $chunk !== '') {
                        $p = self::lastBreak($chunk);
                        $carry = $p === -1 ? '' : mb_substr($chunk, $p + 1, null, 'UTF-8');
                        $lines[] = $p === -1 ? $chunk : mb_substr($chunk, 0, $p + 1, 'UTF-8');
                        $chunk = $carry;
                        $chunkW = Measure::width($carry);
                    }

                    $chunk .= $ch;
                    $chunkW += $cw;
                }

                $cur = $chunk;
                $curW = $chunkW;
            } elseif ($cur === '') {
                $cur = $word;
                $curW = $ww;
            } elseif ($curW + 1 + $ww <= $width) {
                $cur .= " {$word}";
                $curW += 1 + $ww;
            } else {
                $lines[] = $cur;
                $cur = $word;
                $curW = $ww;
            }
        }

        if ($cur !== '') {
            $lines[] = $cur;
        }

        if ($lines === []) {
            $lines[] = '';
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $target = max(1, $width - 1);
            $s = '';
            $sw = 0;

            foreach (Measure::measured($lines[count($lines) - 1]) as [$ch, $cw]) {
                if ($sw + $cw > $target) {
                    break;
                }

                $s .= $ch;
                $sw += $cw;
            }

            $lines[count($lines) - 1] = "{$s}…";
        }

        return $lines;
    }

    /** Truncated to `inner` columns, leaving room for the ellipsis. */
    public static function fitLabel(string $label, int $inner): string
    {
        if (Measure::width($label) <= $inner) {
            return $label;
        }

        $out = '';
        $used = 0;

        foreach (Measure::measured($label) as [$c, $cw]) {
            if ($used + $cw + 1 > $inner) {
                break;
            }

            $out .= $c;
            $used += $cw;
        }

        return "{$out}…";
    }
}
