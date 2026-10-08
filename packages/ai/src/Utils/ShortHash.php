<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `shortHash()` (`packages/ai/src/utils/hash.ts`), ported to the digit.
 *
 * It names tool calls and message items that are rebuilt for another provider
 * (`fc_<hash>`, `<callId>_<hash>`, `msg_<hash>`), so the same input has to give the same id
 * pi gives — not just some deterministic id. JavaScript walks the string in UTF-16 code units
 * and multiplies with `Math.imul` (32-bit, wrapping); both are reproduced here, because PHP's
 * own `*` on two 32-bit values can leave the 64-bit integer range and turn into a float.
 */
final class ShortHash
{
    public static function of(string $value): string
    {
        $h1 = 0xDEADBEEF;
        $h2 = 0x41C6CE57;

        $units = $value === '' ? [] : unpack('v*', mb_convert_encoding($value, 'UTF-16LE', 'UTF-8'));
        foreach ($units ?: [] as $unit) {
            $h1 = self::imul($h1 ^ $unit, 2654435761);
            $h2 = self::imul($h2 ^ $unit, 1597334677);
        }

        $h1 = self::imul($h1 ^ ($h1 >> 16), 2246822507) ^ self::imul($h2 ^ ($h2 >> 13), 3266489909);
        $h2 = self::imul($h2 ^ ($h2 >> 16), 2246822507) ^ self::imul($h1 ^ ($h1 >> 13), 3266489909);

        return base_convert((string) $h2, 10, 36) . base_convert((string) $h1, 10, 36);
    }

    /** `Math.imul`, kept as an unsigned 32-bit value (what JavaScript's `>>> 0` reads it as). */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $low = ($a & 0xFFFF) * $b;
        $high = (($a >> 16) * $b) & 0xFFFF;

        return ($low + ($high << 16)) & 0xFFFFFFFF;
    }
}
