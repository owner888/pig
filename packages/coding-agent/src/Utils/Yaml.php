<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

/**
 * The YAML a frontmatter block is written in — upstream's `parse()` from the `yaml` package, for
 * what a `SKILL.md` or a prompt template puts between its `---` lines.
 *
 * pig has no YAML package and adds none, so this is the part of YAML 1.2 frontmatter uses, and it
 * **refuses what `yaml` refuses** rather than reading around it. A line-at-a-time reader did that,
 * and got two things wrong: a malformed block loaded where upstream drops the skill with a warning
 * (most often `description: Use when: …`, a second `: ` in a plain value, which YAML reads as a
 * nested mapping and refuses), and a valid one read wrong (`description: >` and its folded lines
 * came back as the description `>`).
 *
 * What is read:
 * - block mappings and sequences, nested by indentation (spaces; a tab there is an error, as in
 *   YAML), a sequence's items allowed at its key's own indentation, `- key: value` items;
 * - plain scalars, over several lines too, folded; `'single'` and `"double"` quoted ones with
 *   their escapes, over several lines; `|` and `>` block scalars with chomping (`-`, `+`) and an
 *   indentation indicator;
 * - flow collections, `[a, b]` and `{a: b}`, nested, over several lines;
 * - comments; the core schema's `null`, booleans, integers (decimal, `0o`, `0x`) and floats.
 *
 * What is refused, each with the line it was found on: a duplicate key; a `: ` or a final `:`
 * inside a plain value; an unterminated quote; an unknown escape; a value starting with `@` or a
 * backtick (YAML reserves them); indentation that matches no enclosing block; anything left over
 * after a quoted value or a flow collection. Anchors, aliases and tags are read on block values
 * (`!!str` keeping a plain value as text, any other tag ignored) and refused inside a flow
 * collection, the one place this is stricter than `yaml`: nothing a frontmatter needs is written so.
 */
final class Yaml
{
    /** @var list<array{indent: int, text: string, line: int, raw: string}> the lines, comments and blanks kept as empty text; `raw` as written, for a block scalar */
    private array $lines = [];

    private int $at = 0;

    /** The core schema's tags other than `!!str`, which leave a value as it would be read untagged. */
    private const array CORE_TAGS = ['!!int', '!!float', '!!bool', '!!null', '!!map', '!!seq'];

    /** @var array<string, mixed> `&name` → the value it was put on, for `*name` */
    private array $anchors = [];

    /**
     * The value the document holds — an array for a mapping or a sequence, a scalar, or null for
     * nothing at all.
     *
     * @param int $firstLine the line the document starts on in its file, for the error messages
     * @throws YamlError
     */
    public static function parse(string $yaml, int $firstLine = 1): mixed
    {
        $parser = new self();

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml)) as $number => $raw) {
            $content = ltrim($raw, ' ');
            $indent = strlen($raw) - strlen($content);

            if (str_starts_with($content, "\t") && trim($content) !== '') {
                throw new YamlError('Tabs are not allowed as indentation', $firstLine + $number);
            }

            // A document marker leaves nothing to read: the block is one document.
            if ($indent === 0 && (rtrim($content) === '---' || rtrim($content) === '...')) {
                $content = '';
            }

            $parser->lines[] = ['indent' => $indent, 'text' => rtrim($content), 'line' => $firstLine + $number, 'raw' => $raw];
        }

        $parser->skipEmpty();

        if ($parser->at >= count($parser->lines)) {
            return null;
        }

        $value = $parser->block($parser->lines[$parser->at]['indent'], -1);
        $parser->skipEmpty();

        if ($parser->at < count($parser->lines)) {
            $parser->fail('Unexpected content', $parser->lines[$parser->at]['line']);
        }

        return $value;
    }

    // ---- blocks --------------------------------------------------------------------------

    /** The block whose first line is the current one, at $indent, inside a parent at $parent. */
    private function block(int $indent, int $parent): mixed
    {
        $line = $this->lines[$this->at];

        if ($line['text'] === '-' || str_starts_with($line['text'], '- ')) {
            return $this->sequence($indent);
        }

        if ($this->keyOf($line['text']) !== null) {
            return $this->mapping($indent);
        }

        // A scalar standing on its own lines — a document that is just a value.
        $this->at++;

        return $this->value($line['text'], $line['line'], $parent);
    }

    /** @return array<string, mixed> */
    private function mapping(int $indent): array
    {
        $map = [];

        while (($line = $this->next()) !== null && $line['indent'] >= $indent) {
            if ($line['indent'] > $indent) {
                $this->fail('Bad indentation of a mapping entry', $line['line']);
            }

            $key = $this->keyOf($line['text'], $line['line']);

            if ($key === null) {
                $this->fail('Implicit keys need to be followed by map values', $line['line']);
            }

            [$name, $rest] = $key;

            if (array_key_exists($name, $map)) {
                $this->fail('Map keys must be unique', $line['line']);
            }

            $this->at++;
            $map[$name] = $this->entryValue($rest, $line['line'], $indent);
        }

        return $map;
    }

    /** @return list<mixed> */
    private function sequence(int $indent): array
    {
        $list = [];

        while (($line = $this->next()) !== null && $line['indent'] >= $indent) {
            if ($line['indent'] > $indent) {
                $this->fail('Bad indentation of a sequence entry', $line['line']);
            }

            if ($line['text'] !== '-' && !str_starts_with($line['text'], '- ')) {
                // A mapping key at the sequence's indentation ends it, when the sequence was a
                // value at its key's own indentation; the caller decides whether that is right.
                break;
            }

            $rest = ltrim(substr($line['text'], 1), ' ');

            if ($rest === '' || str_starts_with($rest, '#')) {
                $this->at++;
                $list[] = $this->nested($indent, $line['line']);

                continue;
            }

            // `- key: value` and `- - x`: the item is a block starting where its text does.
            $itemIndent = $indent + (strlen($line['text']) - strlen($rest));

            if ($this->keyOf($rest) !== null || $rest === '-' || str_starts_with($rest, '- ')) {
                $this->lines[$this->at] = ['indent' => $itemIndent, 'text' => $rest, 'line' => $line['line'], 'raw' => str_repeat(' ', $itemIndent) . $rest];
                $list[] = $this->block($itemIndent, $indent);

                continue;
            }

            $this->at++;
            $list[] = $this->value($rest, $line['line'], $indent);
        }

        return $list;
    }

    /** What follows `key:` — on its line, or below it. */
    private function entryValue(string $rest, int $lineNumber, int $indent): mixed
    {
        if ($rest === '' || str_starts_with($rest, '#')) {
            $next = $this->next();

            // A sequence may sit at its key's own indentation; anything else must be deeper.
            if ($next !== null && $next['indent'] === $indent && ($next['text'] === '-' || str_starts_with($next['text'], '- '))) {
                return $this->sequence($indent);
            }

            return $this->nested($indent, $lineNumber);
        }

        return $this->value($rest, $lineNumber, $indent);
    }

    /** The block below a line at $indent, or null when the next line is not deeper. */
    private function nested(int $indent, int $lineNumber): mixed
    {
        $next = $this->next();

        if ($next === null || $next['indent'] <= $indent) {
            return null;
        }

        return $this->block($next['indent'], $indent);
    }

    // ---- scalars and flow collections ----------------------------------------------------

    /** A value that starts on the line just read ($text), inside a block at $parent. */
    private function value(string $text, int $lineNumber, int $parent): mixed
    {
        $first = $text[0];

        if ($first === '*') {
            if (preg_match('/^\*([^\s,\[\]{}]+)\s*(?:#.*)?$/', $text, $alias) !== 1) {
                $this->fail('Unexpected content after an alias', $lineNumber);
            }

            if (!array_key_exists($alias[1], $this->anchors)) {
                $this->fail("Aliased anchor not found: {$alias[1]}", $lineNumber);
            }

            return $this->anchors[$alias[1]];
        }

        if ($first === '&' || $first === '!') {
            return $this->withProperties($text, $lineNumber, $parent);
        }

        // A sequence or an explicit key cannot start on the line of the key it is the value of.
        if ($text === '-' || str_starts_with($text, '- ')) {
            $this->fail('Unexpected block-seq-ind on same line with key', $lineNumber);
        }

        if ($text === '?' || str_starts_with($text, '? ')) {
            $this->fail('Nested mappings are not allowed in compact mappings', $lineNumber);
        }

        if ($first === '@' || $first === '`') {
            $this->fail("Plain value cannot start with reserved character {$first}", $lineNumber);
        }

        if ($first === '|' || $first === '>') {
            return $this->blockScalar($text, $lineNumber, $parent);
        }

        if ($first === '"' || $first === "'") {
            [$value, $after] = $this->quoted($text, $lineNumber, $parent);
            $this->expectNothingAfter($after, $lineNumber);

            return $value;
        }

        if ($first === '[' || $first === '{') {
            $source = $this->continued($text, $parent);
            $offset = 0;
            $value = $this->flow($source, $offset, $lineNumber);
            $this->expectNothingAfter(substr($source, $offset), $lineNumber);

            return $value;
        }

        $this->checkPlainStart($text, $lineNumber, false);

        return self::resolve($this->plain($text, $lineNumber, $parent));
    }

    /**
     * A value with an anchor (`&name`, kept for a later `*name`) or a tag in front of it. `!!str`
     * keeps a plain value as the text it is, and so does a tag `yaml` does not know (it warns and
     * reads a string); the core schema's other tags leave the value as it would be.
     */
    private function withProperties(string $text, int $lineNumber, int $parent): mixed
    {
        $anchor = null;
        $tag = null;

        while ($text !== '' && ($text[0] === '&' || $text[0] === '!')) {
            // An anchor; or a tag — `!<verbatim>`, `!!core`, `!local` (a `!handle!` of its own is
            // not resolvable, as `yaml` cannot resolve it either).
            if (preg_match('/^(?:&[^\s,\[\]{}]+|!<[^>]+>|!!?[\w\-.~%:\/#;?@&=+$*\'()]*)/', $text, $property) !== 1
                || ($text[strlen($property[0])] ?? ' ') !== ' ') {
                $this->fail('Tags and anchors must be separated from the next token by white space', $lineNumber);
            }

            if ($property[0][0] === '&' ? $anchor !== null : $tag !== null) {
                $this->fail('A node can have at most one ' . ($property[0][0] === '&' ? 'anchor' : 'tag'), $lineNumber);
            }

            $property[0][0] === '&' ? $anchor = substr($property[0], 1) : $tag = $property[0];
            $text = ltrim(substr($text, strlen($property[0])), ' ');
        }

        $value = match (true) {
            $text === '' || str_starts_with($text, '#') => $this->nested($parent, $lineNumber),
            $tag !== null && !in_array($tag, self::CORE_TAGS, true) && !in_array($text[0], ['"', "'", '|', '>', '[', '{', '&', '*', '!'], true) => $this->taggedPlain($text, $lineNumber, $parent),
            default => $this->value($text, $lineNumber, $parent),
        };

        // A string tag on nothing is the empty string, not null.
        if ($value === null && $tag !== null && !in_array($tag, self::CORE_TAGS, true)) {
            $value = '';
        }

        if ($anchor !== null) {
            $this->anchors[$anchor] = $value;
        }

        return $value;
    }

    /** A plain value under a string tag: the text as written, after the same checks as any plain value. */
    private function taggedPlain(string $text, int $lineNumber, int $parent): string
    {
        $this->checkPlainStart($text, $lineNumber, false);

        return $this->plain($text, $lineNumber, $parent);
    }

    /** A plain scalar: this line's text, and the more-indented lines after it, folded. */
    private function plain(string $text, int $lineNumber, int $parent): string
    {
        $parts = [self::plainLine($text, $lineNumber, $this)];
        $pendingBreaks = 0;

        // A comment ends a plain scalar: what follows on deeper lines is not part of it.
        while (!self::hasComment($text) && ($line = $this->lines[$this->at] ?? null) !== null) {
            if ($line['text'] === '') {
                $pendingBreaks++;
                $this->at++;

                continue;
            }

            if ($line['indent'] <= $parent || str_starts_with($line['text'], '#')) {
                break;
            }

            $parts[] = ($pendingBreaks > 0 ? str_repeat("\n", $pendingBreaks) : ' ') . self::plainLine($line['text'], $line['line'], $this);
            $pendingBreaks = 0;
            $this->at++;
            $text = $line['text'];
        }

        // Blank lines read past the end were not the scalar's.
        $this->at -= $pendingBreaks;

        return implode('', $parts);
    }

    /**
     * What a plain scalar may not start with — YAML's indicators, where they would mean something
     * else — refused with `yaml`'s sentences.
     */
    private function checkPlainStart(string $text, int $lineNumber, bool $inFlow): void
    {
        $first = $text[0] ?? '';
        $second = $text[1] ?? ' ';

        match (true) {
            in_array($first, [',', '[', ']', '{', '}'], true) => $this->fail("Plain value cannot start with flow indicator character {$first}", $lineNumber),
            $first === '%' => $this->fail('Plain value cannot start with directive indicator character %', $lineNumber),
            $first === '@' || $first === '`' => $this->fail("Plain value cannot start with reserved character {$first}", $lineNumber),
            $inFlow && ($first === '|' || $first === '>') => $this->fail("Plain value cannot start with block scalar indicator {$first}", $lineNumber),
            $inFlow && $first === '-' && ($second === ' ' || $second === "\t") => $this->fail('Block collections are not allowed within flow collections', $lineNumber),
            !$inFlow && $first === ':' && ($second === ' ' || $second === "\t") => $this->fail('Nested mappings are not allowed in compact mappings', $lineNumber),
            default => null,
        };
    }

    private static function hasComment(string $text): bool
    {
        return preg_match('/\s#/', $text) === 1;
    }

    /** One line of a plain scalar, its comment cut off, refusing what would make it a mapping. */
    private static function plainLine(string $text, int $lineNumber, self $parser): string
    {
        $value = $text;

        if (preg_match('/\s#/', $value, $match, PREG_OFFSET_CAPTURE) === 1) {
            $value = substr($value, 0, $match[0][1]);
        }

        $value = rtrim($value);

        if (str_contains($value, ': ') || str_ends_with($value, ':')) {
            $parser->fail('Nested mappings are not allowed in compact mappings', $lineNumber);
        }

        return $value;
    }

    /**
     * A quoted scalar from the start of $text, over more lines when the quote is not closed on
     * this one — line breaks folded as YAML folds them.
     *
     * @return array{0: string, 1: string} the value, and what followed the closing quote
     */
    private function quoted(string $text, int $lineNumber, int $parent): array
    {
        $quote = $text[0];
        $source = substr($text, 1);
        $value = '';

        while (true) {
            [$piece, $closedAt] = $quote === "'" ? self::singleQuoted($source) : self::doubleQuoted($source, $lineNumber, $this);
            $value .= $piece;

            if ($closedAt !== null) {
                return [$value, substr($source, $closedAt)];
            }

            // Not closed: fold the next lines in. An escaped line break joins without a space.
            $breaks = 0;
            $joined = false;

            while (($line = $this->lines[$this->at] ?? null) !== null && $line['text'] === '') {
                $breaks++;
                $this->at++;
            }

            if ($line === null || ($line['indent'] <= $parent && $line['text'] !== '')) {
                $this->fail('Missing closing ' . ($quote === "'" ? "'quote" : '"quote'), $lineNumber);
            }

            if ($quote === '"' && str_ends_with($value, "\0escaped-break")) {
                $value = substr($value, 0, -strlen("\0escaped-break"));
                $joined = true;
            } else {
                $value = rtrim($value, ' ');
            }

            $value .= $joined ? '' : ($breaks > 0 ? str_repeat("\n", $breaks) : ' ');
            $source = $line['text'];
            $lineNumber = $line['line'];
            $this->at++;
        }
    }

    /** @return array{0: string, 1: int|null} the text, and the offset after the closing quote */
    private static function singleQuoted(string $source): array
    {
        $value = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] !== "'") {
                $value .= $source[$i];

                continue;
            }

            if (($source[$i + 1] ?? '') === "'") {
                $value .= "'";
                $i++;

                continue;
            }

            return [$value, $i + 1];
        }

        return [$value, null];
    }

    private const array ESCAPES = [
        '0' => "\0", 'a' => "\x07", 'b' => "\x08", 't' => "\t", "\t" => "\t", 'n' => "\n", 'v' => "\x0B",
        'f' => "\x0C", 'r' => "\r", 'e' => "\x1B", ' ' => ' ', '"' => '"', '/' => '/', '\\' => '\\',
        'N' => "\u{85}", '_' => "\u{A0}", 'L' => "\u{2028}", 'P' => "\u{2029}",
    ];

    /** @return array{0: string, 1: int|null} the text, and the offset after the closing quote */
    private static function doubleQuoted(string $source, int $lineNumber, self $parser): array
    {
        $value = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '"') {
                return [$value, $i + 1];
            }

            if ($char !== '\\') {
                $value .= $char;

                continue;
            }

            $escape = $source[$i + 1] ?? null;

            if ($escape === null) {
                // A backslash ending the line: the break is not folded into a space.
                return [$value . "\0escaped-break", null];
            }

            $i++;

            if (isset(self::ESCAPES[$escape])) {
                $value .= self::ESCAPES[$escape];

                continue;
            }

            $digits = ['x' => 2, 'u' => 4, 'U' => 8][$escape] ?? null;
            $hex = $digits === null ? '' : substr($source, $i + 1, $digits);

            if ($digits === null || strlen($hex) !== $digits || !ctype_xdigit($hex)) {
                $parser->fail("Invalid escape sequence \\{$escape}", $lineNumber);
            }

            $value .= mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
            $i += $digits;
        }

        return [$value, null];
    }

    /** A `|` or `>` block scalar: its header is $text, its lines the more-indented ones after it. */
    private function blockScalar(string $text, int $lineNumber, int $parent): string
    {
        if (preg_match('/^([|>])([+-]?)([1-9]?)([+-]?)(?:\s+#.*)?$/', $text, $header) !== 1 || ($header[2] !== '' && $header[4] !== '')) {
            $this->fail('Invalid block scalar header', $lineNumber);
        }

        $folded = $header[1] === '>';
        $chomp = $header[2] . $header[4];
        $contentIndent = $header[3] !== '' ? max(0, $parent) + (int) $header[3] : null;
        $lines = [];

        $leadingBlankIndent = 0;

        while (($line = $this->lines[$this->at] ?? null) !== null) {
            // As written: a block scalar keeps its trailing spaces, and a blank line's spaces past
            // the content's indentation.
            $raw = $line['raw'];

            if ($line['text'] === '' && $contentIndent === null) {
                $leadingBlankIndent = max($leadingBlankIndent, $line['indent']);
            }

            if ($line['text'] !== '') {
                if ($contentIndent === null && $leadingBlankIndent > $line['indent'] && $line['indent'] > $parent) {
                    $this->fail('Block scalars with more-indented leading empty lines must use an explicit indentation indicator', $line['line']);
                }

                $contentIndent ??= $line['indent'];

                if ($line['indent'] <= $parent || $line['indent'] < $contentIndent) {
                    break;
                }
            }

            $lines[] = $contentIndent !== null && strlen($raw) > $contentIndent ? substr($raw, $contentIndent) : '';
            $this->at++;
        }

        // Trailing blank lines are the chomping's to keep or drop, and the lines after are not ours.
        $trailing = 0;

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
            $trailing++;
        }

        $body = $folded ? self::fold($lines) : implode("\n", $lines);

        return match (true) {
            $lines === [] => $chomp === '+' ? str_repeat("\n", $trailing) : '',
            $chomp === '-' => $body,
            $chomp === '+' => $body . "\n" . str_repeat("\n", $trailing),
            default => $body . "\n",
        };
    }

    /**
     * `>`'s folding: a break between two lines of ordinary text is a space, a blank line between
     * them is the break, and the breaks around a more-indented line are kept as they are.
     *
     * @param list<string> $lines
     */
    private static function fold(array $lines): string
    {
        $out = '';
        $breaks = 0;
        $started = false;
        $previousMore = false;

        foreach ($lines as $line) {
            if ($line === '') {
                $breaks++;

                continue;
            }

            $more = $line[0] === ' ' || $line[0] === "\t";

            if (!$started) {
                $out = str_repeat("\n", $breaks) . $line;
                $started = true;
            } elseif ($breaks > 0) {
                $out .= str_repeat("\n", $more || $previousMore ? $breaks + 1 : $breaks) . $line;
            } else {
                $out .= ($more || $previousMore ? "\n" : ' ') . $line;
            }

            $breaks = 0;
            $previousMore = $more;
        }

        return $out;
    }

    /** $text and the more-indented lines after it, joined, until the brackets balance. */
    private function continued(string $text, int $parent): string
    {
        $source = $text;

        while (self::unbalanced($source) && ($line = $this->lines[$this->at] ?? null) !== null
            && ($line['text'] === '' || $line['indent'] > $parent)) {
            $source .= ' ' . $line['text'];
            $this->at++;
        }

        return $source;
    }

    private static function unbalanced(string $source): bool
    {
        $depth = 0;
        $quote = null;

        for ($i = 0, $n = strlen($source); $i < $n; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }

            match ($char) {
                '"', "'" => $quote = $char,
                '[', '{' => $depth++,
                ']', '}' => $depth--,
                default => null,
            };
        }

        return $depth > 0;
    }

    /** A flow collection or scalar at $offset in $source, moving $offset past it. */
    private function flow(string $source, int &$offset, int $lineNumber): mixed
    {
        self::skipSpaces($source, $offset);
        $char = $source[$offset] ?? '';

        if ($char === '[' || $char === '{') {
            $close = $char === '[' ? ']' : '}';
            $offset++;
            $items = [];

            while (true) {
                self::skipSpaces($source, $offset);

                if (($source[$offset] ?? '') === $close) {
                    $offset++;

                    return $items;
                }

                if ($offset >= strlen($source)) {
                    $this->fail("Missing closing {$close}", $lineNumber);
                }

                if ($source[$offset] === ',') {
                    $this->fail('Unexpected , in flow ' . ($close === ']' ? 'sequence' : 'map'), $lineNumber);
                }

                $item = $this->flow($source, $offset, $lineNumber);
                self::skipSpaces($source, $offset);

                if ($close === '}' || ($source[$offset] ?? '') === ':') {
                    if (($source[$offset] ?? '') !== ':') {
                        // `{a}`: a key with no value.
                        $value = null;
                    } else {
                        $offset++;
                        self::skipSpaces($source, $offset);
                        $value = in_array($source[$offset] ?? '', [',', $close], true) ? null : $this->flow($source, $offset, $lineNumber);
                    }

                    if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item) && $item !== null) {
                        $this->fail('Flow mapping keys must be scalars', $lineNumber);
                    }

                    $key = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;

                    if ($close === '}') {
                        if (array_key_exists($key, $items)) {
                            $this->fail('Map keys must be unique', $lineNumber);
                        }

                        $items[$key] = $value;
                    } else {
                        $items[] = [$key => $value];
                    }
                } else {
                    $items[] = $item;
                }

                self::skipSpaces($source, $offset);
                $separator = $source[$offset] ?? '';

                if ($separator === ',') {
                    $offset++;
                } elseif ($separator !== $close) {
                    $this->fail("Missing , or {$close} in flow collection", $lineNumber);
                }
            }
        }

        if ($char === '"' || $char === "'") {
            [$value, $closedAt] = $char === "'" ? self::singleQuoted(substr($source, $offset + 1)) : self::doubleQuoted(substr($source, $offset + 1), $lineNumber, $this);

            if ($closedAt === null) {
                $this->fail('Missing closing ' . $char . 'quote', $lineNumber);
            }

            $offset += $closedAt + 1;

            return $value;
        }

        if ($char === '&' || $char === '*' || $char === '!') {
            $this->fail('Anchors, aliases and tags are not supported inside a flow collection here', $lineNumber);
        }

        // A plain scalar in a flow: up to a separator, or a `:` that starts a value.
        $this->checkPlainStart(substr($source, $offset), $lineNumber, true);
        preg_match('/\G(?:[^,\[\]{}:#]|:(?=[^\s,\[\]{}])|(?<=\S)#)*/', $source, $match, 0, $offset);
        $offset += strlen($match[0]);

        return self::resolve(trim($match[0]));
    }

    private static function skipSpaces(string $source, int &$offset): void
    {
        while (($source[$offset] ?? '') === ' ') {
            $offset++;
        }
    }

    // ---- helpers -------------------------------------------------------------------------

    /**
     * `key: rest` — the key (unquoted) and what follows the colon — or null when the text is not a
     * mapping entry.
     *
     * @return array{0: string, 1: string}|null
     */
    private function keyOf(string $text, int $lineNumber = 0): ?array
    {
        if ($text === '' || str_starts_with($text, '#') || str_starts_with($text, '- ') || $text === '-') {
            return null;
        }

        // An anchor or a tag on the key itself, which the key's value does not need.
        while (($text[0] ?? '') === '&' || ($text[0] ?? '') === '!') {
            if (preg_match('/^(?:&[^\s,\[\]{}]+|!<[^>]+>|!!?[\w\-.~%:\/#;?@&=+$*\'()]*) +(?=\S)/', $text, $property) !== 1) {
                return null;
            }

            $text = substr($text, strlen($property[0]));
        }

        if ($text[0] === '"' || $text[0] === "'") {
            [$key, $closedAt] = $text[0] === "'" ? self::singleQuoted(substr($text, 1)) : self::doubleQuoted(substr($text, 1), 0, $this);

            if ($closedAt === null) {
                return null;
            }

            $after = substr($text, $closedAt + 1);

            return preg_match('/^\s*:(?:\s+(.*))?$/', $after, $match) === 1 ? [$key, trim($match[1] ?? '')] : null;
        }

        if ($text[0] === '[' || $text[0] === '{' || $text[0] === '|' || $text[0] === '>') {
            return null;
        }

        // `: value` — the empty key, which YAML allows and reads as "".
        if ($text === ':' || str_starts_with($text, ': ')) {
            return ['', trim(substr($text, 1))];
        }

        // The first `: ` (or a final `:`) outside a comment is where the key ends.
        if (preg_match('/^([^#]*?[^\s#])\s*:(?:\s+(.*)|)$/', $text, $match) !== 1) {
            return null;
        }

        if ($lineNumber > 0) {
            $this->checkPlainStart($match[1], $lineNumber, false);
        }

        return [$match[1], trim($match[2] ?? '')];
    }

    /** The next line with something on it, without moving past it. */
    private function next(): ?array
    {
        $this->skipEmpty();

        return $this->lines[$this->at] ?? null;
    }

    private function skipEmpty(): void
    {
        while (($line = $this->lines[$this->at] ?? null) !== null && ($line['text'] === '' || str_starts_with($line['text'], '#'))) {
            $this->at++;
        }
    }

    private function expectNothingAfter(string $after, int $lineNumber): void
    {
        $after = trim($after);

        if ($after !== '' && !str_starts_with($after, '#')) {
            $this->fail('Unexpected content after a value', $lineNumber);
        }
    }

    /** The core schema: what a plain scalar is, when it is not a string. */
    private static function resolve(string $value): mixed
    {
        return match (true) {
            in_array($value, ['', '~', 'null', 'Null', 'NULL'], true) => null,
            in_array($value, ['true', 'True', 'TRUE'], true) => true,
            in_array($value, ['false', 'False', 'FALSE'], true) => false,
            preg_match('/^[-+]?[0-9]+$/', $value) === 1 => (int) $value,
            preg_match('/^0o[0-7]+$/', $value) === 1 => (int) octdec(substr($value, 2)),
            preg_match('/^0x[0-9a-fA-F]+$/', $value) === 1 => (int) hexdec(substr($value, 2)),
            preg_match('/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/', $value) === 1 => (float) $value,
            preg_match('/^[-+]?\.(?:inf|Inf|INF)$/', $value) === 1 => str_starts_with($value, '-') ? -INF : INF,
            preg_match('/^\.(?:nan|NaN|NAN)$/', $value) === 1 => NAN,
            default => $value,
        };
    }

    /** @throws YamlError */
    private function fail(string $message, int $lineNumber): never
    {
        throw new YamlError($message, $lineNumber);
    }
}
