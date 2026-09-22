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

        // A bare token running to the end: keep it if it already reads as a value. A number
        // may still be growing — "12" of "125" — so the preview can be briefly wrong, which
        // is the trade partial-json makes too, and the final parse corrects it.
        if ($tokenStart >= 0 && self::isCompleteLiteral(substr($json, $tokenStart))) {
            $safeEnd = $length;
            $safeStack = $stack;
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

    private static function isCompleteLiteral(string $token): bool
    {
        if ($token === 'true' || $token === 'false' || $token === 'null') {
            return true;
        }

        return is_numeric($token);
    }
}
