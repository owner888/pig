<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Decodes JSON that is still arriving.
 *
 * Tool-call arguments stream in as JSON fragments, and the UI wants to show them filling
 * up rather than appearing all at once at the end. Upstream leans on the `partial-json`
 * npm package for this; PHP has no counterpart, so the repair is done here.
 *
 * Never throws and never returns a partial *value*: what comes back is always a decodable
 * object, possibly an empty one. The authoritative parse is the one at the end of the
 * stream, over complete JSON — this only has to be good enough to look at.
 *
 * **Checked against the real package**, `partial-json@0.1.7`, over every prefix of a corpus of
 * realistic tool arguments — escapes, newlines, tabs, astral characters, nested arrays, numbers
 * cut mid-digit, `tr` for `true`, a `\u00e` cut mid-escape. Two differences, both accounted for:
 *
 * - **An empty object comes back as `[]`**, because PHP has one array type and `{}` and `[]` decode
 *   to the same value. That distinction is restored where it matters, which is where the arguments
 *   are encoded again: all four providers and `MessageJson` write `{}` for an empty arguments list,
 *   because `[]` is not an object and Anthropic refuses one.
 * - **Whitespace at the cut end of a string is kept**, where the package drops it: `{"a":"const `
 *   reads as `const ` here and `const` there. This value exists to be looked at while it fills up,
 *   the complete parse at the end is identical either way, and dropping what the model actually
 *   sent is the stranger of the two.
 *
 * That corpus had "numbers cut mid-digit" in it and still missed a number cut at its **decimal
 * point**, which is the one prefix where PHP's idea of a number and JSON's disagree — see
 * `isCompleteLiteral()`. It was found by a mutation sweep instead, and the lesson is the one the
 * traps already state about corpora: a corpus answers the questions it was given.
 */
final class PartialJson
{
    /**
     * @return array<string, mixed> the object so far, or [] when nothing can be read yet
     */
    public static function parse(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        foreach ([$json, ...self::repairs($json)] as $candidate) {
            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // A bare scalar is valid JSON but not an arguments object.
                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }

    /**
     * Ways of closing off a truncated document, best first.
     *
     * @return list<string>
     */
    private static function repairs(string $json): array
    {
        $scan = self::scan($json);
        $repairs = [];

        // Cut inside a string value: close the quote and everything still open. Fails when
        // the cut landed inside an escape like \u00, and the next candidate covers that.
        if ($scan['inString'] && !$scan['inKey']) {
            $body = $scan['escaped'] ? substr($json, 0, -1) : $json;
            $repairs[] = $body . '"' . self::closersFor($scan['stack']);
        }

        // Otherwise fall back to the last point where the document could legally end.
        if ($scan['safeEnd'] >= 0) {
            $repairs[] = substr($json, 0, $scan['safeEnd']) . self::closersFor($scan['safeStack']);
        }

        return $repairs;
    }

    /** @param list<string> $stack */
    private static function closersFor(array $stack): string
    {
        $closers = '';

        foreach (array_reverse($stack) as $opener) {
            $closers .= $opener === '{' ? '}' : ']';
        }

        return $closers;
    }

    /**
     * One pass, recording the furthest offset at which the document could be closed.
     *
     * @return array{inString: bool, inKey: bool, escaped: bool, stack: list<string>, safeEnd: int, safeStack: list<string>}
     */
    private static function scan(string $json): array
    {
        /** @var list<string> $stack */
        $stack = [];
        /** @var list<bool> $expectKey */
        $expectKey = [];
        $inString = false;
        $escaped = false;
        $safeEnd = -1;
        $safeStack = [];
        $tokenStart = -1;

        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $character = $json[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;

                    // A key is only half of a pair; the object cannot be closed after it.
                    if (!self::atKey($stack, $expectKey)) {
                        $safeEnd = $i + 1;
                        $safeStack = $stack;
                    }
                }

                continue;
            }

            if ($tokenStart >= 0 && !self::isBareToken($character)) {
                if (self::isCompleteLiteral(substr($json, $tokenStart, $i - $tokenStart))) {
                    $safeEnd = $i;
                    $safeStack = $stack;
                }

                $tokenStart = -1;
            }

            match (true) {
                $character === '"' => $inString = true,
                $character === '{' => self::open($stack, $expectKey, '{', true, $i, $safeEnd, $safeStack),
                $character === '[' => self::open($stack, $expectKey, '[', false, $i, $safeEnd, $safeStack),
                $character === '}' || $character === ']' => self::close($stack, $expectKey, $i, $safeEnd, $safeStack),
                // The value before a comma is finished, so the document can end just before it.
                $character === ',' => self::afterComma($stack, $expectKey, $i, $safeEnd, $safeStack),
                $character === ':' => self::afterColon($expectKey),
                self::isBareToken($character) => $tokenStart = $tokenStart < 0 ? $i : $tokenStart,
                default => null,
            };
        }

        // A bare token running to the end: keep the longest prefix of it that already reads as
        // a value. Usually that is the whole token. A number may still be growing — "12" of
        // "125" — so the preview can be briefly wrong, which is the trade partial-json makes
        // too, and the final parse corrects it.
        //
        // **Walking back matters for one byte of every float.** `0.` is not a JSON number, so
        // without this the token contributes nothing and the pair it belongs to drops out of
        // the object until the next digit lands — `{"a":1,"temperature":0.` read as `{"a":1}`
        // where `{"a":1,"temperature":0}` is available and is what the byte before and the byte
        // after both say. Being briefly wrong about a value is this method's stated trade;
        // taking a key off the screen and putting it back is not.
        if ($tokenStart >= 0) {
            for ($size = $length - $tokenStart; $size > 0; $size--) {
                if (self::isCompleteLiteral(substr($json, $tokenStart, $size))) {
                    $safeEnd = $tokenStart + $size;
                    $safeStack = $stack;
                    break;
                }
            }
        }

        return [
            'inString' => $inString,
            'inKey' => $inString && self::atKey($stack, $expectKey),
            'escaped' => $escaped,
            'stack' => $stack,
            'safeEnd' => $safeEnd,
            'safeStack' => $safeStack,
        ];
    }

    /**
     * @param list<string> $stack
     * @param list<bool>   $expectKey
     * @param list<string> $safeStack
     */
    private static function open(
        array &$stack,
        array &$expectKey,
        string $opener,
        bool $keyed,
        int $at,
        int &$safeEnd,
        array &$safeStack,
    ): void {
        $stack[] = $opener;
        $expectKey[] = $keyed;
        $safeEnd = $at + 1;
        $safeStack = $stack;
    }

    /**
     * @param list<string> $stack
     * @param list<bool>   $expectKey
     * @param list<string> $safeStack
     */
    private static function close(array &$stack, array &$expectKey, int $at, int &$safeEnd, array &$safeStack): void
    {
        array_pop($stack);
        array_pop($expectKey);
        $safeEnd = $at + 1;
        $safeStack = $stack;
    }

    /**
     * @param list<string> $stack
     * @param list<bool>   $expectKey
     * @param list<string> $safeStack
     */
    private static function afterComma(
        array $stack,
        array &$expectKey,
        int $at,
        int &$safeEnd,
        array &$safeStack,
    ): void {
        $safeEnd = $at;
        $safeStack = $stack;

        if ($stack !== [] && $stack[count($stack) - 1] === '{') {
            $expectKey[count($expectKey) - 1] = true;
        }
    }

    /** @param list<bool> $expectKey */
    private static function afterColon(array &$expectKey): void
    {
        if ($expectKey !== []) {
            $expectKey[count($expectKey) - 1] = false;
        }
    }

    /**
     * @param list<string> $stack
     * @param list<bool>   $expectKey
     */
    private static function atKey(array $stack, array $expectKey): bool
    {
        return $stack !== []
            && $stack[count($stack) - 1] === '{'
            && ($expectKey[count($expectKey) - 1] ?? false);
    }

    /** Characters that can appear in a number, true, false or null. */
    private static function isBareToken(string $character): bool
    {
        return strpos("-+.0123456789eEtrufalsn", $character) !== false;
    }

    /**
     * Is this bare token a value in its own right, or one still being written?
     *
     * **JSON's number grammar, not PHP's.** `is_numeric()` was here, and the two disagree on
     * exactly one shape a real stream produces: a number cut at its decimal point. PHP reads
     * `0.` and `-12.` as numbers and JSON does not, so the safe end advanced onto a document
     * `json_decode()` then refused — and with no candidate left, the whole object came back as
     * `[]`. `{"path":"/tmp/a.php","temperature":0.` read as nothing with the path already in
     * hand. On screen that is one blank frame in the middle of every tool call carrying a
     * float; if the turn is interrupted at that byte it is worse than a flicker, because the
     * arguments a provider is then sent are `[]` rather than the keys that had arrived.
     *
     * The other places the two disagree — `+12`, `.5`, `012`, whitespace on either side — are
     * not prefixes of any valid JSON number, so no stream arrives at one. Refusing them costs
     * nothing and leaves the earlier keys standing instead of building a repair that cannot
     * parse, which is the same trade the decimal point makes.
     *
     * `\z` and not `$`, because `$` forgives a trailing newline — the trap has its own entry.
     */
    private static function isCompleteLiteral(string $token): bool
    {
        if ($token === 'true' || $token === 'false' || $token === 'null') {
            return true;
        }

        return preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][-+]?\d+)?\z/', $token) === 1;
    }
}
