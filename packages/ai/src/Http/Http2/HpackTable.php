<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

use Pig\Ai\Http\HttpError;

/**
 * HPACK's indexing address space (RFC 7541 §2.3): the 61 static entries of Appendix A, then the
 * dynamic table, newest first, whose size is held under a limit by evicting the oldest.
 */
final class HpackTable
{
    /** Octets an entry costs on top of its name and value (RFC 7541 §4.1). */
    private const int ENTRY_OVERHEAD = 32;

    /** @var list<array{0: string, 1: string}> index 1 is the first */
    private const array STATIC = [
        [':authority', ''], [':method', 'GET'], [':method', 'POST'], [':path', '/'],
        [':path', '/index.html'], [':scheme', 'http'], [':scheme', 'https'], [':status', '200'],
        [':status', '204'], [':status', '206'], [':status', '304'], [':status', '400'],
        [':status', '404'], [':status', '500'], ['accept-charset', ''], ['accept-encoding', 'gzip, deflate'],
        ['accept-language', ''], ['accept-ranges', ''], ['accept', ''], ['access-control-allow-origin', ''],
        ['age', ''], ['allow', ''], ['authorization', ''], ['cache-control', ''],
        ['content-disposition', ''], ['content-encoding', ''], ['content-language', ''], ['content-length', ''],
        ['content-location', ''], ['content-range', ''], ['content-type', ''], ['cookie', ''],
        ['date', ''], ['etag', ''], ['expect', ''], ['expires', ''],
        ['from', ''], ['host', ''], ['if-match', ''], ['if-modified-since', ''],
        ['if-none-match', ''], ['if-range', ''], ['if-unmodified-since', ''], ['last-modified', ''],
        ['link', ''], ['location', ''], ['max-forwards', ''], ['proxy-authenticate', ''],
        ['proxy-authorization', ''], ['range', ''], ['referer', ''], ['refresh', ''],
        ['retry-after', ''], ['server', ''], ['set-cookie', ''], ['strict-transport-security', ''],
        ['transfer-encoding', ''], ['user-agent', ''], ['vary', ''], ['via', ''],
        ['www-authenticate', ''],
    ];

    /** @var list<array{0: string, 1: string}> newest first */
    private array $dynamic = [];

    private int $size = 0;

    public function __construct(private int $maxSize = 4096)
    {
    }

    /** @return array{0: string, 1: string} name and value */
    public function get(int $index): array
    {
        if ($index >= 1 && $index <= count(self::STATIC)) {
            return self::STATIC[$index - 1];
        }

        return $this->dynamic[$index - count(self::STATIC) - 1]
            ?? throw new HttpError("HPACK: index {$index} is not in the table");
    }

    public function add(string $name, string $value): void
    {
        $cost = strlen($name) + strlen($value) + self::ENTRY_OVERHEAD;

        // An entry larger than the whole table empties it and is not stored (§4.4).
        $this->evictTo($this->maxSize - $cost);

        if ($cost > $this->maxSize) {
            return;
        }

        array_unshift($this->dynamic, [$name, $value]);
        $this->size += $cost;
    }

    public function resize(int $maxSize): void
    {
        $this->maxSize = $maxSize;
        $this->evictTo($maxSize);
    }

    /**
     * The index of the entry that is $name and $value, else of one that is only $name, else 0 —
     * the static table first, as it is the cheaper to point at.
     *
     * @return array{0: int, 1: bool} the index, and whether the value matched as well
     */
    public static function findStatic(string $name, string $value): array
    {
        $nameOnly = 0;

        foreach (self::STATIC as $i => [$staticName, $staticValue]) {
            if ($staticName !== $name) {
                continue;
            }

            if ($staticValue === $value) {
                return [$i + 1, true];
            }

            $nameOnly = $nameOnly === 0 ? $i + 1 : $nameOnly;
        }

        return [$nameOnly, false];
    }

    private function evictTo(int $limit): void
    {
        while ($this->size > max($limit, 0) && $this->dynamic !== []) {
            [$name, $value] = array_pop($this->dynamic);
            $this->size -= strlen($name) + strlen($value) + self::ENTRY_OVERHEAD;
        }
    }
}
