<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

/**
 * Encodes a header list (RFC 7541 §6) without a dynamic table.
 *
 * A header is an index when the static table has it whole, else a literal that is never added to
 * the peer's table — so nothing here depends on the peer's SETTINGS_HEADER_TABLE_SIZE, and a
 * request's few headers lose nothing worth the state. Credentials are marked never-indexed (§7.1).
 */
final class HpackEncoder
{
    private const array SENSITIVE = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    /** @param list<array{0: string, 1: string}> $headers lowercase names */
    public function encode(array $headers): string
    {
        $block = '';

        foreach ($headers as [$name, $value]) {
            [$index, $whole] = HpackTable::findStatic($name, $value);

            if ($whole) {
                $block .= self::integer($index, 7, 0x80);

                continue;
            }

            $block .= self::integer($index, 4, in_array($name, self::SENSITIVE, true) ? 0x10 : 0x00);
            $block .= $index === 0 ? self::string($name) : '';
            $block .= self::string($value);
        }

        return $block;
    }

    private static function integer(int $value, int $prefix, int $pattern): string
    {
        $mask = (1 << $prefix) - 1;

        if ($value < $mask) {
            return chr($pattern | $value);
        }

        $out = chr($pattern | $mask);
        $value -= $mask;

        while ($value >= 128) {
            $out .= chr(($value & 0x7f) | 0x80);
            $value >>= 7;
        }

        return $out . chr($value);
    }

    private static function string(string $plain): string
    {
        if (Huffman::encodedLength($plain) < strlen($plain)) {
            $encoded = Huffman::encode($plain);

            return self::integer(strlen($encoded), 7, 0x80) . $encoded;
        }

        return self::integer(strlen($plain), 7, 0x00) . $plain;
    }
}
