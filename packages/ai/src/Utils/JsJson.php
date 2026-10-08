<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use JsonException;

/**
 * JavaScript's `JSON.parse` and `JSON.stringify`, as far as what they *say* goes.
 *
 * Upstream surfaces V8's own `SyntaxError` text wherever a provider's payload is not JSON — the
 * proxy's `JSON.parse(data)`, Anthropic's `Could not parse Anthropic SSE event …: <why>`, Mistral's
 * stream, `@google/genai`'s `chunk.json()` — and that text is what a person reads in the error line
 * and what the retry and overflow rules match against. PHP's `json_last_error_msg()` says `Syntax
 * error` for nearly all of it, so pig used to print a different sentence for the same bytes.
 *
 * `syntaxError()` walks the text the way V8's `JsonParser` (Node 22, the engine pi pins) does and
 * returns the message V8 would throw: the same kinds, the same positions and `(line L column C)`
 * suffix, the same ten-character context around an unexpected token. Positions and the context are
 * counted in UTF-16 code units, as JavaScript counts them, and a context cut through a surrogate
 * pair reads as U+FFFD where V8's string holds the lone half. The table in `JsJsonTest` is real
 * `node -e` output.
 *
 * The input is what JavaScript would have held: bytes that are not UTF-8 read as U+FFFD, as
 * `TextDecoder` reads them (checked against `Buffer.toString('utf8')` over a sweep of truncated and
 * overlong sequences: the same number of replacements in the same places).
 *
 * **One thing cannot be matched**: a JavaScript string can hold half a surrogate pair and a PHP
 * UTF-8 string cannot. Where V8's message carries one — the unexpected token is an emoji, whose
 * first UTF-16 unit is what V8 quotes, or the ten-unit context cuts a pair — this has U+FFFD in
 * that place, which is also what the half becomes the moment upstream prints or encodes it as
 * UTF-8. `JSON.stringify` of an escaped lone surrogate (`"\ud800"`) is the same story.
 */
final class JsJson
{
    private const int MAX_CONTEXT_CHARACTERS = 10;

    private const int MAX_SAFE_INTEGER = 9007199254740991;

    /** ECMAScript's WhiteSpace and LineTerminator code points, as one PCRE class. */
    private const string JS_SPACE = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const int MIN_ORIGINAL_SOURCE_LENGTH_FOR_CONTEXT = (self::MAX_CONTEXT_CHARACTERS * 2) + 1;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private string $source = '';

    private int $length = 0;

    private int $cursor = 0;

    private function __construct()
    {
    }

    /**
     * `JSON.parse(text)`: the value, objects as arrays unless `$assoc` is false.
     *
     * @throws JsonException carrying V8's message for text that is not JSON
     */
    public static function parse(string $text, bool $assoc = true): mixed
    {
        $flags = JSON_INVALID_UTF8_SUBSTITUTE;
        $value = json_decode($text, $assoc, 100_000, $flags);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }

        $message = self::syntaxError($text);

        if ($message !== null) {
            throw new JsonException($message);
        }

        // Valid to V8, refused by PHP: an escaped lone surrogate (`"\ud800"`), which a JavaScript
        // string holds and prints as U+FFFD. Decoded as the replacement character instead.
        $value = json_decode(self::replaceLoneSurrogateEscapes($text), $assoc, 100_000, $flags);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }

        throw new JsonException(json_last_error_msg());
    }

    /**
     * The message V8's `JSON.parse` throws for this text, or null when it parses.
     */
    public static function syntaxError(string $text): ?string
    {
        $parser = new self();
        $parser->source = self::decodeUtf8($text);
        $parser->length = strlen($parser->source);

        return $parser->run();
    }

    /**
     * `JSON.stringify(value)` for a decoded JSON value — objects as `stdClass` (or string-keyed
     * arrays), so `{}` stays `{}`. What differs from `json_encode()` is spelt JavaScript's way:
     * U+2028/U+2029 unescaped, and numbers as `Number.prototype.toString()` writes them (`1` not
     * `1.0`, `1e+21`, `1e-7`, `0.000001`).
     */
    public static function stringify(mixed $value): string
    {
        if (is_float($value)) {
            return is_finite($value) ? self::number($value) : 'null';
        }

        if (is_int($value) && abs($value) > self::MAX_SAFE_INTEGER) {
            // A JavaScript number is a double: past 2^53 the integer is not what PHP holds.
            return self::number((float) $value);
        }

        if ($value instanceof \stdClass) {
            return self::object(get_object_vars($value));
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? '[' . implode(',', array_map(self::stringify(...), $value)) . ']'
                : self::object($value);
        }

        if (is_string($value)) {
            return self::encodeString($value);
        }

        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? 'null' : $json;
    }

    /**
     * `String.prototype.trim()`: JavaScript's white space and line terminators — which take in the
     * no-break and other Unicode spaces and the BOM, and leave a NUL alone, where PHP's `trim()`
     * does the opposite.
     */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^' . self::JS_SPACE . '+|' . self::JS_SPACE . '+$/u', '', self::decodeUtf8($text));
    }

    /** `String.prototype.trimStart()`, with `trim()`'s idea of white space. */
    public static function trimStart(string $text): string
    {
        return (string) preg_replace('/^' . self::JS_SPACE . '+/u', '', self::decodeUtf8($text));
    }

    /**
     * A decoded JSON value in a template literal (`${value}`): null `null`, a list its items joined
     * by commas (null as nothing), an object `[object Object]`, a number as JavaScript prints it.
     */
    public static function toString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_float($value) => is_nan($value) ? 'NaN' : (is_infinite($value) ? ($value > 0 ? 'Infinity' : '-Infinity') : self::number($value)),
            is_int($value) => abs($value) > self::MAX_SAFE_INTEGER ? self::number((float) $value) : (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(
                static fn (mixed $item): string => $item === null ? '' : self::toString($item),
                $value,
            )),
            default => '[object Object]',
        };
    }

    /** `Number.prototype.toString()` for a finite double. */
    public static function number(float $number): string
    {
        if ($number == 0.0) {
            return '0';
        }

        // The shortest round-trip digits PHP knows (`serialize_precision = -1`), re-spelt.
        $repr = (string) json_encode(abs($number), JSON_PRESERVE_ZERO_FRACTION);

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i', $repr, $match) !== 1) {
            return $repr;
        }

        $int = $match[1];
        $frac = $match[2] ?? '';
        $exp = (int) ($match[3] ?? 0);
        $digits = ltrim($int . $frac, '0');
        // Position of the decimal point relative to the start of `$digits`: value = 0.digits × 10^n.
        $n = strlen($int) + $exp - (strlen($int . $frac) - strlen(ltrim($int . $frac, '0')));
        $digits = rtrim($digits, '0');
        $k = strlen($digits);
        $sign = $number < 0 ? '-' : '';

        if ($k <= $n && $n <= 21) {
            return $sign . $digits . str_repeat('0', $n - $k);
        }

        if (0 < $n && $n <= 21) {
            return $sign . substr($digits, 0, $n) . '.' . substr($digits, $n);
        }

        if (-6 < $n && $n <= 0) {
            return $sign . '0.' . str_repeat('0', -$n) . $digits;
        }

        $e = $n - 1;
        $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $sign . $mantissa . 'e' . ($e < 0 ? '-' : '+') . abs($e);
    }

    /**
     * An object's members in JavaScript's own-property order: integer-like keys (array indexes)
     * first, ascending, then the rest as they were written.
     *
     * @param array<array-key, mixed> $members
     */
    private static function object(array $members): string
    {
        $indexes = [];
        $names = [];

        foreach ($members as $key => $item) {
            $key = (string) $key;

            if (preg_match('/^(?:0|[1-9]\d{0,9})$/', $key) === 1 && (int) $key < 4294967295) {
                $indexes[(int) $key] = $item;
            } else {
                $names[] = [$key, $item];
            }
        }

        ksort($indexes);
        $parts = [];

        foreach ($indexes as $key => $item) {
            $parts[] = '"' . $key . '":' . self::stringify($item);
        }

        foreach ($names as [$key, $item]) {
            $parts[] = self::encodeString($key) . ':' . self::stringify($item);
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function encodeString(string $text): string
    {
        return (string) json_encode($text, self::JSON_FLAGS);
    }

    /**
     * Every `\uD800`–`\uDFFF` escape that is not half of a pair, as `\ufffd`. Only called on text
     * V8 accepts, so a backslash outside a string cannot occur and an escaped backslash is skipped
     * whole.
     */
    private static function replaceLoneSurrogateEscapes(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $isHigh = static fn (string $hex): bool => ($v = hexdec($hex)) >= 0xD800 && $v <= 0xDBFF;
        $isLow = static fn (string $hex): bool => ($v = hexdec($hex)) >= 0xDC00 && $v <= 0xDFFF;

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== '\\') {
                $out .= $text[$i];

                continue;
            }

            if (($text[$i + 1] ?? '') !== 'u') {
                $out .= substr($text, $i, 2);
                $i++;

                continue;
            }

            $hex = substr($text, $i + 2, 4);
            $next = substr($text, $i + 6, 2) === '\\u' ? substr($text, $i + 8, 4) : '';

            if ($isHigh($hex) && $next !== '' && $isLow($next)) {
                $out .= substr($text, $i, 12);
                $i += 11;

                continue;
            }

            $out .= $isHigh($hex) || $isLow($hex) ? '\\ufffd' : substr($text, $i, 6);
            $i += 5;
        }

        return $out;
    }

    // ---- V8's JsonParser, as far as which error it reports -------------------------------

    private function run(): ?string
    {
        try {
            $this->skipWhitespace();
            $this->parseValue();
            $this->skipWhitespace();

            if ($this->cursor < $this->length) {
                $this->fail('Unexpected non-whitespace character after JSON', '');
            }

            return null;
        } catch (\UnexpectedValueException $failure) {
            return $failure->getMessage();
        }
    }

    private function parseValue(): void
    {
        if ($this->cursor >= $this->length) {
            $this->unexpectedToken();
        }

        $c = $this->source[$this->cursor];

        match (true) {
            $c === '"' => $this->scanString(),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->scanNumber(),
            $c === '{' => $this->parseObject(),
            $c === '[' => $this->parseArray(),
            $c === 't' => $this->scanLiteral('true'),
            $c === 'f' => $this->scanLiteral('false'),
            $c === 'n' => $this->scanLiteral('null'),
            default => $this->unexpectedToken(),
        };
    }

    private function parseObject(): void
    {
        $this->cursor++;
        $this->skipWhitespace();

        if ($this->peek() === '}') {
            $this->cursor++;

            return;
        }

        if ($this->peek() !== '"') {
            $this->fail("Expected property name or '}'");
        }

        while (true) {
            $this->scanString();
            $this->skipWhitespace();

            if ($this->peek() !== ':') {
                $this->fail("Expected ':' after property name");
            }

            $this->cursor++;
            $this->skipWhitespace();
            $this->parseValue();
            $this->skipWhitespace();

            $c = $this->peek();

            if ($c === '}') {
                $this->cursor++;

                return;
            }

            if ($c !== ',') {
                $this->fail("Expected ',' or '}' after property value");
            }

            $this->cursor++;
            $this->skipWhitespace();

            if ($this->peek() !== '"') {
                $this->fail('Expected double-quoted property name');
            }
        }
    }

    private function parseArray(): void
    {
        $this->cursor++;
        $this->skipWhitespace();

        if ($this->peek() === ']') {
            $this->cursor++;

            return;
        }

        while (true) {
            $this->parseValue();
            $this->skipWhitespace();

            $c = $this->peek();

            if ($c === ']') {
                $this->cursor++;

                return;
            }

            if ($c !== ',') {
                $this->fail("Expected ',' or ']' after array element");
            }

            $this->cursor++;
            $this->skipWhitespace();
        }
    }

    private function scanString(): void
    {
        $this->cursor++;

        while (true) {
            if ($this->cursor >= $this->length) {
                $this->fail('Unterminated string');
            }

            $c = $this->source[$this->cursor];

            if ($c === '"') {
                $this->cursor++;

                return;
            }

            if (ord($c) < 0x20) {
                $this->fail('Bad control character in string literal');
            }

            if ($c !== '\\') {
                $this->cursor++;

                continue;
            }

            $this->cursor++;

            // A backslash at the very end is V8's end-of-input token, not an unterminated string.
            if ($this->cursor >= $this->length) {
                $this->unexpectedToken();
            }

            $e = $this->source[$this->cursor];

            if ($e === 'u') {
                for ($i = 1; $i <= 4; $i++) {
                    $this->cursor++;

                    if ($this->cursor >= $this->length || !ctype_xdigit($this->source[$this->cursor])) {
                        $this->fail('Bad Unicode escape');
                    }
                }

                $this->cursor++;

                continue;
            }

            // Past Latin-1 (a UTF-8 lead byte from 0xC4 up) V8 reports the character as a token.
            if (ord($e) >= 0xC4) {
                $this->unexpectedToken();
            }

            if (!in_array($e, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                $this->fail('Bad escaped character');
            }

            $this->cursor++;
        }
    }

    private function scanNumber(): void
    {
        if ($this->peek() === '-') {
            $this->cursor++;
        }

        if ($this->peek() === '0') {
            $this->cursor++;

            if ($this->isDigit($this->peek())) {
                $this->fail('Unexpected number');
            }
        } else {
            if (!$this->isDigit($this->peek())) {
                $this->fail('No number after minus sign');
            }

            while ($this->isDigit($this->peek())) {
                $this->cursor++;
            }
        }

        if ($this->peek() === '.') {
            $this->cursor++;

            if (!$this->isDigit($this->peek())) {
                $this->fail('Unterminated fractional number');
            }

            while ($this->isDigit($this->peek())) {
                $this->cursor++;
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $this->cursor++;

            if ($this->peek() === '-' || $this->peek() === '+') {
                $this->cursor++;
            }

            if (!$this->isDigit($this->peek())) {
                $this->fail('Exponent part is missing a number');
            }

            while ($this->isDigit($this->peek())) {
                $this->cursor++;
            }
        }
    }

    /** V8's `ScanLiteral()`: a mismatch is reported as the token the mismatched character starts. */
    private function scanLiteral(string $literal): void
    {
        $this->cursor++;

        for ($i = 1, $n = strlen($literal); $i < $n; $i++) {
            if ($this->cursor >= $this->length) {
                $this->unexpectedToken();
            }

            if ($this->source[$this->cursor] !== $literal[$i]) {
                $this->unexpectedToken();
            }

            $this->cursor++;
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->cursor < $this->length && in_array($this->source[$this->cursor], [' ', "\t", "\n", "\r"], true)) {
            $this->cursor++;
        }
    }

    private function peek(): ?string
    {
        return $this->cursor < $this->length ? $this->source[$this->cursor] : null;
    }

    private function isDigit(?string $c): bool
    {
        return $c !== null && $c >= '0' && $c <= '9';
    }

    /** V8's `LookUpErrorMessageForJsonToken()`: the message for whatever token starts here. */
    private function unexpectedToken(): never
    {
        if ($this->cursor >= $this->length) {
            throw new \UnexpectedValueException('Unexpected end of JSON input');
        }

        $c = $this->source[$this->cursor];

        if ($c === '-' || ($c >= '0' && $c <= '9')) {
            $this->fail('Unexpected number');
        }

        if ($c === '"') {
            $this->fail('Unexpected string');
        }

        // `IsSpecialString()`: what `JSON.parse(undefined)` and friends are handed.
        if (in_array($this->source, ['[object Object]', 'undefined', 'Infinity', 'NaN'], true)) {
            throw new \UnexpectedValueException("\"{$this->source}\" is not valid JSON");
        }

        $units = self::utf16($this->source);
        $length = intdiv(strlen($units), 2);
        $pos = $this->position();
        $token = self::fromUtf16(substr($units, $pos * 2, 2));

        if ($length < self::MIN_ORIGINAL_SOURCE_LENGTH_FOR_CONTEXT) {
            throw new \UnexpectedValueException("Unexpected token '{$token}', \"{$this->source}\" is not valid JSON");
        }

        if ($pos < self::MAX_CONTEXT_CHARACTERS) {
            $context = self::fromUtf16(substr($units, 0, ($pos + self::MAX_CONTEXT_CHARACTERS) * 2));

            throw new \UnexpectedValueException("Unexpected token '{$token}', \"{$context}\"... is not valid JSON");
        }

        if ($pos < $length - self::MAX_CONTEXT_CHARACTERS) {
            $context = self::fromUtf16(substr($units, ($pos - self::MAX_CONTEXT_CHARACTERS) * 2, self::MAX_CONTEXT_CHARACTERS * 4));

            throw new \UnexpectedValueException("Unexpected token '{$token}', ...\"{$context}\"... is not valid JSON");
        }

        $context = self::fromUtf16(substr($units, ($pos - self::MAX_CONTEXT_CHARACTERS) * 2));

        throw new \UnexpectedValueException("Unexpected token '{$token}', ...\"{$context}\" is not valid JSON");
    }

    /** A message with V8's `in JSON at position N (line L column C)` tail. */
    private function fail(string $what, string $in = ' in JSON'): never
    {
        $prefix = self::utf16(substr($this->source, 0, $this->cursor));
        $units = array_values(unpack('v*', $prefix) ?: []);
        $end = count($units);
        $line = 1;
        $lastLineBreak = 0;

        // V8's `CalculateFileLocation()`: `\r\n` is one break, and so are a lone `\r` and `\n`.
        for ($i = 0; $i < $end; $i++) {
            if ($units[$i] === 0x0D && $i < $end - 1 && $units[$i + 1] === 0x0A) {
                $i++;
            }

            if ($units[$i] === 0x0D || $units[$i] === 0x0A) {
                $line++;
                $lastLineBreak = $i + 1;
            }
        }

        $column = 1 + $end - $lastLineBreak;

        throw new \UnexpectedValueException("{$what}{$in} at position {$end} (line {$line} column {$column})");
    }

    /** The cursor as a UTF-16 offset. */
    private function position(): int
    {
        return intdiv(strlen(self::utf16(substr($this->source, 0, $this->cursor))), 2);
    }

    /** Not-UTF-8 bytes as U+FFFD, the way `TextDecoder` (and so every string upstream parses) has them. */
    public static function decodeUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    private static function utf16(string $utf8): string
    {
        return $utf8 === '' ? '' : (string) mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
    }

    /** UTF-16LE back to UTF-8, a lone surrogate as U+FFFD — what printing the JS string gives. */
    private static function fromUtf16(string $units): string
    {
        $out = '';
        $values = array_values(unpack('v*', $units) ?: []);
        $count = count($values);

        for ($i = 0; $i < $count; $i++) {
            $u = $values[$i];

            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $count && $values[$i + 1] >= 0xDC00 && $values[$i + 1] <= 0xDFFF) {
                $out .= mb_chr(0x10000 + (($u - 0xD800) << 10) + ($values[$i + 1] - 0xDC00), 'UTF-8');
                $i++;

                continue;
            }

            $out .= $u >= 0xD800 && $u <= 0xDFFF ? "\u{FFFD}" : mb_chr($u, 'UTF-8');
        }

        return $out;
    }
}
