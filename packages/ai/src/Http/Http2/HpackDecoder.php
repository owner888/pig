<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

use Pig\Ai\Http\HttpError;

/**
 * Decodes header blocks (RFC 7541 §6). One per connection: the dynamic table lives across blocks,
 * so the blocks must be decoded in the order they were sent, every one of them.
 */
final class HpackDecoder
{
    /** What a header block may add up to, counted as RFC 9113 §6.5.2 does. */
    private const int MAX_LIST_BYTES = 262144;

    private readonly HpackTable $table;

    /** @param int $maxTableSize the SETTINGS_HEADER_TABLE_SIZE this side told the peer */
    public function __construct(private readonly int $maxTableSize = 4096)
    {
        $this->table = new HpackTable($maxTableSize);
    }

    /** @return list<array{0: string, 1: string}> name and value, in the order sent */
    public function decode(string $block): array
    {
        $headers = [];
        $offset = 0;
        $length = strlen($block);
        $listBytes = 0;

        while ($offset < $length) {
            $byte = ord($block[$offset]);

            if (($byte & 0x80) !== 0) {
                $index = self::integer($block, $offset, 7);

                if ($index === 0) {
                    throw new HttpError('HPACK: index 0 is not an entry');
                }

                $header = $this->table->get($index);
            } elseif (($byte & 0xe0) === 0x20) {
                $size = self::integer($block, $offset, 5);

                if ($size > $this->maxTableSize) {
                    throw new HttpError("HPACK: a table size of {$size} is above the {$this->maxTableSize} allowed");
                }

                $this->table->resize($size);

                continue;
            } else {
                $indexed = ($byte & 0x40) !== 0;
                $index = self::integer($block, $offset, $indexed ? 6 : 4);
                $name = $index === 0 ? self::string($block, $offset) : $this->table->get($index)[0];
                $header = [$name, self::string($block, $offset)];

                if ($indexed) {
                    $this->table->add($header[0], $header[1]);
                }
            }

            $listBytes += strlen($header[0]) + strlen($header[1]) + 32;

            if ($listBytes > self::MAX_LIST_BYTES) {
                throw new HttpError('HPACK: the headers are larger than ' . self::MAX_LIST_BYTES . ' bytes');
            }

            $headers[] = $header;
        }

        return $headers;
    }

    /** An integer with an N-bit prefix (§5.1); moves $offset past it. */
    public static function integer(string $data, int &$offset, int $prefix): int
    {
        $mask = (1 << $prefix) - 1;
        $value = ord($data[$offset++] ?? throw new HttpError('HPACK: a header block ends inside an integer')) & $mask;

        if ($value < $mask) {
            return $value;
        }

        for ($shift = 0; $shift <= 28; $shift += 7) {
            $byte = ord($data[$offset++] ?? throw new HttpError('HPACK: a header block ends inside an integer'));
            $value += ($byte & 0x7f) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new HttpError('HPACK: an integer too large to be a length or an index');
    }

    /** A string literal (§5.2); moves $offset past it. */
    private static function string(string $data, int &$offset): string
    {
        $huffman = (ord($data[$offset] ?? throw new HttpError('HPACK: a header block ends before a string')) & 0x80) !== 0;
        $length = self::integer($data, $offset, 7);

        if ($offset + $length > strlen($data)) {
            throw new HttpError('HPACK: a string runs past the end of its header block');
        }

        $raw = substr($data, $offset, $length);
        $offset += $length;

        return $huffman ? Huffman::decode($raw) : $raw;
    }
}
