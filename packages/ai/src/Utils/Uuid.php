<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\Timestamp;
use RangeException;

/**
 * Upstream's `utils/uuid.ts`: time-ordered UUIDv7s. Within a process each id sorts after the last
 * — a counter seeded at random fills the bits after the millisecond, and the clock is never let
 * run backwards — so two made in the same millisecond still come out in order.
 */
final class Uuid
{
    private const int MAX_TIMESTAMP = 0xFFFFFFFFFFFF;

    private const int MAX_SEQUENCE = (1 << 41) - 1;

    private static int $lastOrdinaryTimestamp = -1;

    private static ?int $sequence = null;

    /** Upstream's `uuidv7(timestampMs?)`. A timestamp given is kept as it is, "for follower ids". */
    public static function v7(?int $timestampMs = null): string
    {
        $requested = $timestampMs ?? Timestamp::nowMs();

        if ($requested < 0 || $requested > self::MAX_TIMESTAMP) {
            throw new RangeException('UUIDv7 timestamp must be an integer between 0 and ' . self::MAX_TIMESTAMP);
        }

        $timestamp = $timestampMs ?? max($requested, self::$lastOrdinaryTimestamp);

        if ($timestampMs === null) {
            self::$lastOrdinaryTimestamp = $timestamp;
        }

        $bytes = array_values(unpack('C16', random_bytes(16)));

        if (self::$sequence === null) {
            self::$sequence = ($bytes[1] << 32) | ($bytes[2] << 24) | ($bytes[3] << 16) | ($bytes[4] << 8) | $bytes[5];
        } else {
            if (self::$sequence === self::MAX_SEQUENCE) {
                throw new RangeException('UUIDv7 generator sequence exhausted');
            }

            self::$sequence++;
        }

        $sequence = self::$sequence;

        for ($index = 5; $index >= 0; $index--) {
            $bytes[$index] = ($timestamp >> ((5 - $index) * 8)) & 0xFF;
        }

        $bytes[6] = 0x70 | (($sequence >> 37) & 0x0F);
        $bytes[7] = ($sequence >> 29) & 0xFF;
        $bytes[8] = 0x80 | (($sequence >> 23) & 0x3F);
        $bytes[9] = ($sequence >> 15) & 0xFF;
        $bytes[10] = ($sequence >> 7) & 0xFF;
        $bytes[11] = (($sequence & 0x7F) << 1) | ($bytes[11] & 0x01);

        $hex = bin2hex(pack('C*', ...$bytes));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
