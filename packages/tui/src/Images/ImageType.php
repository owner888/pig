<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * What kind of image this is, from its first few bytes.
 *
 * By content, never by extension: a file called `.png` that a screenshot tool wrote as
 * JPEG has to go to the model as JPEG, or the provider rejects the whole request. The
 * four types here are the ones every provider accepts.
 */
final class ImageType
{
    /** The media type, or null when the bytes are not one of the four. */
    public static function of(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * The same, for a file, read without loading it.
     *
     * A WebP signature needs twelve bytes; sixteen is a round number with room to spare.
     */
    public static function ofFile(string $path): ?string
    {
        // A file that cannot be opened is not an image as far as this is concerned; the
        // warning is caught rather than suppressed so the rule against `@` holds.
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($path, 'rb');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, 16);
        } finally {
            fclose($handle);
        }

        return $head === false ? null : self::of($head);
    }
}
