<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * An image's size, read from the first few bytes of the file.
 *
 * PHP has `getimagesizefromstring()` in ext-gd, and this does not use it: the headers are
 * four short functions, ext-gd is not always built in, and a picture that cannot be sized
 * would have to fall back to a guess anyway. Decoding is never needed — only the header.
 *
 * Every reader takes base64, because that is the form an image arrives in from a model
 * and the form it goes back out to the terminal in.
 */
final class ImageDimensions
{
    public static function of(string $base64, string $mimeType): ?ImageSize
    {
        return match ($mimeType) {
            'image/png' => self::png($base64),
            'image/jpeg' => self::jpeg($base64),
            'image/gif' => self::gif($base64),
            'image/webp' => self::webp($base64),
            default => null,
        };
    }

    /** IHDR is always the first chunk, so width and height are at a fixed offset. */
    public static function png(string $base64): ?ImageSize
    {
        $bytes = self::decode($base64, 24);

        if ($bytes === null || substr($bytes, 0, 4) !== "\x89PNG") {
            return null;
        }

        return new ImageSize(self::uint32be($bytes, 16), self::uint32be($bytes, 20));
    }

    /**
     * Walk the segment chain to the frame header.
     *
     * Only SOF0 to SOF2 — baseline, extended and progressive, which is everything a
     * camera or a screenshot produces. The lossless and arithmetic-coded variants exist
     * and do not turn up here.
     */
    public static function jpeg(string $base64): ?ImageSize
    {
        $bytes = self::decode($base64, 2);

        if ($bytes === null || substr($bytes, 0, 2) !== "\xff\xd8") {
            return null;
        }

        $length = strlen($bytes);
        $offset = 2;

        while ($offset < $length - 9) {
            if ($bytes[$offset] !== "\xff") {
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker >= 0xc0 && $marker <= 0xc2) {
                return new ImageSize(self::uint16be($bytes, $offset + 7), self::uint16be($bytes, $offset + 5));
            }

            $segment = self::uint16be($bytes, $offset + 2);

            if ($segment < 2) {
                return null;
            }

            $offset += 2 + $segment;
        }

        return null;
    }

    public static function gif(string $base64): ?ImageSize
    {
        $bytes = self::decode($base64, 10);
        $signature = $bytes === null ? '' : substr($bytes, 0, 6);

        if ($signature !== 'GIF87a' && $signature !== 'GIF89a') {
            return null;
        }

        return new ImageSize(self::uint16le($bytes, 6), self::uint16le($bytes, 8));
    }

    /**
     * WebP, in its three shapes.
     *
     * Lossy keeps the size in the VP8 frame tag, lossless packs both into fourteen bits
     * each of one little-endian word, and the extended form has a 24-bit pair. All three
     * store size minus one, except the lossy one, which does not.
     */
    public static function webp(string $base64): ?ImageSize
    {
        $bytes = self::decode($base64, 30);

        if ($bytes === null || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        return match (substr($bytes, 12, 4)) {
            'VP8 ' => new ImageSize(self::uint16le($bytes, 26) & 0x3fff, self::uint16le($bytes, 28) & 0x3fff),
            'VP8L' => self::losslessWebp($bytes),
            'VP8X' => new ImageSize(self::uint24le($bytes, 24) + 1, self::uint24le($bytes, 27) + 1),
            default => null,
        };
    }

    private static function losslessWebp(string $bytes): ImageSize
    {
        $bits = self::uint32le($bytes, 21);

        return new ImageSize(($bits & 0x3fff) + 1, (($bits >> 14) & 0x3fff) + 1);
    }

    /** Decoded bytes, or null when there are not even enough for a header. */
    private static function decode(string $base64, int $minimum): ?string
    {
        // Strict: a payload that is not base64 at all should be a miss, not silently
        // decoded into whatever bytes could be salvaged from it.
        $bytes = base64_decode($base64, true);

        if ($bytes === false || strlen($bytes) < $minimum) {
            return null;
        }

        return $bytes;
    }

    private static function uint32be(string $bytes, int $offset): int
    {
        return (int) unpack('N', substr($bytes, $offset, 4))[1];
    }

    private static function uint16be(string $bytes, int $offset): int
    {
        return (int) unpack('n', substr($bytes, $offset, 2))[1];
    }

    private static function uint16le(string $bytes, int $offset): int
    {
        return (int) unpack('v', substr($bytes, $offset, 2))[1];
    }

    private static function uint32le(string $bytes, int $offset): int
    {
        return (int) unpack('V', substr($bytes, $offset, 4))[1];
    }

    private static function uint24le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8) | (ord($bytes[$offset + 2]) << 16);
    }
}
