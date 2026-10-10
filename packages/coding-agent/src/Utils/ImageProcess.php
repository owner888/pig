<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

use GdImage;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Tui\Images\TerminalImage;

/**
 * Upstream's `utils/image-process.ts`, `image-resize-core.ts` and `tool-result-images.ts`: an
 * image on its way into the conversation is put in a format every provider takes and, unless
 * `images.autoResize` is off, fitted inside the provider's limits — 2000 by 2000 pixels and 4.5MB
 * of base64 by default, or the model's `inputLimits.images.resize`. One oversized picture makes a
 * provider refuse the whole conversation, not just the turn it arrived in.
 *
 * The strategy is upstream's to the step: fit the dimensions, try PNG and then JPEG at falling
 * qualities, and shrink by a quarter until something fits or the picture is one pixel. A resized
 * image comes with upstream's note on how to map coordinates back.
 *
 * **GD is the codec, and it is optional.** Upstream bundles Photon (Rust compiled to WASM); PHP's
 * equivalent is `ext-gd`, which most builds have and pig does not require. Without it, an image
 * already inside the limits — measured from its header, which needs no codec — goes through as it
 * is, and one that is not cannot be fitted and is omitted with upstream's sentence. Upstream with
 * no Photon omits every image, in-limits ones too; passing those through is the one deviation.
 */
final class ImageProcess
{
    public const int DEFAULT_MAX_WIDTH = 2000;
    public const int DEFAULT_MAX_HEIGHT = 2000;
    public const int DEFAULT_MAX_BYTES = 4_718_592; // 4.5MB of base64, below Anthropic's 5MB
    public const int DEFAULT_JPEG_QUALITY = 80;

    public const string CONVERT_FAILED = '[Image omitted: could not be converted to a supported inline image format.]';
    public const string RESIZE_FAILED = '[Image omitted: could not be resized below the inline image size limit.]';

    private const array SUPPORTED = [
        'image/png' => 'image/png',
        'image/jpeg' => 'image/jpeg',
        'image/jpg' => 'image/jpeg',
        'image/gif' => 'image/gif',
        'image/webp' => 'image/webp',
    ];

    /** Whether this PHP can decode and encode pictures. A seam for tests. */
    public static ?bool $codec = null;

    /**
     * Upstream's `processImage()`.
     *
     * @param array{maxWidth?: int, maxHeight?: int, maxBytes?: int, jpegQuality?: int}|null $resize
     * @return array{ok: true, data: string, mimeType: string, hints: list<string>}|array{ok: false, message: string}
     */
    public static function process(string $bytes, string $mimeType, bool $autoResize = true, ?array $resize = null): array
    {
        $normalized = self::normalize($bytes, $mimeType);

        if ($normalized === null) {
            return ['ok' => false, 'message' => self::CONVERT_FAILED];
        }

        [$bytes, $mimeType, $convertedFrom] = $normalized;
        $hints = [];

        if ($convertedFrom !== null && $convertedFrom !== $mimeType) {
            $hints[] = "[Image converted from {$convertedFrom} to {$mimeType}.]";
        }

        if (!$autoResize) {
            return ['ok' => true, 'data' => base64_encode($bytes), 'mimeType' => $mimeType, 'hints' => $hints];
        }

        $resized = self::resize($bytes, $mimeType, $resize);

        if ($resized === null) {
            return ['ok' => false, 'message' => self::RESIZE_FAILED];
        }

        if ($resized['wasResized']) {
            $scale = number_format($resized['originalWidth'] / $resized['width'], 2, '.', '');
            $hints[] = "[Image: original {$resized['originalWidth']}x{$resized['originalHeight']}, displayed at "
                . "{$resized['width']}x{$resized['height']}. Multiply coordinates by {$scale} to map to original image.]";
        }

        return ['ok' => true, 'data' => $resized['data'], 'mimeType' => $resized['mimeType'], 'hints' => $hints];
    }

    /**
     * Upstream's `normalizeToolResultImages()`: the images a tool handed back, processed once as
     * they enter the conversation. A picture that cannot be processed is kept as it was — the tool
     * made it, and the failure may only be a missing codec. The list comes back unchanged (the same
     * array) when nothing changed.
     *
     * @param list<TextContent|ImageContent> $content
     * @param array{maxWidth?: int, maxHeight?: int, maxBytes?: int, jpegQuality?: int}|null $resize
     * @return list<TextContent|ImageContent>
     */
    public static function normalizeToolResult(array $content, bool $autoResize = true, ?array $resize = null): array
    {
        $normalized = [];
        $changed = false;

        foreach ($content as $block) {
            if (!$block instanceof ImageContent) {
                $normalized[] = $block;

                continue;
            }

            $decoded = base64_decode($block->data, true);
            $processed = $decoded === false ? null : self::process($decoded, $block->mimeType, $autoResize, $resize);

            if ($processed === null || !$processed['ok']
                || ($processed['data'] === $block->data && $processed['mimeType'] === $block->mimeType && $processed['hints'] === [])) {
                $normalized[] = $block;

                continue;
            }

            $normalized[] = new ImageContent($processed['data'], $processed['mimeType']);

            if ($processed['hints'] !== []) {
                $normalized[] = new TextContent(implode("\n", $processed['hints']));
            }

            $changed = true;
        }

        return $changed ? $normalized : $content;
    }

    /** @return array{0: string, 1: string, 2: ?string}|null the bytes, their type, and what they were converted from */
    private static function normalize(string $bytes, string $mimeType): ?array
    {
        $base = strtolower(trim(explode(';', $mimeType)[0]));

        if (isset(self::SUPPORTED[$base])) {
            return [$bytes, self::SUPPORTED[$base], null];
        }

        $image = self::decode($bytes);

        if ($image === null) {
            return null;
        }

        $png = self::encode($image, 'image/png');

        return $png === null ? null : [$png, 'image/png', $base];
    }

    /**
     * Upstream's `resizeImageInProcess()`.
     *
     * @param array{maxWidth?: int, maxHeight?: int, maxBytes?: int, jpegQuality?: int}|null $options
     * @return array{data: string, mimeType: string, originalWidth: int, originalHeight: int, width: int, height: int, wasResized: bool}|null
     */
    private static function resize(string $bytes, string $mimeType, ?array $options): ?array
    {
        $maxWidth = $options['maxWidth'] ?? self::DEFAULT_MAX_WIDTH;
        $maxHeight = $options['maxHeight'] ?? self::DEFAULT_MAX_HEIGHT;
        $maxBytes = $options['maxBytes'] ?? self::DEFAULT_MAX_BYTES;
        $quality = $options['jpegQuality'] ?? self::DEFAULT_JPEG_QUALITY;
        $base64 = base64_encode($bytes);
        $orientation = ExifOrientation::of($bytes);

        // The size as it will be seen. Upstream decodes and turns it first; the header and the
        // orientation give the same two numbers without a codec.
        $header = TerminalImage::getImageDimensions($base64, $mimeType);
        [$width, $height] = $header === null ? [null, null] : [$header->widthPx, $header->heightPx];

        if ($width !== null && ExifOrientation::swapsAxes($orientation)) {
            [$width, $height] = [$height, $width];
        }

        if ($width !== null && $width <= $maxWidth && $height <= $maxHeight && strlen($base64) < $maxBytes) {
            return [
                'data' => $base64,
                'mimeType' => $mimeType,
                'originalWidth' => $width,
                'originalHeight' => $height,
                'width' => $width,
                'height' => $height,
                'wasResized' => false,
            ];
        }

        $decoded = self::decode($bytes);

        if ($decoded === null) {
            return null;
        }

        $image = ExifOrientation::apply($decoded, $orientation);
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        // Upstream's own check, on what was decoded — reached when the header could not be read.
        if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight && strlen($base64) < $maxBytes) {
            return [
                'data' => $base64,
                'mimeType' => $mimeType,
                'originalWidth' => $originalWidth,
                'originalHeight' => $originalHeight,
                'width' => $originalWidth,
                'height' => $originalHeight,
                'wasResized' => false,
            ];
        }

        $targetWidth = $originalWidth;
        $targetHeight = $originalHeight;

        if ($targetWidth > $maxWidth) {
            $targetHeight = (int) round($targetHeight * $maxWidth / $targetWidth);
            $targetWidth = $maxWidth;
        }

        if ($targetHeight > $maxHeight) {
            $targetWidth = (int) round($targetWidth * $maxHeight / $targetHeight);
            $targetHeight = $maxHeight;
        }

        $qualities = array_values(array_unique([$quality, 85, 70, 55, 40]));

        while (true) {
            $scaled = self::scaled($image, max(1, $targetWidth), max(1, $targetHeight));

            if ($scaled === null) {
                return null;
            }

            $candidates = [['image/png', null], ...array_map(static fn (int $q): array => ['image/jpeg', $q], $qualities)];

            foreach ($candidates as [$type, $q]) {
                $encoded = self::encode($scaled, $type, $q);

                if ($encoded === null) {
                    continue;
                }

                $data = base64_encode($encoded);

                if (strlen($data) < $maxBytes) {
                    return [
                        'data' => $data,
                        'mimeType' => $type,
                        'originalWidth' => $originalWidth,
                        'originalHeight' => $originalHeight,
                        'width' => $targetWidth,
                        'height' => $targetHeight,
                        'wasResized' => true,
                    ];
                }
            }

            if ($targetWidth === 1 && $targetHeight === 1) {
                return null;
            }

            $nextWidth = $targetWidth === 1 ? 1 : max(1, (int) floor($targetWidth * 0.75));
            $nextHeight = $targetHeight === 1 ? 1 : max(1, (int) floor($targetHeight * 0.75));

            if ($nextWidth === $targetWidth && $nextHeight === $targetHeight) {
                return null;
            }

            $targetWidth = $nextWidth;
            $targetHeight = $nextHeight;
        }
    }

    private static function hasCodec(): bool
    {
        return self::$codec ?? (function_exists('imagecreatefromstring') && function_exists('imagepng'));
    }

    private static function decode(string $bytes): ?GdImage
    {
        if (!self::hasCodec()) {
            return null;
        }

        // `imagecreatefromstring()` warns as well as answering false on bytes it cannot read; the
        // false is the answer, and the warning has nowhere useful to go.
        set_error_handler(static fn (): bool => true);

        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        return $image instanceof GdImage ? $image : null;
    }

    private static function scaled(GdImage $image, int $width, int $height): ?GdImage
    {
        $scaled = imagecreatetruecolor($width, $height);

        if ($scaled === false) {
            return null;
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $scaled;
    }

    private static function encode(GdImage $image, string $type, ?int $quality = null): ?string
    {
        ob_start();

        try {
            $ok = $type === 'image/png' ? imagepng($image) : imagejpeg($image, null, $quality ?? self::DEFAULT_JPEG_QUALITY);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $ok && $bytes !== '' ? $bytes : null;
    }
}
