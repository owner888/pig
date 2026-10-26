<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Tool-call arguments as they stream, read one piece at a time — upstream's `StreamingJsonParser`.
 *
 * `PartialJson::parse()` repairs and decodes the whole text again for every piece, so a stream costs
 * the square of its length: a 66KB `write` in 20-byte deltas was over four seconds of CPU on the
 * event loop, with the terminal frozen for all of it. Here `append()` reads each byte once and keeps
 * where it is — the open containers, the string being read, the token being read — so the whole
 * stream costs time linear in its length. `value()` hands out what has been read so far.
 *
 * What a document cut short reads as is `PartialJson::parse()`'s, which the tests check prefix by
 * prefix: an unterminated string shows what has arrived, a member whose key or value has not
 * started is left out, a number shows its longest prefix that is a number (`0.` is `0`), and a
 * literal shows once it is complete. Where the two differ, it is upstream's way, which shows more:
 * "an unterminated escape is held back until complete" where the old repair lost the whole string
 * for that byte, and "raw control characters in strings are kept, an invalid escape such as `\d`
 * stays literal text" where `json_decode()` refused the document and the member went missing until
 * the stream ended. The authoritative parse is still the one over the finished text,
 * `PartialJson::parseToolCallArguments()`, which this does not replace.
 *
 * **Copies, and what pays for them.** A snapshot has to be a value of its own, so the containers
 * still open are copied into each one; completed containers and strings are shared, PHP arrays and
 * strings being copy-on-write. As upstream: "each `append()` earns copy credit proportional to its
 * length plus a constant; `snapshot()` spends it and returns the previous snapshot while the open
 * path costs more than the credit. So a document that keeps a very large container open (thousands
 * of elements) shows a preview that lags a little behind the text, and no input makes the total work
 * grow faster than the text." The string still being read is shared the same way, and copied
 * once per snapshot when the next piece is appended to it, so it is charged too, by its length.
 *
 * @internal
 */
final class StreamingJson
{
    // Upstream's: "Copy credit per append and per appended character. The floor keeps small
    // documents fresh at every snapshot."
    private const int CREDIT_PER_APPEND = 256;

    private const int CREDIT_PER_CHARACTER = 8;

    // Bytes of the open string copied per credit, a container member being one credit.
    private const int STRING_BYTES_PER_CREDIT = 8;

    // "An unterminated number longer than this is not shown until it ends."
    private const int MAX_PENDING_SCALAR_LENGTH = 64;

    /** What ends a number or a literal. */
    private const string DELIMITERS = " \t\n\r,:{}[]\"";

    private const array SIMPLE_ESCAPES = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t"];

    /** The root has been read whole; anything after it is ignored, as `json_decode()` would refuse it. */
    private bool $done = false;

    /** Whether a root value has started. */
    private bool $rootStarted = false;

    private mixed $root = null;

    /** @var list<StreamingJsonFrame> the containers still open, outermost first */
    private array $stack = [];

    /**
     * How many frames from the bottom can be seen: a container in a place that takes no value
     * (`{"a" 1 [2]}`'s array) is read and dropped, and everything inside it with it.
     */
    private int $visible = 0;

    private bool $inString = false;

    /** The open string is a key; otherwise a value. */
    private bool $stringIsKey = false;

    /** Where the open string goes: a key, `true` for the next array element or the root, null for nowhere. */
    private string|int|bool|null $stringSlot = null;

    private string $string = '';

    /** An escape read so far: '' for none, else a backslash and up to five more characters. */
    private string $escape = '';

    /** A `\uD800`–`\uDBFF` waiting for its other half. */
    private ?int $highSurrogate = null;

    /** How far into the open string the bytes are known to be UTF-8. */
    private int $checkedTo = 0;

    private bool $stringValid = true;

    /** A number or literal being read. */
    private string $scalar = '';

    private string|int|bool|null $scalarSlot = null;

    /** What copying the open path costs: the members and elements of the visible open containers. */
    private int $pathCost = 0;

    private int $credit = 0;

    private bool $dirty = false;

    /** @var array<array-key, mixed> */
    private array $snapshot = [];

    /** Read the next piece. Costs time linear in its length. */
    public function append(string $text): void
    {
        $length = strlen($text);
        $this->credit += self::CREDIT_PER_APPEND + self::CREDIT_PER_CHARACTER * $length;
        $at = 0;

        while ($at < $length && !$this->done) {
            $this->dirty = true;

            if ($this->inString) {
                $at = $this->readString($text, $at, $length);
            } elseif ($this->scalar !== '') {
                $at = $this->readScalar($text, $at, $length);
            } else {
                $this->structural($text[$at++]);
            }
        }
    }

    /**
     * The object read so far, `[]` when none can be seen yet — or when the document is not an object
     * or array at all, as `PartialJson::parse()` says. Returns the previous snapshot while a fresh copy
     * of the open path is not yet paid for (see the class comment).
     *
     * @return array<array-key, mixed>
     */
    public function value(): array
    {
        if (!$this->dirty) {
            return $this->snapshot;
        }

        // The open string is shared with the snapshot, so the next piece appended to it copies it
        // whole — fast, being `memcpy()`, but a copy per snapshot of a string as long as the file
        // being written all the same. Charged by the byte, so a long string is shown a few pieces
        // behind instead: at one credit per byte, a 4MB `write` in 20-byte pieces copied 5GB.
        $cost = $this->pathCost + 1 + ($this->inString ? intdiv(strlen($this->string), self::STRING_BYTES_PER_CREDIT) : 0);

        if ($cost > $this->credit) {
            return $this->snapshot;
        }

        $this->credit -= $cost;
        $this->dirty = false;
        [$hasPending, $pending] = $this->pending();

        return $this->snapshot = $this->build($hasPending, $pending);
    }

    private function readString(string $text, int $at, int $length): int
    {
        while ($at < $length) {
            if ($this->escape !== '') {
                if ($this->continueEscape($text[$at])) {
                    $at++;
                }

                // Not part of the escape: read as an ordinary character below.
                continue;
            }

            $run = strcspn($text, "\"\\", $at);

            if ($run > 0) {
                $this->flushSurrogate();
                $this->string .= substr($text, $at, $run);
                $at += $run;

                if ($at >= $length) {
                    break;
                }
            }

            if ($text[$at] === '"') {
                $this->endString();

                return $at + 1;
            }

            $this->escape = '\\';
            $at++;
        }

        return $at;
    }

    /** Continue an escape with $character; false when it is not part of it and must be read normally. */
    private function continueEscape(string $character): bool
    {
        if ($this->escape === '\\') {
            if (isset(self::SIMPLE_ESCAPES[$character])) {
                $this->escape = '';
                $this->flushSurrogate();
                $this->string .= self::SIMPLE_ESCAPES[$character];

                return true;
            }

            if ($character === 'u') {
                $this->escape = '\\u';

                return true;
            }

            // An invalid escape keeps its backslash as text, as `JsonRepair` does.
            $this->escape = '';
            $this->flushSurrogate();
            $this->string .= '\\';

            return false;
        }

        if (ctype_xdigit($character)) {
            $this->escape .= $character;

            if (strlen($this->escape) < 6) {
                return true;
            }

            $code = hexdec(substr($this->escape, 2));
            $this->escape = '';
            $this->codepoint($code);

            return true;
        }

        // `\u` without its four digits stays text.
        $literal = $this->escape;
        $this->escape = '';
        $this->flushSurrogate();
        $this->string .= $literal;

        return false;
    }

    /** A `\u` escape's code unit, pairing surrogates the way `json_decode()` does. */
    private function codepoint(int $code): void
    {
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            $this->flushSurrogate();
            $this->highSurrogate = $code;

            return;
        }

        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            if ($this->highSurrogate !== null) {
                $code = 0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($code - 0xDC00);
                $this->highSurrogate = null;
                $this->string .= mb_chr($code, 'UTF-8');

                return;
            }

            $this->string .= "\u{FFFD}";

            return;
        }

        $this->flushSurrogate();
        $this->string .= mb_chr($code, 'UTF-8');
    }

    /** A high surrogate with nothing to pair with: not a character, so a replacement for one. */
    private function flushSurrogate(): void
    {
        if ($this->highSurrogate !== null) {
            $this->highSurrogate = null;
            $this->string .= "\u{FFFD}";
        }
    }

    private function readScalar(string $text, int $at, int $length): int
    {
        $run = strcspn($text, self::DELIMITERS, $at);
        $this->scalar .= substr($text, $at, $run);
        $at += $run;

        if ($at < $length) {
            $this->endScalar();
        }

        return $at;
    }

    private function structural(string $character): void
    {
        switch ($character) {
            case ' ':
            case "\t":
            case "\n":
            case "\r":
                return;
            case '"':
                $this->startString();

                return;
            case '{':
            case '[':
                $this->open($character === '{');

                return;
            case '}':
            case ']':
                $this->close();

                return;
            case ':':
                $frame = $this->top();

                if ($frame !== null && $frame->isObject && $frame->phase === StreamingJsonFrame::COLON) {
                    $frame->phase = StreamingJsonFrame::VALUE;
                }

                return;
            case ',':
                $frame = $this->top();

                if ($frame !== null && $frame->isObject) {
                    $frame->phase = StreamingJsonFrame::KEY;
                }

                return;
            default:
                $this->scalar = $character;
                $this->scalarSlot = $this->valueSlot();
        }
    }

    private function top(): ?StreamingJsonFrame
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    /**
     * Where a value starting now goes: a key, `true` for the next element or the root, or null for a
     * place that takes no value. As upstream, "a missing colon is tolerated: `{"a" 1}` reads as
     * `{"a": 1}`".
     */
    private function valueSlot(): string|int|bool|null
    {
        $frame = $this->top();

        if ($frame === null) {
            return $this->rootStarted ? null : true;
        }

        if (!$frame->isObject) {
            return true;
        }

        return ($frame->phase === StreamingJsonFrame::VALUE || $frame->phase === StreamingJsonFrame::COLON) && $frame->key !== null
            ? $frame->key
            : null;
    }

    private function startString(): void
    {
        $frame = $this->top();
        // "A missing comma is tolerated: `{"a": 1 "b": 2}` reads the second member."
        $this->stringIsKey = $frame !== null && $frame->isObject
            && ($frame->phase === StreamingJsonFrame::KEY || $frame->phase === StreamingJsonFrame::COMMA);
        $this->stringSlot = $this->stringIsKey ? null : $this->valueSlot();
        $this->inString = true;
        $this->string = '';
        $this->escape = '';
        $this->highSurrogate = null;
        $this->checkedTo = 0;
        $this->stringValid = true;

        if (!$this->stringIsKey && $frame === null) {
            $this->rootStarted = true;
        }
    }

    private function endString(): void
    {
        $this->flushSurrogate();
        $this->inString = false;
        $text = $this->checkUtf8(true) ? $this->string : mb_scrub($this->string, 'UTF-8');
        $this->string = '';

        if ($this->stringIsKey) {
            $frame = $this->top();
            $frame->key = $text;
            $frame->phase = StreamingJsonFrame::COLON;

            return;
        }

        $this->place($this->stringSlot, $text);
    }

    private function endScalar(): void
    {
        $token = $this->scalar;
        $this->scalar = '';

        if ($this->top() === null) {
            $this->rootStarted = true;
        }

        $value = self::decodeScalar($token);

        // A word that is no value — `NaN`, `undefined`, a stray `x` — is dropped.
        if ($value !== null) {
            $this->place($this->scalarSlot, $value[0]);
        } elseif ($this->top() === null) {
            $this->done = true;
        }
    }

    /** @return array{0: mixed}|null the token's value, or null when it is none */
    private static function decodeScalar(string $token): ?array
    {
        return match (true) {
            $token === 'true' => [true],
            $token === 'false' => [false],
            $token === 'null' => [null],
            // JSON's grammar, not `is_numeric()`'s — see `PartialJson::isCompleteLiteral()` — and
            // `json_decode()` for the value, so a number reads exactly as the finished parse reads it.
            preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][-+]?\d+)?\z/', $token) === 1 => [json_decode($token)],
            default => null,
        };
    }

    private function open(bool $isObject): void
    {
        $slot = $this->valueSlot();

        if ($this->stack === []) {
            $this->rootStarted = true;
        }

        $frame = new StreamingJsonFrame($isObject, $slot);
        $visible = $slot !== null && $this->visible === count($this->stack);
        $this->stack[] = $frame;

        if ($visible) {
            $this->visible++;
            $this->pathCost++;
        }
    }

    private function close(): void
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            return;
        }

        if ($this->visible > count($this->stack)) {
            $this->visible--;
            $this->pathCost -= $frame->size + 1;
        }

        $this->place($frame->slot, $frame->container);
    }

    /** A finished value into its place; at the root it ends the document. */
    private function place(string|int|bool|null $slot, mixed $value): void
    {
        $frame = $this->top();

        if ($frame === null) {
            if ($slot !== null) {
                $this->root = $value;
            }

            $this->rootStarted = true;
            $this->done = true;

            return;
        }

        if ($frame->isObject) {
            $frame->phase = StreamingJsonFrame::COMMA;
        }

        if ($slot === null) {
            return;
        }

        $size = count($frame->container);

        if ($slot === true) {
            $frame->container[] = $value;
        } else {
            $frame->container[$slot] = $value;
        }

        if (count($frame->container) > $size) {
            $frame->size++;

            if ($this->visible === count($this->stack)) {
                $this->pathCost++;
            }
        }
    }

    /**
     * The value still being read, as `PartialJson::parse()` would show it: a string's text so far
     * (without an escape or a character cut in half), or the longest prefix of a token that is a value.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function pending(): array
    {
        if ($this->inString) {
            if ($this->stringIsKey || $this->stringSlot === null) {
                return [false, null];
            }

            $valid = $this->checkUtf8(false);
            $text = substr($this->string, 0, $this->completeLength());

            return [true, $valid ? $text : mb_scrub($text, 'UTF-8')];
        }

        if ($this->scalar === '' || $this->scalarSlot === null || strlen($this->scalar) > self::MAX_PENDING_SCALAR_LENGTH) {
            return [false, null];
        }

        for ($size = strlen($this->scalar); $size > 0; $size--) {
            $value = self::decodeScalar(substr($this->scalar, 0, $size));

            if ($value !== null) {
                return [true, $value[0]];
            }
        }

        return [false, null];
    }

    /** The open string's length without a UTF-8 sequence cut off at its end. */
    private function completeLength(): int
    {
        $length = strlen($this->string);

        for ($back = 1; $back <= 3 && $back <= $length; $back++) {
            $byte = ord($this->string[$length - $back]);

            if ($byte < 0x80) {
                return $length;
            }

            if ($byte >= 0xC0) {
                $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $needed > $back ? $length - $back : $length;
            }
        }

        return $length;
    }

    /**
     * Whether the open string is UTF-8 so far, checking only what was not checked before — so a
     * long string costs one pass, not one per snapshot. With $whole, the end of the string too.
     */
    private function checkUtf8(bool $whole): bool
    {
        $end = $whole ? strlen($this->string) : $this->completeLength();

        if ($this->stringValid && $end > $this->checkedTo) {
            $this->stringValid = mb_check_encoding(substr($this->string, $this->checkedTo, $end - $this->checkedTo), 'UTF-8');
            $this->checkedTo = $end;
        }

        return $this->stringValid;
    }

    /**
     * A copy of the open path with the pending value in place.
     *
     * @return array<array-key, mixed>
     */
    private function build(bool $hasPending, mixed $pending): array
    {
        if ($this->stack === []) {
            if ($this->rootStarted && is_array($this->root)) {
                return $this->root;
            }

            // A string or a number at the root is valid JSON, but no arguments object.
            return [];
        }

        $hasChild = $hasPending && $this->visible === count($this->stack);
        $child = $pending;
        $slot = $this->inString ? $this->stringSlot : $this->scalarSlot;

        for ($i = $this->visible - 1; $i >= 0; $i--) {
            $frame = $this->stack[$i];
            $container = $frame->container;

            if ($hasChild) {
                if ($slot === true) {
                    $container[] = $child;
                } else {
                    $container[$slot] = $child;
                }
            }

            $child = $container;
            $slot = $frame->slot;
            $hasChild = true;
        }

        return $this->visible > 0 ? $child : [];
    }
}
