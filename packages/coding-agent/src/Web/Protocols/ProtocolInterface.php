<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Protocols;

use Pig\CodingAgent\Web\TcpConnection;

/**
 * Standard protocol interface inspired by Workerman.
 *
 * Encapsulates wire-framing (input), serialization (decode),
 * and framing/encoding (encode) cleanly decoupled from network socket transport.
 */
interface ProtocolInterface
{
    /**
     * Check whether the package in the buffer is complete.
     *
     * @return int<0, max>|false Returns package byte length if complete, 0 if incomplete (wait for more), or false on error.
     */
    public static function input(string $buffer, TcpConnection $connection): int|false;

    /**
     * Decode a single complete raw package into application-level data.
     */
    public static function decode(string $buffer, TcpConnection $connection): mixed;

    /**
     * Encode application data into wire format string.
     */
    public static function encode(mixed $data, TcpConnection $connection): string;
}
