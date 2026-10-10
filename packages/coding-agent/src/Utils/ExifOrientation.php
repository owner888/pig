<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

use GdImage;

/**
 * Upstream's `utils/exif-orientation.ts`: the EXIF orientation of a JPEG or a WebP, read off
 * the bytes, and applied to a decoded image before it is resized — or a phone photo taken
 * on its side is resized on its side.
 *
 * The reading is upstream's byte walk, line for line. The applying is GD's `imageflip()` and
 * `imagerotate()` where upstream has Photon's flips and a hand-written rotate; the pixels come
 * out in the same places.
 */
final class ExifOrientation
{
    /** 1 to 8, or 1 when there is none or it cannot be read. */
    public static function of(string $bytes): int
    {
        $tiff = -1;

        if (strlen($bytes) >= 2 && $bytes[0] === "\xff" && $bytes[1] === "\xd8") {
            $tiff = self::jpegTiffOffset($bytes);
        } elseif (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            $tiff = self::webpTiffOffset($bytes);
        }

        return $tiff === -1 ? 1 : self::fromTiff($bytes, $tiff);
    }

    /** Whether the orientation swaps width and height. */
    public static function swapsAxes(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /** The image as it should be seen; the one given when it already is. */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        // `imagerotate()` turns anticlockwise, so upstream's clockwise 90 (orientation 6) is 270.
        $rotate = static fn (GdImage $image, int $degrees): GdImage => imagerotate($image, $degrees, 0) ?: $image;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                imageflip($image, IMG_FLIP_BOTH);

                return $image;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $rotated = $rotate($image, 270);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);

                return $rotated;
            case 6:
                return $rotate($image, 270);
            case 7:
                $rotated = $rotate($image, 90);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);

                return $rotated;
            case 8:
                return $rotate($image, 90);
            default:
                return $image;
        }
    }

    private static function fromTiff(string $bytes, int $start): int
    {
        $length = strlen($bytes);

        if ($start + 8 > $length) {
            return 1;
        }

        $le = substr($bytes, $start, 2) === 'II';
        $read16 = static fn (int $at): int => $le
            ? ord($bytes[$at]) | (ord($bytes[$at + 1]) << 8)
            : (ord($bytes[$at]) << 8) | ord($bytes[$at + 1]);
        $read32 = static fn (int $at): int => $le
            ? ord($bytes[$at]) | (ord($bytes[$at + 1]) << 8) | (ord($bytes[$at + 2]) << 16) | (ord($bytes[$at + 3]) << 24)
            : (ord($bytes[$at]) << 24) | (ord($bytes[$at + 1]) << 16) | (ord($bytes[$at + 2]) << 8) | ord($bytes[$at + 3]);

        $ifd = $start + $read32($start + 4);

        if ($ifd + 2 > $length) {
            return 1;
        }

        $entries = $read16($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > $length) {
                return 1;
            }

            if ($read16($entry) === 0x0112) {
                $value = $read16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    private static function jpegTiffOffset(string $bytes): int
    {
        $length = strlen($bytes);
        $offset = 2;

        while ($offset < $length - 1) {
            if ($bytes[$offset] !== "\xff") {
                return -1;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xff) {
                $offset++;

                continue;
            }

            if ($marker === 0xe1) {
                if ($offset + 4 >= $length) {
                    return -1;
                }

                $segment = $offset + 4;

                if ($segment + 6 > $length) {
                    return -1;
                }

                if (substr($bytes, $segment, 6) === "Exif\0\0") {
                    return $segment + 6;
                }
            }

            if ($offset + 4 > $length) {
                return -1;
            }

            $offset += 2 + ((ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]));
        }

        return -1;
    }

    private static function webpTiffOffset(string $bytes): int
    {
        $length = strlen($bytes);
        $offset = 12;

        while ($offset + 8 <= $length) {
            $id = substr($bytes, $offset, 4);
            $size = ord($bytes[$offset + 4]) | (ord($bytes[$offset + 5]) << 8)
                | (ord($bytes[$offset + 6]) << 16) | (ord($bytes[$offset + 7]) << 24);
            $data = $offset + 8;

            if ($id === 'EXIF') {
                if ($data + $size > $length) {
                    return -1;
                }

                return $size >= 6 && substr($bytes, $data, 6) === "Exif\0\0" ? $data + 6 : $data;
            }

            $offset = $data + $size + ($size % 2);
        }

        return -1;
    }
}
