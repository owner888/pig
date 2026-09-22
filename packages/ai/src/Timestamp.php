<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Unix milliseconds, the unit every message upstream stamps itself with via Date.now(). */
final class Timestamp
{
    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
