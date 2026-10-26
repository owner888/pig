<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/** @internal An open object or array of `StreamingJson`. */
final class StreamingJsonFrame
{
    public const int KEY = 0;

    public const int COLON = 1;

    public const int VALUE = 2;

    public const int COMMA = 3;

    /** @var array<array-key, mixed> */
    public array $container = [];

    /** Members or elements: what copying the container costs. */
    public int $size = 0;

    /** Objects: the key read last, where the member's value goes. */
    public string|int|null $key = null;

    public int $phase = self::KEY;

    /** @param string|int|bool|null $slot where the container goes in its parent; see `StreamingJson::valueSlot()` */
    public function __construct(public readonly bool $isObject, public readonly string|int|bool|null $slot)
    {
        if (!$isObject) {
            $this->phase = self::VALUE;
        }
    }
}
