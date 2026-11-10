<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `statements.ts`: the shared statement layer — source text to statements, plus
 * the small string-reading helpers every grammar leans on.
 *
 * Statements are walked by code point (`mb_str_split()`) where upstream walks `[...s]`; where it
 * slices strings by `indexOf`, the offsets are bytes from the same string. White space is
 * JavaScript's (`Labels::SPACE`, `Labels::trim()`).
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Statements
{
    /** @param list<string> $out */
    private static function flushStatement(string $cur, array &$out): string
    {
        $trimmed = Labels::trim($cur);

        if ($trimmed !== '') {
            $out[] = $trimmed;
        }

        return '';
    }

    /**
     * One source line split into statements on `;`, stopping at a `%%` comment. Quoted spans are
     * opaque, so a label may contain `;` and `%%`.
     *
     * @param list<string> $out
     */
    public static function splitStatements(string $line, array &$out): void
    {
        $chars = mb_str_split($line, 1, 'UTF-8');
        $n = count($chars);
        $cur = '';
        $inQuotes = false;

        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    $inQuotes = false;
                }

                $cur .= $c;
            } elseif ($c === '"') {
                $inQuotes = true;
                $cur .= $c;
            } elseif ($c === '%' && ($chars[$i + 1] ?? null) === '%') {
                break;
            } elseif ($c === ';') {
                $cur = self::flushStatement($cur, $out);
            } else {
                $cur .= $c;
            }
        }

        self::flushStatement($cur, $out);
    }

    /**
     * Index just past a leading YAML frontmatter block (`---` … `---`), or 0 when there is none.
     * While the block is still unterminated everything is frontmatter, so a streamed diagram stays
     * blank until it closes.
     *
     * @param list<string> $lines
     */
    public static function frontmatterEnd(array $lines): int
    {
        $i = 0;
        $n = count($lines);

        while ($i < $n && Labels::trim($lines[$i]) === '') {
            $i++;
        }

        if ($i >= $n || Labels::trim($lines[$i]) !== '---') {
            return 0;
        }

        $i++;

        while ($i < $n && Labels::trim($lines[$i]) !== '---') {
            $i++;
        }

        return $i + 1;
    }

    /**
     * All statements in a source block, in order. A leading YAML frontmatter block, part of the
     * mermaid grammar since v10, is skipped.
     *
     * @return list<string>
     */
    public static function statementsOf(string $src): array
    {
        $lines = Labels::srcLines($src);
        $out = [];

        foreach (array_slice($lines, self::frontmatterEnd($lines)) as $line) {
            self::splitStatements($line, $out);
        }

        return $out;
    }

    /**
     * The `title:` of a leading frontmatter block, or null — the one frontmatter key with terminal
     * meaning; `config` and the rest style mermaid's own renderers.
     */
    public static function frontmatterTitle(string $src): ?string
    {
        $lines = Labels::srcLines($src);
        $end = self::frontmatterEnd($lines);

        foreach (array_slice($lines, 0, $end) as $line) {
            $kv = self::splitOnce($line, ':');

            // Untrimmed on the left: an indented `title:` is nested under some other key.
            if ($kv === null || Labels::trimEnd($kv[0]) !== 'title') {
                continue;
            }

            $t = Labels::trim($kv[1]);
            $quoted = strlen($t) > 1
                && ((str_starts_with($t, '"') && str_ends_with($t, '"')) || (str_starts_with($t, "'") && str_ends_with($t, "'")));
            $title = Labels::trim($quoted ? substr($t, 1, -1) : $t);

            return $title === '' ? null : $title;
        }

        return null;
    }

    /**
     * Per-character flags: true where the character lies inside a double-quoted span, quotes
     * included.
     *
     * @param list<string> $chars
     * @return list<bool>
     */
    public static function quoteMask(array $chars): array
    {
        $mask = [];
        $inQuotes = false;

        foreach ($chars as $c) {
            if ($c === '"') {
                $mask[] = true;
                $inQuotes = !$inQuotes;
            } else {
                $mask[] = $inQuotes;
            }
        }

        return $mask;
    }

    /**
     * Split on separator characters outside double quotes and parentheses, dropping empty
     * segments — the one splitter every grammar shares, so quote rules cannot drift between them.
     *
     * @param callable(string): bool $isSep
     * @return list<string>
     */
    public static function splitTop(string $s, callable $isSep): array
    {
        $out = [];
        $cur = '';
        $inQuotes = false;
        $depth = 0;

        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $c === '(') {
                $depth++;
            } elseif (!$inQuotes && $c === ')' && $depth > 0) {
                $depth--;
            }

            if (!$inQuotes && $depth === 0 && $isSep($c)) {
                if ($cur !== '') {
                    $out[] = $cur;
                }

                $cur = '';
            } else {
                $cur .= $c;
            }
        }

        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }

    /**
     * `head : rest` split at the first label colon, skipping `:::` tag runs so `A:::hot : desc`
     * keeps its tag with the id. Null when there is no colon.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitColon(string $s): ?array
    {
        $chars = mb_str_split($s, 1, 'UTF-8');
        $n = count($chars);

        for ($i = 0; $i < $n; $i++) {
            if ($chars[$i] !== ':') {
                continue;
            }

            $run = $i;

            while ($run < $n && $chars[$run] === ':') {
                $run++;
            }

            if ($run - $i >= 3) {
                $i = $run - 1;

                continue;
            }

            return [implode('', array_slice($chars, 0, $i)), implode('', array_slice($chars, $i + 1))];
        }

        return null;
    }

    /**
     * Trailing `:::name` tags stripped from an id token: `A:::hot` is id `A`, classes `[hot]`.
     *
     * @return array{id: string, classes: list<string>}
     */
    public static function takeTags(string $token): array
    {
        $parts = explode(':::', $token);

        if (count($parts) === 1 || $parts[0] === '') {
            return ['id' => $token, 'classes' => []];
        }

        return ['id' => $parts[0], 'classes' => array_values(array_filter(array_slice($parts, 1), static fn (string $c): bool => $c !== ''))];
    }

    /**
     * The body of a `class A,B name` statement as `[ids, names]`. The last whitespace-separated
     * token is the name list, everything before it the ids — so a space after a comma (`class A,
     * B warn`) still reads as two ids.
     *
     * @return array{0: list<string>, 1: list<string>}|null
     */
    public static function parseClassAssign(string $rest): ?array
    {
        $body = Labels::trim($rest);

        if (preg_match('/' . Labels::SPACE . Labels::NONSPACE . '*$/Du', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $ws = $m[0][1];
        $split = static fn (string $s): array => array_values(array_filter(
            array_map(Labels::trim(...), explode(',', $s)),
            static fn (string $t): bool => $t !== '',
        ));

        return [$split(substr($body, 0, $ws)), $split(substr($body, $ws))];
    }

    /**
     * `A "url" [tooltip]` / `A href "url" …` as `[id, url]`. The callback forms (`call`,
     * `callback`) are null — their quoted string is a tooltip.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseHref(string $rest): ?array
    {
        $words = Labels::words($rest);
        $id = $words[0] ?? null;
        $second = $words[1] ?? null;

        if ($id === null || $second === 'call' || $second === 'callback') {
            return null;
        }

        return preg_match('/"([^"]+)"/', $rest, $url) === 1 ? [$id, $url[1]] : null;
    }

    public static function firstWord(string $s): string
    {
        return Labels::words($s)[0] ?? '';
    }

    /**
     * Split on the first occurrence of `sep`, Rust's `split_once`.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitOnce(string $s, string $sep): ?array
    {
        $i = strpos($s, $sep);

        return $i === false ? null : [substr($s, 0, $i), substr($s, $i + strlen($sep))];
    }

    public static function nonEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    /**
     * The diagram kind from the header statement, lowercased.
     *
     * @param list<string> $statements
     */
    public static function headerKind(array $statements): ?string
    {
        $header = $statements[0] ?? null;

        if ($header === null) {
            return null;
        }

        $kind = self::firstWord($header);

        return $kind === '' ? null : Labels::asciiLower($kind);
    }

    /**
     * The body of a `classDef` statement — `name[,name2] k1:v1,k2:v2` — as names and properties.
     * Values are kept verbatim; malformed pairs are skipped.
     *
     * @return array{names: list<string>, props: array<string, string>}|null
     */
    public static function parseClassDef(string $rest): ?array
    {
        $body = Labels::trim($rest);

        if (preg_match('/' . Labels::SPACE . '/u', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $ws = $m[0][1];
        $names = array_values(array_filter(
            array_map(Labels::trim(...), explode(',', substr($body, 0, $ws))),
            static fn (string $s): bool => $s !== '',
        ));
        $props = [];

        // `splitTop()` keeps `rgb(255,0,0)` whole; a bare comma still separates pairs.
        foreach (self::splitTop(Labels::trim(substr($body, $ws)), static fn (string $c): bool => $c === ',') as $pair) {
            $kv = self::splitOnce($pair, ':');

            if ($kv === null) {
                continue;
            }

            $k = Labels::trim($kv[0]);
            $v = Labels::trim($kv[1]);

            if ($k !== '' && $v !== '') {
                $props[$k] = $v;
            }
        }

        return $names === [] ? null : ['names' => $names, 'props' => $props];
    }
}
